<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BoardingPass;
use App\Models\Booking;
use App\Models\Flight;
use App\Models\Penalty;
use App\Models\PenaltyRule;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PenaltyService
{
    public function __construct(private AuditLogService $auditLogService) {}

    public function applyDepartedNoShows(): int
    {
        $processed = 0;

        Booking::query()
            ->where('booking_status', Booking::STATUS_CONFIRMED)
            ->whereDoesntHave('boardingPass', fn (Builder $query) => $query->where('pass_status', BoardingPass::STATUS_USED))
            ->whereHas('flight', function (Builder $query): void {
                $query->whereDate('departure_date', '<=', today())
                    ->whereIn('status', [Flight::STATUS_DEPARTED, Flight::STATUS_COMPLETED]);
            })
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use (&$processed): void {
                foreach ($bookings as $booking) {
                    if ($this->markNoShow($booking->getKey())) {
                        $processed++;
                    }
                }
            });

        return $processed;
    }

    public function applyCancellation(Booking $booking, User $actor): ?Penalty
    {
        return $this->applyForBooking($booking, Penalty::REASON_CANCELLATION, $actor);
    }

    public function pay(Penalty $penalty, User $passenger): Penalty
    {
        return DB::transaction(function () use ($penalty, $passenger): Penalty {
            $lockedPenalty = Penalty::query()
                ->whereKey($penalty->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPenalty->user_id !== $passenger->getKey() || $lockedPenalty->status === Penalty::STATUS_WAIVED) {
                throw ValidationException::withMessages([
                    'penalty' => 'This penalty cannot be paid from this account.',
                ]);
            }

            $dueCents = $this->toCents($lockedPenalty->penalty_amount) - $this->toCents($lockedPenalty->amount_paid);

            if ($dueCents <= 0) {
                return $lockedPenalty;
            }

            $wallet = $this->lockedWallet($passenger->getKey());
            $balanceCents = $this->toCents($wallet->balance);

            if ($balanceCents < $dueCents) {
                throw ValidationException::withMessages([
                    'wallet' => 'Your wallet balance is not enough to pay this penalty.',
                ]);
            }

            $this->moveWalletFunds(
                $wallet,
                $dueCents,
                WalletTransaction::TYPE_DEBIT,
                'Penalty payment '.$lockedPenalty->penalty_reference,
                $passenger->getKey(),
                $lockedPenalty,
            );

            $lockedPenalty->update([
                'amount_paid' => $this->fromCents($this->toCents($lockedPenalty->penalty_amount)),
                'status' => Penalty::STATUS_PAID,
            ]);

            return $lockedPenalty->refresh();
        }, attempts: 3);
    }

    public function creditWallet(
        User $passenger,
        User $admin,
        string $amount,
        string $reason,
        string $externalReference,
    ): WalletTransaction {
        return DB::transaction(function () use ($passenger, $admin, $amount, $reason, $externalReference): WalletTransaction {
            $wallet = $this->lockedWallet($passenger->getKey());
            $transaction = $this->moveWalletFunds(
                $wallet,
                $this->toCents($amount),
                WalletTransaction::TYPE_CREDIT,
                $reason,
                $admin->getKey(),
                externalReference: $externalReference,
            );

            $this->auditLogService->record(
                AuditLog::EVENT_ADMINISTRATOR_CHANGE,
                $admin,
                $transaction,
                'Administrator recorded a passenger wallet credit.',
                [
                    'action' => 'wallet_credit',
                    'passenger_id' => $passenger->getKey(),
                    'amount' => $transaction->amount,
                    'reason' => $reason,
                ],
            );

            return $transaction;
        }, attempts: 3);
    }

    public function adjust(Penalty $penalty, User $admin, string $amount, string $reason): Penalty
    {
        return DB::transaction(function () use ($penalty, $admin, $amount, $reason): Penalty {
            $lockedPenalty = Penalty::query()
                ->whereKey($penalty->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPenalty->status === Penalty::STATUS_WAIVED) {
                throw ValidationException::withMessages([
                    'penalty' => 'A waived penalty cannot be adjusted.',
                ]);
            }

            $newAmountCents = $this->toCents($amount);
            $paidCents = $this->toCents($lockedPenalty->amount_paid);
            $oldAmount = $lockedPenalty->penalty_amount;
            $wallet = $this->lockedWallet($lockedPenalty->user_id);

            if ($newAmountCents < $paidCents) {
                $refundCents = $paidCents - $newAmountCents;
                $this->moveWalletFunds(
                    $wallet,
                    $refundCents,
                    WalletTransaction::TYPE_CREDIT,
                    'Penalty adjustment '.$lockedPenalty->penalty_reference,
                    $admin->getKey(),
                    $lockedPenalty,
                );
                $paidCents = $newAmountCents;
            } elseif ($newAmountCents > $paidCents) {
                $additionalDue = $newAmountCents - $paidCents;
                $availableCents = $this->toCents($wallet->balance);
                $deductedCents = min($additionalDue, $availableCents);

                if ($deductedCents > 0) {
                    $this->moveWalletFunds(
                        $wallet,
                        $deductedCents,
                        WalletTransaction::TYPE_DEBIT,
                        'Penalty adjustment '.$lockedPenalty->penalty_reference,
                        $admin->getKey(),
                        $lockedPenalty,
                    );
                    $paidCents += $deductedCents;
                }
            }

            $lockedPenalty->update([
                'penalty_amount' => $this->fromCents($newAmountCents),
                'amount_paid' => $this->fromCents($paidCents),
                'status' => $paidCents >= $newAmountCents ? Penalty::STATUS_PAID : Penalty::STATUS_APPLIED,
                'adjustment_reason' => $reason,
                'adjusted_by' => $admin->getKey(),
                'adjusted_at' => now(),
            ]);

            $this->auditLogService->record(
                AuditLog::EVENT_ADMINISTRATOR_CHANGE,
                $admin,
                $lockedPenalty,
                'Administrator adjusted a penalty.',
                [
                    'action' => 'penalty_adjusted',
                    'from' => $oldAmount,
                    'to' => $lockedPenalty->penalty_amount,
                    'reason' => $reason,
                ],
            );

            return $lockedPenalty->refresh();
        }, attempts: 3);
    }

    public function waive(Penalty $penalty, User $admin, string $reason): Penalty
    {
        return DB::transaction(function () use ($penalty, $admin, $reason): Penalty {
            $lockedPenalty = Penalty::query()
                ->whereKey($penalty->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPenalty->status === Penalty::STATUS_WAIVED) {
                throw ValidationException::withMessages([
                    'penalty' => 'This penalty has already been waived.',
                ]);
            }

            $paidCents = $this->toCents($lockedPenalty->amount_paid);

            if ($paidCents > 0) {
                $wallet = $this->lockedWallet($lockedPenalty->user_id);
                $this->moveWalletFunds(
                    $wallet,
                    $paidCents,
                    WalletTransaction::TYPE_CREDIT,
                    'Waived penalty '.$lockedPenalty->penalty_reference,
                    $admin->getKey(),
                    $lockedPenalty,
                );
            }

            $lockedPenalty->update([
                'amount_paid' => '0.00',
                'status' => Penalty::STATUS_WAIVED,
                'adjustment_reason' => $reason,
                'adjusted_by' => $admin->getKey(),
                'adjusted_at' => now(),
            ]);

            $this->auditLogService->record(
                AuditLog::EVENT_ADMINISTRATOR_CHANGE,
                $admin,
                $lockedPenalty,
                'Administrator waived a penalty.',
                [
                    'action' => 'penalty_waived',
                    'amount' => $lockedPenalty->penalty_amount,
                    'reason' => $reason,
                ],
            );

            return $lockedPenalty->refresh();
        }, attempts: 3);
    }

    private function markNoShow(int $bookingId): bool
    {
        return DB::transaction(function () use ($bookingId): bool {
            $booking = Booking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();

            if ($booking->booking_status !== Booking::STATUS_CONFIRMED) {
                return false;
            }

            $flight = Flight::withTrashed()
                ->whereKey($booking->flight_id)
                ->lockForUpdate()
                ->firstOrFail();
            $boardingPass = BoardingPass::query()
                ->where('booking_id', $booking->getKey())
                ->lockForUpdate()
                ->first();
            $departureAt = $flight->departure_date
                ->copy()
                ->setTimeFromTimeString($flight->departure_time);

            if (
                $flight->trashed()
                || ! in_array($flight->status, [Flight::STATUS_DEPARTED, Flight::STATUS_COMPLETED], true)
                || now()->lessThan($departureAt)
                || $boardingPass?->pass_status === BoardingPass::STATUS_USED
            ) {
                return false;
            }

            $booking->update(['booking_status' => Booking::STATUS_NO_SHOW]);
            $this->applyForBooking($booking, Penalty::REASON_NO_SHOW);

            return true;
        }, attempts: 3);
    }

    private function applyForBooking(Booking $booking, string $reason, ?User $actor = null): ?Penalty
    {
        return DB::transaction(function () use ($booking, $reason, $actor): ?Penalty {
            $existingPenalty = Penalty::query()
                ->where('booking_id', $booking->getKey())
                ->lockForUpdate()
                ->first();

            if ($existingPenalty !== null) {
                return $existingPenalty;
            }

            $rule = PenaltyRule::query()
                ->where('cabin_class', $booking->cabin_class)
                ->where('enabled', true)
                ->first();

            if ($rule === null) {
                return null;
            }

            $amountCents = $rule->penalty_type === PenaltyRule::TYPE_PERCENTAGE
                ? (int) round($this->toCents($booking->fare_amount) * (float) $rule->penalty_value / 100)
                : $this->toCents($rule->penalty_value) * $booking->number_of_passengers;

            if ($amountCents <= 0) {
                return null;
            }

            $penalty = Penalty::query()->create([
                'penalty_reference' => 'PNL-'.strtoupper((string) Str::ulid()),
                'booking_id' => $booking->getKey(),
                'user_id' => $booking->passenger_id,
                'flight_id' => $booking->flight_id,
                'penalty_rule_id' => $rule->getKey(),
                'penalty_amount' => $this->fromCents($amountCents),
                'amount_paid' => '0.00',
                'penalty_reason' => $reason,
                'status' => Penalty::STATUS_APPLIED,
            ]);

            $wallet = $this->lockedWallet($booking->passenger_id);
            $balanceCents = $this->toCents($wallet->balance);
            $paidCents = min($balanceCents, $amountCents);

            if ($paidCents > 0) {
                $this->moveWalletFunds(
                    $wallet,
                    $paidCents,
                    WalletTransaction::TYPE_DEBIT,
                    'Penalty '.$penalty->penalty_reference,
                    null,
                    $penalty,
                );
                $penalty->update([
                    'amount_paid' => $this->fromCents($paidCents),
                    'status' => $paidCents === $amountCents ? Penalty::STATUS_PAID : Penalty::STATUS_APPLIED,
                ]);
            }

            $penalty->refresh();
            $this->auditLogService->record(
                AuditLog::EVENT_PENALTY_APPLIED,
                $actor,
                $penalty,
                $reason === Penalty::REASON_NO_SHOW
                    ? 'No-show penalty applied automatically.'
                    : 'Cancellation penalty applied immediately.',
                [
                    'booking_id' => $booking->getKey(),
                    'amount' => $penalty->penalty_amount,
                    'status' => $penalty->status,
                    'reason' => $reason,
                ],
            );

            return $penalty;
        }, attempts: 3);
    }

    private function lockedWallet(int $userId): Wallet
    {
        User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

        Wallet::query()->firstOrCreate(
            ['user_id' => $userId],
            ['balance' => '0.00'],
        );

        return Wallet::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function moveWalletFunds(
        Wallet $wallet,
        int $amountCents,
        string $type,
        string $description,
        ?int $actorId,
        ?Penalty $penalty = null,
        ?string $externalReference = null,
    ): WalletTransaction {
        $balanceCents = $this->toCents($wallet->balance);
        $newBalanceCents = $type === WalletTransaction::TYPE_CREDIT
            ? $balanceCents + $amountCents
            : $balanceCents - $amountCents;

        if ($newBalanceCents < 0) {
            throw ValidationException::withMessages([
                'wallet' => 'The wallet does not have enough funds for this transaction.',
            ]);
        }

        $wallet->update(['balance' => $this->fromCents($newBalanceCents)]);

        return $wallet->transactions()->create([
            'user_id' => $wallet->user_id,
            'actor_id' => $actorId,
            'penalty_id' => $penalty?->getKey(),
            'transaction_type' => $type,
            'amount' => $this->fromCents($amountCents),
            'balance_after' => $this->fromCents($newBalanceCents),
            'external_reference' => $externalReference,
            'description' => $description,
        ]);
    }

    private function toCents(string|int|float|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function fromCents(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }
}
