<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CheckIn;
use App\Models\Flight;
use App\Models\PenaltyRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private AuditLogService $auditLogService,
        private PassengerNotificationService $passengerNotificationService,
        private PenaltyService $penaltyService,
    ) {}

    public function create(
        User $passenger,
        int $flightId,
        int $numberOfPassengers,
        string $cabinClass = Flight::CABIN_CLASS_ECONOMY,
    ): Booking {
        return DB::transaction(function () use ($passenger, $flightId, $numberOfPassengers, $cabinClass): Booking {
            $lockedPassenger = User::query()
                ->whereKey($passenger->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPassenger->hasConfirmedBookingForFlight($flightId)) {
                throw ValidationException::withMessages([
                    'flight_id' => 'You already have a confirmed booking for this flight.',
                ]);
            }

            if ($lockedPassenger->hasUncheckedInBooking()) {
                throw ValidationException::withMessages([
                    'flight_id' => 'Check in for your current booking before booking another flight.',
                ]);
            }

            if ($numberOfPassengers !== 1) {
                throw ValidationException::withMessages([
                    'number_of_passengers' => 'Only one passenger can be included in each booking.',
                ]);
            }

            $flight = Flight::query()
                ->whereKey($flightId)
                ->lockForUpdate()
                ->firstOrFail();
            $departureAt = $flight->departure_date
                ->copy()
                ->setTimeFromTimeString($flight->departure_time);

            if (
                ! in_array($flight->status, [Flight::STATUS_SCHEDULED, Flight::STATUS_BOARDING], true)
                || now()->greaterThanOrEqualTo($departureAt)
            ) {
                throw ValidationException::withMessages([
                    'flight_id' => 'This flight is not accepting bookings.',
                ]);
            }

            $fareColumn = Flight::FARE_COLUMNS[$cabinClass] ?? null;
            $unitFareCents = $fareColumn === null ? 0 : (int) round((float) $flight->{$fareColumn} * 100);

            if ($unitFareCents <= 0) {
                throw ValidationException::withMessages([
                    'cabin_class' => 'This cabin class is not available for booking on this flight.',
                ]);
            }

            if ($flight->available_seats < $numberOfPassengers) {
                throw ValidationException::withMessages([
                    'number_of_passengers' => 'There are not enough seats available for this flight.',
                ]);
            }

            $flight->decrement('available_seats', $numberOfPassengers);

            $booking = $lockedPassenger->bookings()->create([
                'booking_reference' => 'DBP-'.strtoupper((string) Str::ulid()),
                'flight_id' => $flight->getKey(),
                'booking_status' => Booking::STATUS_CONFIRMED,
                'booking_date' => now(),
                'number_of_passengers' => $numberOfPassengers,
                'cabin_class' => $cabinClass,
                'fare_amount' => number_format($unitFareCents * $numberOfPassengers / 100, 2, '.', ''),
            ]);

            $this->auditLogService->record(
                AuditLog::EVENT_BOOKING_CREATED,
                $passenger,
                $booking,
                'Booking created.',
                [
                    'booking_reference' => $booking->booking_reference,
                    'flight_id' => $flight->getKey(),
                    'number_of_passengers' => $numberOfPassengers,
                    'cabin_class' => $cabinClass,
                    'fare_amount' => $booking->fare_amount,
                ],
            );
            $this->passengerNotificationService->bookingConfirmed($booking);

            return $booking;
        }, attempts: 3);
    }

    public function cancel(Booking $booking, User $actor): Booking
    {
        return DB::transaction(function () use ($booking, $actor): Booking {
            $lockedBooking = Booking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $flight = Flight::withTrashed()
                ->whereKey($lockedBooking->flight_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedBooking->booking_status !== Booking::STATUS_CONFIRMED
                || CheckIn::query()->where('booking_id', $lockedBooking->getKey())->exists()
                || now()->greaterThanOrEqualTo($flight->departure_date->copy()->setTimeFromTimeString($flight->departure_time))
                || in_array($flight->status, [Flight::STATUS_DEPARTED, Flight::STATUS_COMPLETED], true)
            ) {
                throw ValidationException::withMessages([
                    'booking' => 'This booking can no longer be cancelled.',
                ]);
            }

            $rule = PenaltyRule::query()
                ->where('cabin_class', $lockedBooking->cabin_class)
                ->where('enabled', true)
                ->first();

            if ($rule !== null) {
                $this->penaltyService->applyCancellation($lockedBooking, $actor);
            }

            if (in_array($flight->status, [Flight::STATUS_SCHEDULED, Flight::STATUS_BOARDING], true)) {
                $flight->available_seats = min(
                    $flight->total_seats,
                    $flight->available_seats + $lockedBooking->number_of_passengers,
                );
                $flight->save();
            }

            $lockedBooking->update(['booking_status' => Booking::STATUS_CANCELLED]);
            $this->auditLogService->record(
                AuditLog::EVENT_BOOKING_CANCELLED,
                $actor,
                $lockedBooking,
                'Booking cancelled.',
                [
                    'booking_reference' => $lockedBooking->booking_reference,
                    'flight_id' => $flight->getKey(),
                    'status' => [
                        'from' => Booking::STATUS_CONFIRMED,
                        'to' => Booking::STATUS_CANCELLED,
                    ],
                ],
            );

            if ($actor->isAdmin()) {
                $this->auditLogService->record(
                    AuditLog::EVENT_ADMINISTRATOR_CHANGE,
                    $actor,
                    $lockedBooking,
                    'Administrator cancelled a booking.',
                    ['action' => AuditLog::EVENT_BOOKING_CANCELLED],
                );
            }

            $this->passengerNotificationService->bookingCancelled($lockedBooking);

            return $lockedBooking->refresh();
        }, attempts: 3);
    }
}
