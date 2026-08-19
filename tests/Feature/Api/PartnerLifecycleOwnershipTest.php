<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Mail\Orders\AppointmentConfirmedMail;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderStatusUpdate;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Support\PartnerLifecyclePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * "An integration may only cause the transitions it owns."
 *
 * The status callback used to accept any value in OrderStatus and hand it
 * straight to TransitionOrderStatus, so holding the inspection provider's API
 * key was enough to cancel a customer's order or tell them their car was ready
 * for pickup — every state the graph could reach from wherever the order stood.
 * These tests pin the narrowed allow-list, and pin that the calls the provider
 * legitimately makes still work, including on retry.
 */
class PartnerLifecycleOwnershipTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private const KEY = 'the-real-key';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['services.tuvsud.api_key' => self::KEY]);
    }

    // ------------------------------------------------------------- owned

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function ownedTransitions(): array
    {
        return [
            'appointment confirmed' => ['order_placed', 'confirmed', 'confirmed'],
            'inspection completed' => ['confirmed', 'inspected', 'inspected'],
            'reinspection performed' => ['workshop', 'reinspection', 'reinspection'],
        ];
    }

    #[DataProvider('ownedTransitions')]
    public function test_the_inspection_provider_may_make_the_transitions_it_owns(string $from, string $sent, string $expected): void
    {
        $order = $this->b2cOrder($from);

        $this->callStatus($order->auftragsnummer, $sent)->assertOk();

        $this->assertSame($expected, $order->fresh()->order_status);
    }

    /**
     * The safer external vocabulary maps onto the same three transitions, so a
     * provider can describe what happened without naming an internal status.
     */
    #[DataProvider('eventNames')]
    public function test_provider_event_names_map_onto_the_same_transitions(string $from, string $event, string $expected): void
    {
        $order = $this->b2cOrder($from);

        $this->callStatus($order->auftragsnummer, $event)->assertOk();

        $this->assertSame($expected, $order->fresh()->order_status);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function eventNames(): array
    {
        return [
            ['order_placed', 'appointment_confirmed', 'confirmed'],
            ['confirmed', 'inspection_completed', 'inspected'],
            ['workshop', 'reinspection_completed', 'reinspection'],
        ];
    }

    // ---------------------------------------------------------- not owned

    /**
     * Every status the provider must never reach, tested from a state the
     * graph would otherwise allow it from — so what refuses the call is the
     * ownership rule, not the transition table doing it by accident.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function unownedTransitions(): array
    {
        return [
            'cancelling a live order' => ['confirmed', 'cancelled'],
            'discarding a request' => ['order_requested', 'discarded'],
            'placing the booking itself' => ['order_requested', 'order_placed'],
            'sending the car to the workshop' => ['inspected', 'workshop'],
            'sending it back to the workshop' => ['reinspection', 'reworkshop'],
            'announcing it ready for pickup' => ['reinspection', 'delivered'],
        ];
    }

    #[DataProvider('unownedTransitions')]
    public function test_the_inspection_provider_may_not_make_transitions_it_does_not_own(string $from, string $target): void
    {
        $order = $this->b2cOrder($from);

        $this->callStatus($order->auftragsnummer, $target)
            ->assertStatus(403)
            ->assertJson(['error' => "This integration may not set order status '{$target}'."]);

        $this->assertSame($from, $order->fresh()->order_status);
    }

    /**
     * The B2B return graph carries six further statuses the inspection
     * provider has no part in at all.
     */
    #[DataProvider('b2bOnlyTransitions')]
    public function test_the_inspection_provider_owns_nothing_on_the_b2b_return_graph(string $from, string $target): void
    {
        $order = $this->b2bOrder($from);

        $this->callStatus($order->auftragsnummer, $target)->assertStatus(403);

        $this->assertSame($from, $order->fresh()->order_status);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function b2bOnlyTransitions(): array
    {
        return [
            ['confirmed', 'vehicle_collected'],
            ['inspected', 'workshop_commissioned'],
            ['workshop', 'repair_completed'],
            ['reinspection', 'vehicle_returned'],
            ['vehicle_returned', 'invoice_processed'],
        ];
    }

    /**
     * No status the provider does not own is reachable, whatever the order's
     * state — the exhaustive version of the two providers above.
     */
    public function test_no_unowned_status_is_reachable_from_any_state(): void
    {
        // The sweep makes far more calls than the route's 60/min allows, and a
        // 429 would prove nothing about ownership.
        $this->withoutMiddleware(ThrottleRequests::class);

        $owned = PartnerLifecyclePermissions::ownedBy(PartnerLifecyclePermissions::TUV_SUD);

        foreach (array_diff(OrderStatus::values(), $owned) as $target) {
            foreach (['order_requested', 'order_placed', 'confirmed', 'inspected', 'workshop', 'reinspection'] as $from) {
                $order = $this->b2cOrder($from);

                $response = $this->callStatus($order->auftragsnummer, $target);

                $this->assertContains(
                    $response->getStatusCode(),
                    [403, 422],
                    "status [{$target}] from [{$from}] was not refused",
                );
                $this->assertSame($from, $order->fresh()->order_status, "status [{$target}] from [{$from}] mutated the order");
            }
        }
    }

    // ------------------------------------------------- ordering of the gates

    /**
     * An edge that does not exist reports as a bad transition even when the
     * provider owns the target, so a legitimate integration debugging a
     * sequencing mistake is told what is actually wrong.
     */
    public function test_an_impossible_edge_reports_as_a_transition_error_not_a_permission_error(): void
    {
        $order = $this->b2cOrder('delivered');

        $this->callStatus($order->auftragsnummer, 'inspected')
            ->assertStatus(422)
            ->assertJson(['error' => "Cannot transition order from 'delivered' to 'inspected'."]);
    }

    public function test_an_unknown_status_value_is_still_rejected(): void
    {
        $order = $this->b2cOrder('order_placed');

        $this->callStatus($order->auftragsnummer, 'totally_made_up')->assertStatus(422);

        $this->assertSame('order_placed', $order->fresh()->order_status);
    }

    // ------------------------------------------------------------- replay

    public function test_a_redelivered_callback_is_an_idempotent_success(): void
    {
        $order = $this->b2cOrder('order_placed');

        $this->callStatus($order->auftragsnummer, 'inspection_completed');
        $this->assertSame('order_placed', $order->fresh()->order_status, 'guard sanity: inspected is not reachable from order_placed');

        $this->callStatus($order->auftragsnummer, 'appointment_confirmed')->assertOk();
        $this->callStatus($order->auftragsnummer, 'appointment_confirmed')->assertOk();
        $this->callStatus($order->auftragsnummer, 'appointment_confirmed')->assertOk();

        $this->assertSame('confirmed', $order->fresh()->order_status);

        // TransitionOrderStatus records the real move once and treats the
        // repeats as no-ops, so a retrying provider produces no extra history
        // and no extra customer mail.
        $this->assertSame(1, OrderStatusUpdate::query()
            ->where('auftragsnummer', $order->auftragsnummer)
            ->where('new_status', 'confirmed')
            ->count());

        Mail::assertQueued(AppointmentConfirmedMail::class, 1);
    }

    // ------------------------------------------------- the confirm endpoint

    public function test_the_appointment_confirmation_callback_still_works(): void
    {
        $order = $this->b2cOrder('order_placed');

        $this->withHeader('X-API-Key', self::KEY)
            ->getJson('/order/tuvsud/confirm?auftragsnummer='.$order->auftragsnummer)
            ->assertOk();

        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    // --------------------------------------------------------------- auth

    public function test_an_unauthenticated_caller_carries_no_provider_identity(): void
    {
        $order = $this->b2cOrder('order_placed');

        $this->getJson('/order/tuvsud/status?auftragsnummer='.$order->auftragsnummer.'&status=confirmed')
            ->assertStatus(401);

        $this->assertSame('order_placed', $order->fresh()->order_status);
    }

    /**
     * The provider is whatever the key proves, so naming a different one in
     * the request changes nothing.
     */
    public function test_a_caller_cannot_name_its_own_provider(): void
    {
        // `reinspection -> delivered` is a legal edge, so the only thing that
        // can refuse this call is the ownership rule.
        $order = $this->b2cOrder('reinspection');

        $this->withHeader('X-API-Key', self::KEY)
            ->getJson('/order/tuvsud/status?auftragsnummer='.$order->auftragsnummer.'&status=delivered&provider=admin')
            ->assertStatus(403);

        $this->assertSame('reinspection', $order->fresh()->order_status);
    }

    // ---------------------------------------------------- permission table

    public function test_dekra_owns_no_lifecycle_transition_today(): void
    {
        $this->assertSame([], PartnerLifecyclePermissions::ownedBy(PartnerLifecyclePermissions::DEKRA));
    }

    public function test_an_unknown_provider_owns_nothing(): void
    {
        $this->assertSame([], PartnerLifecyclePermissions::ownedBy('some-new-partner'));
        $this->assertFalse(PartnerLifecyclePermissions::owns('', 'confirmed'));
    }

    /**
     * The allow-list may only ever name real statuses — a typo here would
     * silently grant nothing, or worse, silently grant something once the
     * enum changed underneath it.
     */
    public function test_every_owned_status_is_a_real_order_status(): void
    {
        foreach ([PartnerLifecyclePermissions::TUV_SUD, PartnerLifecyclePermissions::DEKRA] as $provider) {
            foreach (PartnerLifecyclePermissions::ownedBy($provider) as $status) {
                $this->assertNotNull(OrderStatus::tryFrom($status), "[{$status}] is not an OrderStatus");
            }
        }
    }

    // ------------------------------------------------------------ helpers

    private function callStatus(string $auftragsnummer, string $status): TestResponse
    {
        return $this->withHeader('X-API-Key', self::KEY)
            ->getJson("/order/tuvsud/status?auftragsnummer={$auftragsnummer}&status={$status}");
    }

    private function b2cOrder(string $status): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2b_id' => null]);

        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
        ]);
    }

    private function b2bOrder(string $status): LeasybackOrder
    {
        $vehicle = $this->makeB2bVehicle($this->makeCompany());

        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
        ]);
    }
}
