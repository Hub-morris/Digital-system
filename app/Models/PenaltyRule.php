<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'cabin_class',
    'enabled',
    'penalty_type',
    'penalty_value',
    'cancellation_deadline_hours',
])]
class PenaltyRule extends Model
{
    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'penalty_value' => 'decimal:2',
            'cancellation_deadline_hours' => 'integer',
        ];
    }
}
