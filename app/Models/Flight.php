<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'flight_number',
    'airline',
    'departure_airport',
    'arrival_airport',
    'departure_date',
    'departure_time',
    'arrival_time',
    'aircraft',
    'total_seats',
    'available_seats',
    'economy_fare',
    'business_fare',
    'first_class_fare',
    'status',
])]
class Flight extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_SCHEDULED = 'Scheduled';

    public const STATUS_BOARDING = 'Boarding';

    public const STATUS_DEPARTED = 'Departed';

    public const STATUS_CANCELLED = 'Cancelled';

    public const STATUS_COMPLETED = 'Completed';

    public const CABIN_CLASS_ECONOMY = 'Economy';

    public const CABIN_CLASS_BUSINESS = 'Business';

    public const CABIN_CLASS_FIRST = 'First Class';

    public const CABIN_CLASSES = [
        self::CABIN_CLASS_ECONOMY,
        self::CABIN_CLASS_BUSINESS,
        self::CABIN_CLASS_FIRST,
    ];

    public const FARE_COLUMNS = [
        self::CABIN_CLASS_ECONOMY => 'economy_fare',
        self::CABIN_CLASS_BUSINESS => 'business_fare',
        self::CABIN_CLASS_FIRST => 'first_class_fare',
    ];

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_BOARDING,
        self::STATUS_DEPARTED,
        self::STATUS_CANCELLED,
        self::STATUS_COMPLETED,
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'economy_fare' => 'decimal:2',
            'business_fare' => 'decimal:2',
            'first_class_fare' => 'decimal:2',
        ];
    }

    public function scopeAvailableForPassengers(Builder $query): Builder
    {
        return $query
            ->where('available_seats', '>', 0)
            ->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_BOARDING]);
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(Penalty::class);
    }

    public function verificationRecords(): HasMany
    {
        return $this->hasMany(VerificationRecord::class);
    }
}
