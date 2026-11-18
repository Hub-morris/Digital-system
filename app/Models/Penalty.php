<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'penalty_reference',
    'booking_id',
    'user_id',
    'flight_id',
    'penalty_rule_id',
    'penalty_amount',
    'amount_paid',
    'penalty_reason',
    'status',
    'adjustment_reason',
    'adjusted_by',
    'adjusted_at',
])]
class Penalty extends Model
{
    public const STATUS_APPLIED = 'Applied';

    public const STATUS_WAIVED = 'Waived';

    public const STATUS_PAID = 'Paid';

    public const STATUSES = [
        self::STATUS_APPLIED,
        self::STATUS_WAIVED,
        self::STATUS_PAID,
    ];

    public const REASON_NO_SHOW = 'No-Show';

    public const REASON_CANCELLATION = 'Cancellation';

    protected function casts(): array
    {
        return [
            'penalty_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'adjusted_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class)->withTrashed();
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PenaltyRule::class, 'penalty_rule_id');
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
