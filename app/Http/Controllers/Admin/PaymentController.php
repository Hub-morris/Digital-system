<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\PenaltyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'user_email' => ['nullable', 'email', 'max:255'],
        ]);

        $transactions = WalletTransaction::query()
            ->with([
                'passenger:id,full_name,email',
                'actor:id,full_name',
                'penalty:id,penalty_reference',
            ])
            ->when($filters['user_email'] ?? null, function ($query, string $email): void {
                $query->whereHas('passenger', fn ($userQuery) => $userQuery->where('email', $email));
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.payments.index', compact('transactions'));
    }

    public function creditWallet(Request $request, PenaltyService $penaltyService): RedirectResponse
    {
        $validated = $request->validate([
            'user_email' => [
                'required',
                'email',
                'max:255',
                Rule::exists('users', 'email')->where(fn ($query) => $query->where('role', 'passenger')),
            ],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999'],
            'external_reference' => ['required', 'string', 'max:80', Rule::unique('wallet_transactions', 'external_reference')],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        $passenger = User::query()->where('email', $validated['user_email'])->firstOrFail();

        $penaltyService->creditWallet(
            $passenger,
            $request->user(),
            $validated['amount'],
            $validated['reason'],
            $validated['external_reference'],
        );

        return redirect()->route('admin.payments.index')->with('status', 'Wallet credit recorded.');
    }
}
