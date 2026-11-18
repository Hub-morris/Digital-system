<?php

namespace Tests\Feature;

use App\Models\BoardingPass;
use App\Models\Booking;
use App\Models\CheckIn;
use App\Models\Flight;
use App\Models\Penalty;
use App\Models\PenaltyRule;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('security')]
class PenaltyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_departed_unboarded_booking_is_marked_no_show_and_wallet_is_debited_once(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(12));
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = Flight::factory()->create([
            'departure_date' => today(),
            'departure_time' => '11:00',
            'status' => Flight::STATUS_DEPARTED,
        ]);
        $booking = $this->createBooking($passenger, $flight, ['fare_amount' => '200.00']);
        Wallet::query()->create(['user_id' => $passenger->getKey(), 'balance' => '20.00']);
        $this->enableRule(Flight::CABIN_CLASS_ECONOMY, PenaltyRule::TYPE_PERCENTAGE, '30.00');

        $this->artisan('penalties:apply-no-shows')->assertSuccessful();

        $penalty = Penalty::query()->firstOrFail();
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->getKey(),
            'booking_status' => Booking::STATUS_NO_SHOW,
        ]);
        $this->assertSame('60.00', $penalty->penalty_amount);
        $this->assertSame('20.00', $penalty->amount_paid);
        $this->assertSame(Penalty::STATUS_APPLIED, $penalty->status);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $passenger->getKey(),
            'balance' => '0.00',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'penalty_id' => $penalty->getKey(),
            'transaction_type' => WalletTransaction::TYPE_DEBIT,
            'amount' => '20.00',
        ]);

        $this->artisan('penalties:apply-no-shows')->assertSuccessful();

        $this->assertDatabaseCount('penalties', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_no_show_sweep_does_not_penalize_a_booking_with_a_used_boarding_pass(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(12));
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = Flight::factory()->create([
            'departure_date' => today(),
            'departure_time' => '11:00',
            'status' => Flight::STATUS_DEPARTED,
        ]);
        $booking = $this->createBooking($passenger, $flight);
        $this->createUsedBoardingPass($passenger, $flight, $booking);
        $this->enableRule(Flight::CABIN_CLASS_ECONOMY, PenaltyRule::TYPE_PERCENTAGE, '30.00');

        $this->artisan('penalties:apply-no-shows')->assertSuccessful();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->getKey(),
            'booking_status' => Booking::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseCount('penalties', 0);
    }

    public function test_disabled_rule_marks_no_show_without_creating_a_penalty(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(12));
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = Flight::factory()->create([
            'departure_date' => today(),
            'departure_time' => '11:00',
            'status' => Flight::STATUS_DEPARTED,
        ]);
        $booking = $this->createBooking($passenger, $flight);

        $this->artisan('penalties:apply-no-shows')->assertSuccessful();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->getKey(),
            'booking_status' => Booking::STATUS_NO_SHOW,
        ]);
        $this->assertDatabaseCount('penalties', 0);
        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_cancellation_applies_and_collects_the_configured_penalty_immediately(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(10));
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = Flight::factory()->create([
            'departure_date' => today(),
            'departure_time' => '12:00',
        ]);
        $booking = $this->createBooking($passenger, $flight, ['fare_amount' => '200.00']);
        Wallet::query()->create(['user_id' => $passenger->getKey(), 'balance' => '100.00']);
        $this->enableRule(Flight::CABIN_CLASS_ECONOMY, PenaltyRule::TYPE_PERCENTAGE, '30.00', 3);

        $this->actingAs($passenger)
            ->patch(route('bookings.cancel', $booking))
            ->assertRedirect(route('bookings.show', $booking));

        $penalty = Penalty::query()->firstOrFail();
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->getKey(),
            'booking_status' => Booking::STATUS_CANCELLED,
        ]);
        $this->assertSame(Penalty::REASON_CANCELLATION, $penalty->penalty_reason);
        $this->assertSame('60.00', $penalty->penalty_amount);
        $this->assertSame(Penalty::STATUS_PAID, $penalty->status);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $passenger->getKey(),
            'balance' => '40.00',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $passenger->getKey(),
            'penalty_id' => $penalty->getKey(),
            'transaction_type' => WalletTransaction::TYPE_DEBIT,
            'amount' => '60.00',
        ]);
    }

    public function test_cancellation_outside_the_old_cutoff_still_applies_the_penalty(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(8));
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = Flight::factory()->create([
            'departure_date' => today(),
            'departure_time' => '12:00',
        ]);
        $booking = $this->createBooking($passenger, $flight, ['fare_amount' => '200.00']);
        $this->enableRule(Flight::CABIN_CLASS_ECONOMY, PenaltyRule::TYPE_PERCENTAGE, '30.00', 3);

        $this->actingAs($passenger)
            ->patch(route('bookings.cancel', $booking))
            ->assertRedirect(route('bookings.show', $booking));

        $penalty = Penalty::query()->firstOrFail();
        $this->assertSame(Penalty::REASON_CANCELLATION, $penalty->penalty_reason);
        $this->assertSame('60.00', $penalty->penalty_amount);
    }

    public function test_business_booking_uses_its_fare_and_fixed_penalty_per_passenger(): void
    {
        $start = now()->startOfDay()->addHours(8);
        $this->travelTo($start);
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = Flight::factory()->create([
            'departure_date' => today(),
            'departure_time' => '11:00',
        ]);
        $this->enableRule(Flight::CABIN_CLASS_BUSINESS, PenaltyRule::TYPE_FIXED, '25.00');

        $this->actingAs($passenger)
            ->get(route('flights.show', $flight))
            ->assertOk()
            ->assertSee('Fare per passenger')
            ->assertSee('25.00 per passenger');

        $this->actingAs($passenger)
            ->post(route('bookings.store'), [
                'flight_id' => $flight->getKey(),
                'number_of_passengers' => 1,
                'cabin_class' => Flight::CABIN_CLASS_BUSINESS,
            ])
            ->assertRedirect();

        $booking = Booking::query()->firstOrFail();
        $this->assertSame(Flight::CABIN_CLASS_BUSINESS, $booking->cabin_class);
        $this->assertSame('350.00', $booking->fare_amount);

        $flight->update(['status' => Flight::STATUS_DEPARTED]);
        $this->travelTo($start->copy()->addHours(4));

        $this->artisan('penalties:apply-no-shows')->assertSuccessful();

        $penalty = Penalty::query()->firstOrFail();
        $this->assertSame('25.00', $penalty->penalty_amount);
        $this->assertSame(Penalty::REASON_NO_SHOW, $penalty->penalty_reason);
        $this->assertSame('0.00', $penalty->amount_paid);
    }

    public function test_passenger_can_pay_a_penalty_and_cannot_access_another_passengers_receipt(): void
    {
        $passenger = User::factory()->create(['role' => 'passenger']);
        $otherPassenger = User::factory()->create(['role' => 'passenger']);
        $flight = Flight::factory()->create();
        $booking = $this->createBooking($passenger, $flight);
        $penalty = $this->createPenalty($passenger, $flight, $booking, [
            'penalty_amount' => '50.00',
            'amount_paid' => '20.00',
        ]);
        Wallet::query()->create(['user_id' => $passenger->getKey(), 'balance' => '30.00']);

        $this->actingAs($passenger)
            ->get(route('penalties.index'))
            ->assertOk()
            ->assertSee('penalty history')
            ->assertSee($penalty->penalty_reference);

        $this->get(route('penalties.show', $penalty))
            ->assertOk()
            ->assertSee($penalty->penalty_reference)
            ->assertSee('Penalty receipt');

        $this->post(route('penalties.pay', $penalty))
            ->assertRedirect(route('penalties.show', $penalty));

        $this->assertDatabaseHas('penalties', [
            'id' => $penalty->getKey(),
            'status' => Penalty::STATUS_PAID,
            'amount_paid' => '50.00',
        ]);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $passenger->getKey(),
            'balance' => '0.00',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'penalty_id' => $penalty->getKey(),
            'amount' => '30.00',
            'transaction_type' => WalletTransaction::TYPE_DEBIT,
        ]);

        $this->actingAs($otherPassenger)
            ->get(route('penalties.show', $penalty))
            ->assertNotFound();
    }

    public function test_passenger_cannot_pay_more_than_their_wallet_balance(): void
    {
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = Flight::factory()->create();
        $booking = $this->createBooking($passenger, $flight);
        $penalty = $this->createPenalty($passenger, $flight, $booking, [
            'penalty_amount' => '50.00',
            'amount_paid' => '20.00',
        ]);
        Wallet::query()->create(['user_id' => $passenger->getKey(), 'balance' => '10.00']);

        $this->actingAs($passenger)
            ->post(route('penalties.pay', $penalty))
            ->assertSessionHasErrors('wallet');

        $this->assertDatabaseHas('penalties', [
            'id' => $penalty->getKey(),
            'status' => Penalty::STATUS_APPLIED,
            'amount_paid' => '20.00',
        ]);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $passenger->getKey(),
            'balance' => '10.00',
        ]);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_admin_can_adjust_and_waive_a_penalty_with_wallet_refunds_and_audit_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = Flight::factory()->create();
        $booking = $this->createBooking($passenger, $flight);
        $penalty = $this->createPenalty($passenger, $flight, $booking, [
            'penalty_amount' => '50.00',
            'amount_paid' => '20.00',
        ]);
        Wallet::query()->create(['user_id' => $passenger->getKey(), 'balance' => '0.00']);

        $this->actingAs($admin)
            ->get(route('admin.penalties.show', $penalty))
            ->assertOk()
            ->assertSee('Adjust penalty')
            ->assertSee('Waive penalty');

        $this->actingAs($admin)
            ->patch(route('admin.penalties.adjust', $penalty), [
                'penalty_amount' => '10.00',
                'reason' => 'Fare was calculated incorrectly',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Penalty adjusted.');

        $this->assertDatabaseHas('penalties', [
            'id' => $penalty->getKey(),
            'penalty_amount' => '10.00',
            'amount_paid' => '10.00',
            'status' => Penalty::STATUS_PAID,
            'adjusted_by' => $admin->getKey(),
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'penalty_id' => $penalty->getKey(),
            'transaction_type' => WalletTransaction::TYPE_CREDIT,
            'amount' => '10.00',
        ]);

        $this->patch(route('admin.penalties.waive', $penalty), [
            'reason' => 'Approved customer service waiver',
        ])->assertRedirect();

        $this->assertDatabaseHas('penalties', [
            'id' => $penalty->getKey(),
            'status' => Penalty::STATUS_WAIVED,
            'amount_paid' => '0.00',
        ]);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $passenger->getKey(),
            'balance' => '20.00',
        ]);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_admin_can_configure_class_rules_and_generate_daily_reports(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $passenger = User::factory()->create(['role' => 'passenger']);
        $flight = Flight::factory()->create();
        $booking = $this->createBooking($passenger, $flight);
        $this->createPenalty($passenger, $flight, $booking);

        $rules = [
            'economy' => [
                'enabled' => '1',
                'penalty_type' => PenaltyRule::TYPE_PERCENTAGE,
                'penalty_value' => '30.00',
            ],
            'business' => [
                'enabled' => '0',
                'penalty_type' => PenaltyRule::TYPE_FIXED,
                'penalty_value' => '25.00',
            ],
            'first_class' => [
                'enabled' => '1',
                'penalty_type' => PenaltyRule::TYPE_FIXED,
                'penalty_value' => '50.00',
            ],
        ];

        $this->actingAs($admin)
            ->put(route('admin.penalty-rules.update'), ['rules' => $rules])
            ->assertRedirect(route('admin.penalty-rules.edit'))
            ->assertSessionHas('status', 'Penalty rules updated.');

        $this->assertDatabaseHas('penalty_rules', [
            'cabin_class' => Flight::CABIN_CLASS_ECONOMY,
            'enabled' => true,
            'penalty_value' => '30.00',
        ]);

        $this->get(route('admin.penalties.report', [
            'period' => 'daily',
            'date' => today()->toDateString(),
        ]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8')
            ->assertStreamedContent(
                "Period,\"Penalty count\",\"Penalty total\",Collected,Outstanding\n"
                .today()->toDateString().",1,50.00,0.00,50.00\n",
            );

        $this->get(route('admin.penalties.report', [
            'period' => 'monthly',
            'date' => today()->toDateString(),
        ]))
            ->assertOk()
            ->assertStreamedContent(
                "Period,\"Penalty count\",\"Penalty total\",Collected,Outstanding\n"
                .today()->format('Y-m').",1,50.00,0.00,50.00\n",
            );

        $this->get(route('admin.penalties.index', [
            'user_email' => $passenger->email,
            'flight_number' => $flight->flight_number,
            'status' => Penalty::STATUS_APPLIED,
        ]))
            ->assertOk()
            ->assertSee($passenger->email)
            ->assertSee($flight->flight_number);
    }

    public function test_admin_can_record_verified_wallet_credits_and_non_admins_cannot_manage_payments(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $passenger = User::factory()->create(['role' => 'passenger']);

        $this->actingAs($admin)
            ->post(route('admin.payments.wallet-credits.store'), [
                'user_email' => $passenger->email,
                'amount' => '75.00',
                'external_reference' => 'BANK-TRANSFER-123',
                'reason' => 'Bank transfer reference 123',
            ])
            ->assertRedirect(route('admin.payments.index'))
            ->assertSessionHas('status', 'Wallet credit recorded.');

        $this->assertDatabaseHas('wallets', [
            'user_id' => $passenger->getKey(),
            'balance' => '75.00',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $passenger->getKey(),
            'actor_id' => $admin->getKey(),
            'transaction_type' => WalletTransaction::TYPE_CREDIT,
            'amount' => '75.00',
            'external_reference' => 'BANK-TRANSFER-123',
        ]);

        $this->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee($passenger->email);

        $this->post(route('admin.payments.wallet-credits.store'), [
            'user_email' => $passenger->email,
            'amount' => '75.00',
            'external_reference' => 'BANK-TRANSFER-123',
            'reason' => 'Duplicate bank transfer reference',
        ])->assertSessionHasErrors('external_reference');

        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $passenger->getKey(),
            'balance' => '75.00',
        ]);

        $this->actingAs($passenger)
            ->get(route('admin.payments.index'))
            ->assertForbidden();
    }

    private function createBooking(User $passenger, Flight $flight, array $attributes = []): Booking
    {
        return $passenger->bookings()->create([
            'booking_reference' => 'DBP-'.strtoupper((string) Str::ulid()),
            'flight_id' => $flight->getKey(),
            'booking_status' => Booking::STATUS_CONFIRMED,
            'booking_date' => now(),
            'number_of_passengers' => 1,
            'cabin_class' => Flight::CABIN_CLASS_ECONOMY,
            'fare_amount' => '200.00',
            ...$attributes,
        ]);
    }

    private function createPenalty(User $passenger, Flight $flight, Booking $booking, array $attributes = []): Penalty
    {
        return Penalty::query()->create([
            'penalty_reference' => 'PNL-'.strtoupper((string) Str::ulid()),
            'booking_id' => $booking->getKey(),
            'user_id' => $passenger->getKey(),
            'flight_id' => $flight->getKey(),
            'penalty_amount' => '50.00',
            'amount_paid' => '0.00',
            'penalty_reason' => Penalty::REASON_NO_SHOW,
            'status' => Penalty::STATUS_APPLIED,
            ...$attributes,
        ]);
    }

    private function createUsedBoardingPass(User $passenger, Flight $flight, Booking $booking): void
    {
        $checkIn = CheckIn::query()->create([
            'booking_id' => $booking->getKey(),
            'passenger_id' => $passenger->getKey(),
            'flight_id' => $flight->getKey(),
            'check_in_reference' => 'CI-'.strtoupper((string) Str::ulid()),
            'checked_in_at' => now(),
            'seat_number' => '001',
            'status' => 'Checked In',
        ]);

        BoardingPass::query()->create([
            'pass_reference' => 'BP-'.strtoupper((string) Str::ulid()),
            'booking_id' => $booking->getKey(),
            'passenger_id' => $passenger->getKey(),
            'flight_id' => $flight->getKey(),
            'check_in_id' => $checkIn->getKey(),
            'seat_number' => '001',
            'pass_status' => BoardingPass::STATUS_USED,
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
            'used_at' => now(),
        ]);
    }

    private function enableRule(
        string $cabinClass,
        string $type,
        string $value,
        int $deadlineHours = 3,
    ): PenaltyRule {
        $rule = PenaltyRule::query()->where('cabin_class', $cabinClass)->firstOrFail();
        $rule->update([
            'enabled' => true,
            'penalty_type' => $type,
            'penalty_value' => $value,
            'cancellation_deadline_hours' => $deadlineHours,
        ]);

        return $rule->refresh();
    }
}
