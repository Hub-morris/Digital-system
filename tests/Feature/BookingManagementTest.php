<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CheckIn;
use App\Models\Flight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('security')]
class BookingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_book_flight_and_seats_are_decremented(): void
    {
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = $this->upcomingFlight(['available_seats' => 5]);

        $this->actingAs($passenger)
            ->post(route('bookings.store'), [
                'flight_id' => $flight->id,
                'number_of_passengers' => 1,
            ])
            ->assertRedirect();

        $booking = Booking::query()->where('passenger_id', $passenger->id)->firstOrFail();

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->booking_status);
        $this->assertSame(1, $booking->number_of_passengers);
        $this->assertMatchesRegularExpression('/^DBP-[0-9A-HJKMNP-TV-Z]{26}$/', $booking->booking_reference);
        $this->assertDatabaseHas('flights', [
            'id' => $flight->id,
            'available_seats' => 4,
        ]);

        $this->get(route('bookings.confirmation', $booking))
            ->assertOk()
            ->assertSee($booking->booking_reference);
    }

    public function test_passenger_cannot_book_more_seats_than_available(): void
    {
        $flight = $this->upcomingFlight(['available_seats' => 0]);

        $this->actingAs(User::factory()->create(['role' => 'passenger']))
            ->post(route('bookings.store'), [
                'flight_id' => $flight->id,
                'number_of_passengers' => 1,
            ])
            ->assertSessionHasErrors('number_of_passengers');

        $this->assertDatabaseHas('flights', [
            'id' => $flight->id,
            'available_seats' => 0,
        ]);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_passenger_booking_request_is_limited_to_one_passenger(): void
    {
        $flight = $this->upcomingFlight(['available_seats' => 10]);

        $this->actingAs(User::factory()->create(['role' => 'passenger']))
            ->post(route('bookings.store'), [
                'flight_id' => $flight->id,
                'number_of_passengers' => 2,
            ])
            ->assertSessionHasErrors('number_of_passengers');

        $this->assertDatabaseHas('flights', [
            'id' => $flight->id,
            'available_seats' => 10,
        ]);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_passenger_cannot_book_another_flight_until_checking_in(): void
    {
        $passenger = User::factory()->create(['role' => 'passenger']);
        $currentFlight = $this->upcomingFlight();
        $nextFlight = $this->upcomingFlight();
        $currentBooking = $this->createBooking($passenger, $currentFlight);

        $this->actingAs($passenger)
            ->get(route('flights.show', $nextFlight))
            ->assertOk()
            ->assertSee('Check in for that flight before booking another one.')
            ->assertDontSee('Book this flight');

        $this->post(route('bookings.store'), [
            'flight_id' => $nextFlight->getKey(),
            'number_of_passengers' => 1,
        ])->assertSessionHasErrors([
            'flight_id' => 'Check in for your current booking before booking another flight.',
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $currentBooking->getKey(),
            'booking_status' => Booking::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('flights', [
            'id' => $nextFlight->getKey(),
            'available_seats' => 8,
        ]);
    }

    public function test_passenger_can_book_another_flight_after_checking_in(): void
    {
        $passenger = User::factory()->create(['role' => 'passenger']);
        $currentFlight = $this->upcomingFlight();
        $nextFlight = $this->upcomingFlight();
        $currentBooking = $this->createBooking($passenger, $currentFlight);
        CheckIn::query()->create([
            'booking_id' => $currentBooking->getKey(),
            'passenger_id' => $passenger->getKey(),
            'flight_id' => $currentFlight->getKey(),
            'check_in_reference' => 'CI-'.strtoupper((string) Str::ulid()),
            'checked_in_at' => now(),
            'seat_number' => '001',
            'status' => CheckIn::STATUS_CHECKED_IN,
        ]);

        $this->actingAs($passenger)
            ->post(route('bookings.store'), [
                'flight_id' => $nextFlight->getKey(),
                'number_of_passengers' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('bookings', 2);
        $this->assertDatabaseHas('bookings', [
            'passenger_id' => $passenger->getKey(),
            'flight_id' => $nextFlight->getKey(),
            'booking_status' => Booking::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('flights', [
            'id' => $nextFlight->getKey(),
            'available_seats' => 7,
        ]);
    }

    public function test_passenger_cannot_book_the_same_flight_again_after_checking_in(): void
    {
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = $this->upcomingFlight();
        $booking = $this->createBooking($passenger, $flight);
        CheckIn::query()->create([
            'booking_id' => $booking->getKey(),
            'passenger_id' => $passenger->getKey(),
            'flight_id' => $flight->getKey(),
            'check_in_reference' => 'CI-'.strtoupper((string) Str::ulid()),
            'checked_in_at' => now(),
            'seat_number' => '001',
            'status' => CheckIn::STATUS_CHECKED_IN,
        ]);

        $this->actingAs($passenger)
            ->get(route('flights.show', $flight))
            ->assertOk()
            ->assertSee('You cannot book the same flight again.')
            ->assertDontSee('Book this flight');

        $this->post(route('bookings.store'), [
            'flight_id' => $flight->getKey(),
            'number_of_passengers' => 1,
        ])->assertSessionHasErrors([
            'flight_id' => 'You already have a confirmed booking for this flight.',
        ]);

        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('flights', [
            'id' => $flight->getKey(),
            'available_seats' => 8,
        ]);
    }

    public function test_passenger_can_rebook_a_flight_after_cancelling_the_existing_booking(): void
    {
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = $this->upcomingFlight();
        $booking = $this->createBooking($passenger, $flight);
        $booking->update(['booking_status' => Booking::STATUS_CANCELLED]);

        $this->actingAs($passenger)
            ->post(route('bookings.store'), [
                'flight_id' => $flight->getKey(),
                'number_of_passengers' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('bookings', 2);
        $this->assertDatabaseHas('bookings', [
            'passenger_id' => $passenger->getKey(),
            'flight_id' => $flight->getKey(),
            'booking_status' => Booking::STATUS_CONFIRMED,
        ]);
    }

    public function test_passenger_cannot_book_a_flight_that_has_departed_by_date(): void
    {
        $flight = $this->upcomingFlight(['departure_date' => now()->subDay()->toDateString()]);

        $this->actingAs(User::factory()->create(['role' => 'passenger']))
            ->post(route('bookings.store'), [
                'flight_id' => $flight->id,
                'number_of_passengers' => 1,
            ])
            ->assertSessionHasErrors('flight_id');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_passenger_cannot_book_departed_cancelled_or_completed_flights(): void
    {
        foreach ([Flight::STATUS_DEPARTED, Flight::STATUS_CANCELLED, Flight::STATUS_COMPLETED] as $status) {
            $flight = $this->upcomingFlight(['status' => $status]);

            $this->actingAs(User::factory()->create(['role' => 'passenger']))
                ->post(route('bookings.store'), [
                    'flight_id' => $flight->id,
                    'number_of_passengers' => 1,
                ])
                ->assertSessionHasErrors('flight_id');

            $this->assertDatabaseHas('flights', [
                'id' => $flight->id,
                'available_seats' => 8,
            ]);
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_passenger_cannot_view_another_passengers_booking(): void
    {
        $owner = User::factory()->create(['role' => 'passenger']);
        $otherPassenger = User::factory()->create(['role' => 'passenger']);
        $booking = $owner->bookings()->create([
            'booking_reference' => 'DBP-OWNER-001',
            'flight_id' => $this->upcomingFlight()->id,
            'booking_status' => Booking::STATUS_CONFIRMED,
            'booking_date' => now(),
            'number_of_passengers' => 1,
        ]);

        $this->actingAs($otherPassenger)
            ->get(route('bookings.show', $booking))
            ->assertNotFound();

        $this->get(route('bookings.confirmation', $booking))->assertNotFound();
    }

    public function test_passenger_booking_list_contains_only_their_bookings(): void
    {
        $passenger = User::factory()->create(['role' => 'passenger']);
        $otherPassenger = User::factory()->create(['role' => 'passenger']);
        $passengerBooking = $passenger->bookings()->create([
            'booking_reference' => 'DBP-MINE-001',
            'flight_id' => $this->upcomingFlight()->id,
            'booking_status' => Booking::STATUS_CONFIRMED,
            'booking_date' => now(),
            'number_of_passengers' => 1,
        ]);
        $otherPassenger->bookings()->create([
            'booking_reference' => 'DBP-THEIRS-001',
            'flight_id' => $this->upcomingFlight()->id,
            'booking_status' => Booking::STATUS_CONFIRMED,
            'booking_date' => now(),
            'number_of_passengers' => 1,
        ]);

        $this->actingAs($passenger)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee($passengerBooking->booking_reference)
            ->assertDontSee('DBP-THEIRS-001');
    }

    public function test_cancelling_booking_restores_seats_only_once(): void
    {
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = $this->upcomingFlight(['available_seats' => 8]);
        $booking = $passenger->bookings()->create([
            'booking_reference' => 'DBP-CANCEL-001',
            'flight_id' => $flight->id,
            'booking_status' => Booking::STATUS_CONFIRMED,
            'booking_date' => now(),
            'number_of_passengers' => 2,
        ]);

        $this->actingAs($passenger)
            ->patch(route('bookings.cancel', $booking))
            ->assertRedirect(route('bookings.show', $booking));

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => Booking::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('flights', [
            'id' => $flight->id,
            'available_seats' => 10,
        ]);

        $this->patch(route('bookings.cancel', $booking))->assertSessionHasErrors('booking');
        $this->assertDatabaseHas('flights', [
            'id' => $flight->id,
            'available_seats' => 10,
        ]);
    }

    public function test_passenger_cannot_cancel_a_booking_after_departure(): void
    {
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = $this->upcomingFlight(['status' => Flight::STATUS_DEPARTED, 'available_seats' => 8]);
        $booking = $passenger->bookings()->create([
            'booking_reference' => 'DBP-DEPARTED-001',
            'flight_id' => $flight->id,
            'booking_status' => Booking::STATUS_CONFIRMED,
            'booking_date' => now(),
            'number_of_passengers' => 2,
        ]);

        $this->actingAs($passenger)
            ->patch(route('bookings.cancel', $booking))
            ->assertSessionHasErrors('booking');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => Booking::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('flights', [
            'id' => $flight->id,
            'available_seats' => 8,
        ]);
    }

    public function test_passenger_cannot_cancel_another_passengers_booking(): void
    {
        $owner = User::factory()->create(['role' => 'passenger']);
        $otherPassenger = User::factory()->create(['role' => 'passenger']);
        $flight = $this->upcomingFlight(['available_seats' => 8]);
        $booking = $owner->bookings()->create([
            'booking_reference' => 'DBP-PRIVATE-001',
            'flight_id' => $flight->id,
            'booking_status' => Booking::STATUS_CONFIRMED,
            'booking_date' => now(),
            'number_of_passengers' => 2,
        ]);

        $this->actingAs($otherPassenger)
            ->patch(route('bookings.cancel', $booking))
            ->assertNotFound();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => Booking::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('flights', [
            'id' => $flight->id,
            'available_seats' => 8,
        ]);
    }

    public function test_cancelling_booking_for_cancelled_flight_does_not_restore_seats(): void
    {
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = $this->upcomingFlight(['status' => Flight::STATUS_CANCELLED, 'available_seats' => 8]);
        $booking = $passenger->bookings()->create([
            'booking_reference' => 'DBP-FLIGHT-CANCELLED',
            'flight_id' => $flight->id,
            'booking_status' => Booking::STATUS_CONFIRMED,
            'booking_date' => now(),
            'number_of_passengers' => 2,
        ]);

        $this->actingAs($passenger)
            ->patch(route('bookings.cancel', $booking))
            ->assertRedirect(route('bookings.show', $booking));

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => Booking::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('flights', [
            'id' => $flight->id,
            'available_seats' => 8,
        ]);
    }

    public function test_admin_can_review_search_and_cancel_bookings(): void
    {
        $passenger = User::factory()->create(['role' => 'passenger']);
        $admin = User::factory()->create(['role' => 'admin']);
        $booking = $passenger->bookings()->create([
            'booking_reference' => 'DBP-ADMIN-001',
            'flight_id' => $this->upcomingFlight()->id,
            'booking_status' => Booking::STATUS_CONFIRMED,
            'booking_date' => now(),
            'number_of_passengers' => 1,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.bookings.index', ['q' => 'DBP-ADMIN', 'booking_status' => Booking::STATUS_CONFIRMED]))
            ->assertOk()
            ->assertSee($booking->booking_reference)
            ->assertSee($passenger->email);

        $this->get(route('admin.bookings.show', $booking))
            ->assertOk()
            ->assertSee($booking->booking_reference);

        $this->patch(route('admin.bookings.cancel', $booking))
            ->assertRedirect(route('admin.bookings.show', $booking));

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'booking_status' => Booking::STATUS_CANCELLED,
        ]);
    }

    public function test_admin_cannot_create_a_passenger_booking(): void
    {
        $flight = $this->upcomingFlight();

        foreach (['admin', 'staff'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post(route('bookings.store'), [
                    'flight_id' => $flight->id,
                    'number_of_passengers' => 1,
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_guest_cannot_access_booking_pages_or_create_booking(): void
    {
        $flight = $this->upcomingFlight();

        $this->get(route('bookings.index'))->assertRedirect(route('login'));
        $this->post(route('bookings.store'), [
            'flight_id' => $flight->id,
            'number_of_passengers' => 1,
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('bookings', 0);
    }

    private function upcomingFlight(array $attributes = []): Flight
    {
        return Flight::factory()->create([
            'total_seats' => 10,
            'available_seats' => 8,
            'status' => Flight::STATUS_SCHEDULED,
            ...$attributes,
        ]);
    }

    private function createBooking(User $passenger, Flight $flight): Booking
    {
        return $passenger->bookings()->create([
            'booking_reference' => 'DBP-'.strtoupper((string) Str::ulid()),
            'flight_id' => $flight->getKey(),
            'booking_status' => Booking::STATUS_CONFIRMED,
            'booking_date' => now(),
            'number_of_passengers' => 1,
            'cabin_class' => Flight::CABIN_CLASS_ECONOMY,
            'fare_amount' => '300.00',
        ]);
    }
}
