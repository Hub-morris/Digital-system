<?php

namespace App\Http\Controllers;

use App\Models\Penalty;
use App\Services\PenaltyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PenaltyController extends Controller
{
    public function index(Request $request): View
    {
        $passenger = $request->user();
        $wallet = $passenger->wallet()->first();
        $penalties = $passenger->penalties()
            ->with(['booking:id,booking_reference', 'flight:id,flight_number,departure_date'])
            ->latest('created_at')
            ->latest('id')
            ->paginate(15);
        $outstandingAmount = $passenger->penalties()
            ->where('status', Penalty::STATUS_APPLIED)
            ->selectRaw('COALESCE(SUM(penalty_amount - amount_paid), 0) as amount')
            ->value('amount');

        return view('penalties.index', [
            'penalties' => $penalties,
            'walletBalance' => $wallet?->balance ?? '0.00',
            'outstandingAmount' => $outstandingAmount ?? '0.00',
        ]);
    }

    public function show(Request $request, Penalty $penalty): View
    {
        $walletBalance = $request->user()->wallet()->value('balance') ?? '0.00';
        $penalty = $request->user()->penalties()
            ->with([
                'booking:id,booking_reference,cabin_class,fare_amount,number_of_passengers',
                'flight:id,flight_number,departure_airport,arrival_airport,departure_date,departure_time',
                'walletTransactions' => fn ($query) => $query->latest('created_at'),
            ])
            ->findOrFail($penalty->getKey());

        return view('penalties.show', compact('penalty', 'walletBalance'));
    }

    public function pay(
        Request $request,
        Penalty $penalty,
        PenaltyService $penaltyService,
    ): RedirectResponse {
        $penalty = $request->user()->penalties()->findOrFail($penalty->getKey());
        $penaltyService->pay($penalty, $request->user());

        return redirect()->route('penalties.show', $penalty)->with('status', 'Penalty paid from your wallet.');
    }
}
