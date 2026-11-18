<?php

namespace App\Http\Controllers;

use App\Http\Requests\FlightSearchRequest;
use App\Models\Flight;
use App\Models\PenaltyRule;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PassengerFlightController extends Controller
{
    public function index(FlightSearchRequest $request): View
    {
        $filters = $request->validated();

        $flights = Flight::availableForPassengers()
            ->when($filters['departure_airport'] ?? null, fn ($query, string $airport) => $query->where('departure_airport', 'like', "%{$airport}%"))
            ->when($filters['arrival_airport'] ?? null, fn ($query, string $airport) => $query->where('arrival_airport', 'like', "%{$airport}%"))
            ->when($filters['departure_date'] ?? null, fn ($query, string $date) => $query->whereDate('departure_date', $date))
            ->orderBy('departure_date')
            ->orderBy('departure_time')
            ->paginate(12)
            ->withQueryString();

        return view('flights.passenger.index', compact('flights', 'filters'));
    }

    public function show(Request $request, Flight $flight): View
    {
        abort_unless(
            Flight::availableForPassengers()->whereKey($flight->getKey())->exists(),
            404,
        );

        $penaltyRules = PenaltyRule::query()
            ->whereIn('cabin_class', Flight::CABIN_CLASSES)
            ->get()
            ->keyBy('cabin_class');
        $passenger = $request->user();
        $hasConfirmedBookingForFlight = $passenger->hasConfirmedBookingForFlight($flight->getKey());
        $hasUncheckedInBooking = $passenger->hasUncheckedInBooking();

        return view('flights.passenger.show', compact(
            'flight',
            'penaltyRules',
            'hasConfirmedBookingForFlight',
            'hasUncheckedInBooking',
        ));
    }
}
