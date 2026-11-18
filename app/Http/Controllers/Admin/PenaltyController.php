<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Flight;
use App\Models\Penalty;
use App\Models\PenaltyRule;
use App\Services\AuditLogService;
use App\Services\PenaltyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PenaltyController extends Controller
{
    private const RULE_FORM_KEYS = [
        'economy' => Flight::CABIN_CLASS_ECONOMY,
        'business' => Flight::CABIN_CLASS_BUSINESS,
        'first_class' => Flight::CABIN_CLASS_FIRST,
    ];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'flight_number' => ['nullable', 'string', 'max:20'],
            'user_email' => ['nullable', 'email', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', Rule::in(Penalty::STATUSES)],
        ]);

        $penalties = Penalty::query()
            ->with([
                'passenger:id,full_name,email',
                'flight:id,flight_number,departure_date',
                'booking:id,booking_reference',
            ])
            ->when($filters['flight_number'] ?? null, function ($query, string $flightNumber): void {
                $query->whereHas('flight', fn ($flightQuery) => $flightQuery->where('flight_number', $flightNumber));
            })
            ->when($filters['user_email'] ?? null, function ($query, string $email): void {
                $query->whereHas('passenger', fn ($userQuery) => $userQuery->where('email', $email));
            })
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.penalties.index', [
            'penalties' => $penalties,
            'statuses' => Penalty::STATUSES,
        ]);
    }

    public function show(Penalty $penalty): View
    {
        $penalty->load([
            'booking:id,booking_reference,cabin_class,fare_amount,number_of_passengers',
            'passenger:id,full_name,email',
            'flight:id,flight_number,departure_airport,arrival_airport,departure_date,departure_time',
            'walletTransactions' => fn ($query) => $query->latest('created_at'),
            'adjustedBy:id,full_name',
        ]);

        return view('admin.penalties.show', compact('penalty'));
    }

    public function editRules(): View
    {
        $rules = PenaltyRule::query()->get()->keyBy('cabin_class');

        return view('admin.penalties.rules', [
            'rules' => $rules,
            'formKeys' => self::RULE_FORM_KEYS,
        ]);
    }

    public function updateRules(Request $request, AuditLogService $auditLogService): RedirectResponse
    {
        $validationRules = [
            'rules' => ['required', 'array:economy,business,first_class'],
        ];

        foreach (array_keys(self::RULE_FORM_KEYS) as $key) {
            $type = $request->input("rules.{$key}.penalty_type");
            $maximum = $type === PenaltyRule::TYPE_PERCENTAGE ? 100 : 9999999999;
            $validationRules["rules.{$key}.enabled"] = ['required', 'boolean'];
            $validationRules["rules.{$key}.penalty_type"] = ['required', Rule::in([
                PenaltyRule::TYPE_PERCENTAGE,
                PenaltyRule::TYPE_FIXED,
            ])];
            $validationRules["rules.{$key}.penalty_value"] = [
                'required',
                'numeric',
                'decimal:0,2',
                'min:0.01',
                'max:'.$maximum,
            ];
        }

        $validated = $request->validate($validationRules);

        DB::transaction(function () use ($validated, $request, $auditLogService): void {
            foreach (self::RULE_FORM_KEYS as $key => $cabinClass) {
                $values = $validated['rules'][$key];
                $rule = PenaltyRule::query()->where('cabin_class', $cabinClass)->firstOrFail();
                $before = $rule->only([
                    'enabled',
                    'penalty_type',
                    'penalty_value',
                ]);
                $rule->update([
                    'enabled' => $values['enabled'],
                    'penalty_type' => $values['penalty_type'],
                    'penalty_value' => $values['penalty_value'],
                ]);
                $auditLogService->record(
                    AuditLog::EVENT_ADMINISTRATOR_CHANGE,
                    $request->user(),
                    $rule,
                    'Administrator updated a no-show penalty rule.',
                    [
                        'action' => 'penalty_rule_updated',
                        'before' => $before,
                        'after' => $rule->only(array_keys($before)),
                    ],
                );
            }
        });

        return redirect()->route('admin.penalty-rules.edit')->with('status', 'Penalty rules updated.');
    }

    public function adjust(
        Request $request,
        Penalty $penalty,
        PenaltyService $penaltyService,
    ): RedirectResponse {
        $validated = $request->validate([
            'penalty_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $penaltyService->adjust($penalty, $request->user(), $validated['penalty_amount'], $validated['reason']);

        return back()->with('status', 'Penalty adjusted.');
    }

    public function waive(
        Request $request,
        Penalty $penalty,
        PenaltyService $penaltyService,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $penaltyService->waive($penalty, $request->user(), $validated['reason']);

        return back()->with('status', 'Penalty waived.');
    }

    public function report(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'period' => ['required', Rule::in(['daily', 'monthly'])],
            'date' => ['nullable', 'date'],
        ]);
        $date = Carbon::parse($validated['date'] ?? today());
        $query = Penalty::query();

        if ($validated['period'] === 'daily') {
            $query->whereDate('created_at', $date->toDateString());
            $periodLabel = $date->toDateString();
        } else {
            $query->whereYear('created_at', $date->year)->whereMonth('created_at', $date->month);
            $periodLabel = $date->format('Y-m');
        }

        $summary = $query->selectRaw(
            'COUNT(*) as penalty_count, COALESCE(SUM(penalty_amount), 0) as total_amount, COALESCE(SUM(amount_paid), 0) as collected_amount, COALESCE(SUM(CASE WHEN status = ? THEN penalty_amount - amount_paid ELSE 0 END), 0) as outstanding_amount',
            [Penalty::STATUS_APPLIED],
        )->firstOrFail();
        $filename = $validated['period'].'-penalties-'.$periodLabel.'.csv';

        return response()->streamDownload(function () use ($summary, $periodLabel): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                throw new \RuntimeException('Unable to open the penalty report output stream.');
            }

            fputcsv($output, ['Period', 'Penalty count', 'Penalty total', 'Collected', 'Outstanding']);
            fputcsv($output, [
                $periodLabel,
                $summary->penalty_count,
                number_format((float) $summary->total_amount, 2, '.', ''),
                number_format((float) $summary->collected_amount, 2, '.', ''),
                number_format((float) $summary->outstanding_amount, 2, '.', ''),
            ]);
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
