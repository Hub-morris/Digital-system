@extends('layouts.app')

@section('title', $flight->flight_number . ' | ' . config('app.name'))

@section('content')
    <section class="container page-wrap py-5">
        <p class="eyebrow mb-2"><a href="{{ route('flights.index') }}">Search flights</a> / Flight details</p>
        <h1 class="h2 mb-1">{{ $flight->flight_number }}</h1>
        <p class="text-muted-custom mb-4">{{ $flight->airline }} · {{ $flight->status }}</p>
        <div class="content-panel p-4 p-md-5">
            <dl class="row mb-0">
                <dt class="col-sm-4">Route</dt><dd class="col-sm-8">{{ $flight->departure_airport }} &rarr; {{ $flight->arrival_airport }}</dd>
                <dt class="col-sm-4">Departure</dt><dd class="col-sm-8">{{ $flight->departure_date->format('M j, Y') }} at {{ substr($flight->departure_time, 0, 5) }}</dd>
                <dt class="col-sm-4">Arrival time</dt><dd class="col-sm-8">{{ substr($flight->arrival_time, 0, 5) }}</dd>
                <dt class="col-sm-4">Aircraft</dt><dd class="col-sm-8">{{ $flight->aircraft ?: 'Not specified' }}</dd>
                <dt class="col-sm-4">Available seats</dt><dd class="col-sm-8">{{ $flight->available_seats }}</dd>
            </dl>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger mt-4" role="alert">{{ $errors->first() }}</div>
        @endif

        @php
            $availableCabinClasses = collect(\App\Models\Flight::CABIN_CLASSES)
                ->filter(fn ($cabinClass) => (float) $flight->{\App\Models\Flight::FARE_COLUMNS[$cabinClass]} > 0);
            $requestedCabinClass = old('cabin_class');
            $selectedCabinClass = $availableCabinClasses->contains($requestedCabinClass)
                ? $requestedCabinClass
                : ($availableCabinClasses->first() ?? \App\Models\Flight::CABIN_CLASS_ECONOMY);
        @endphp

        @if ($hasConfirmedBookingForFlight)
            <div class="alert alert-info mt-4" role="status">
                You already have a confirmed booking for this flight. You cannot book the same flight again.
                <a href="{{ route('bookings.index') }}">View your bookings</a>
            </div>
        @elseif ($hasUncheckedInBooking)
            <div class="alert alert-info mt-4" role="status">
                You already have a confirmed booking. Check in for that flight before booking another one.
                <a href="{{ route('bookings.index') }}">View your bookings</a>
            </div>
        @else
            <form class="content-panel p-4 mt-4" method="POST" action="{{ route('bookings.store') }}">
                @csrf
                <input type="hidden" name="flight_id" value="{{ $flight->id }}">
                <div class="row align-items-end g-3">
                    <fieldset class="col-12">
                        <legend class="form-label">Cabin class and fare</legend>
                        @if ($availableCabinClasses->isEmpty())
                            <div class="alert alert-warning" role="status">
                                No cabin classes are available because fares have not been configured for this flight. Please contact an administrator.
                            </div>
                        @endif
                        <div class="row g-3">
                            @foreach (\App\Models\Flight::CABIN_CLASSES as $cabinClass)
                                @php
                                    $fareColumn = \App\Models\Flight::FARE_COLUMNS[$cabinClass];
                                    $fare = (float) $flight->{$fareColumn};
                                    $rule = $penaltyRules->get($cabinClass);
                                    $radioId = 'cabin-class-'.\Illuminate\Support\Str::slug($cabinClass);
                                @endphp
                                <div class="col-md-4">
                                    <div class="content-panel h-100 p-3">
                                        <div class="form-check">
                                            <input
                                                class="form-check-input"
                                                id="{{ $radioId }}"
                                                name="cabin_class"
                                                type="radio"
                                                value="{{ $cabinClass }}"
                                                aria-describedby="penalty-policy-{{ \Illuminate\Support\Str::slug($cabinClass) }}"
                                                @checked($selectedCabinClass === $cabinClass)
                                                @disabled($fare <= 0)
                                                required
                                            >
                                            <label class="form-check-label fw-semibold" for="{{ $radioId }}">{{ $cabinClass }}</label>
                                        </div>
                                        @if ($fare > 0)
                                            <p class="mb-0 mt-2">Fare per passenger: {{ number_format($fare, 2) }}</p>
                                        @else
                                            <p class="mb-0 mt-2 text-muted-custom">Not available: fare has not been configured.</p>
                                        @endif
                                        @if ($rule?->enabled)
                                            <p class="small text-muted-custom mt-2 mb-0" id="penalty-policy-{{ \Illuminate\Support\Str::slug($cabinClass) }}">
                                                If you cancel this booking, a
                                                {{ $rule->penalty_type === \App\Models\PenaltyRule::TYPE_PERCENTAGE
                                                    ? number_format((float) $rule->penalty_value, 2).'% of the fare'
                                                    : number_format((float) $rule->penalty_value, 2).' per passenger' }}
                                                penalty is applied immediately. A penalty also applies if you do not board.
                                            </p>
                                        @else
                                            <p class="small text-muted-custom mt-2 mb-0" id="penalty-policy-{{ \Illuminate\Support\Str::slug($cabinClass) }}">No penalty is currently enabled for this cabin class.</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @error('cabin_class') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
                        <p class="small text-muted-custom mt-3 mb-0">No-show bookings are checked automatically after scheduled departure. Any amount not covered by your wallet remains due.</p>
                    </fieldset>
                    <div class="col-sm-4">
                        <label class="form-label" for="number_of_passengers">Passengers (one per booking)</label>
                        <input class="form-control @error('number_of_passengers') is-invalid @enderror" id="number_of_passengers" name="number_of_passengers" type="number" min="1" max="1" value="{{ old('number_of_passengers', 1) }}" required>
                        @error('number_of_passengers') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-sm-auto"><button class="btn btn-primary" type="submit" @disabled($flight->available_seats < 1 || $availableCabinClasses->isEmpty())>Book this flight</button></div>
                </div>
                @error('flight_id') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
            </form>
        @endif
    </section>
@endsection