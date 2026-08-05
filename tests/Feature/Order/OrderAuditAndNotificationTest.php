<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Mail\Orders\AppointmentRequestedMail;
use App\Mail\Orders\InitialInspectionCompletedMail;
use App\Mail\Orders\OrderCreatedAdminMail;
use App\Mail\Orders\OrderCreatedCustomerMail;
use App\Mail\Orders\OrderStatusUpdatedMail;
use App\Models\User;
use App\Modules\UserProfile\B2B\Models\B2B;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\InspectionStation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Profile\Models\Address;
use App\Modules\UserProfile\Profile\Models\Contact;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Checkpoint 12: leasyback_order_audit_log wiring (docs/B2C_ADMIN_STATUS_MATRIX.md
 * §6's audit-trail consolidation) and real order-created/status-changed
 * notification emails keyed off the actual vehicle owner, not a hardcoded
 * address (docs/B2C_ADMIN_MIGRATION_AUDIT.md §4.7).
 */
class OrderAuditAndNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_creating_a_privatkunde_order_writes_a_create_order_audit_entry_and_notifies_the_owner(): void
    {
        Mail::fake();
        config(['mail_notifications.admin_recipients' => ['ops@leasyback.test']]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde, 'email' => 'owner@example.com']);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $station = InspectionStation::factory()->create(['provider' => 'tuvsud']);

        $this->withHeaders($this->bearer($owner))
            ->postJson("/order/tuvsud/create/{$vehicle->vehicle_id}", [
                'station_id' => $station->station_id,
                'termin' => '2026-09-01T10:00:00+02:00',
            ])
            ->assertOk();

        $this->assertDatabaseHas('leasyback_order_audit_log', [
            'vehicle_id' => $vehicle->vehicle_id,
            'action' => 'CREATE_ORDER',
        ]);

        Mail::assertQueued(OrderCreatedAdminMail::class, fn ($mail) => $mail->hasTo('ops@leasyback.test'));
        Mail::assertQueued(OrderCreatedCustomerMail::class, fn ($mail) => $mail->hasTo('owner@example.com'));
    }

    public function test_creating_a_firmenkunde_order_writes_a_request_order_audit_entry(): void
    {
        Mail::fake();
        $b2bUser = User::factory()->create(['user_type' => UserType::Firmenkunde]);
        $b2b = B2B::create([
            'contact_id' => Contact::factory()->create()->contact_id,
            'address_id' => Address::factory()->create()->address_id,
            'company_name' => 'Acme GmbH',
            'contact_email' => 'fleet@acme.example',
        ]);
        $vehicle = Vehicle::factory()->forB2b($b2b->b2b_id)->create();
        DB::table('user_b2b')->insert([
            'user_id' => $b2bUser->id,
            'b2b_id' => $vehicle->b2b_id,
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->withHeaders($this->bearer($b2bUser))
            ->postJson("/order/b2b/create/{$vehicle->vehicle_id}", [
                'requested_collection_date' => now()->addWeek()->toDateString(),
                'collection_address' => ['street' => 'Werkstr', 'zip_code' => '80331', 'city' => 'München'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('leasyback_order_audit_log', [
            'vehicle_id' => $vehicle->vehicle_id,
            'action' => 'REQUEST_ORDER',
        ]);

        Mail::assertQueued(AppointmentRequestedMail::class, fn ($mail) => $mail->hasTo('fleet@acme.example'));
    }

    public function test_approving_an_order_writes_an_approve_order_audit_entry(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $order = LeasybackOrder::factory()->withStatus(OrderStatus::OrderRequested)->create(['vehicle_id' => $vehicle->vehicle_id]);

        $this->withHeaders($this->bearer($admin))
            ->postJson("/order/tuvsud/order/approve/{$order->id}")
            ->assertOk();

        $this->assertDatabaseHas('leasyback_order_audit_log', [
            'order_id' => $order->id,
            'action' => 'APPROVE_ORDER',
        ]);

        // TransitionOrderStatus already sent the customer-facing
        // notification for this transition — approveOrder() must not
        // send a second one.
        Mail::assertQueued(OrderStatusUpdatedMail::class, 1);
    }

    public function test_a_real_status_transition_sends_exactly_one_customer_notification(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde, 'email' => 'owner2@example.com']);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $order = LeasybackOrder::factory()->withStatus(OrderStatus::Confirmed)->create(['vehicle_id' => $vehicle->vehicle_id]);

        app(TransitionOrderStatus::class)->__invoke($order, 'inspected', 'admin', 'Tester', null);

        Mail::assertQueued(InitialInspectionCompletedMail::class, fn ($mail) => $mail->hasTo('owner2@example.com'));
        Mail::assertQueued(InitialInspectionCompletedMail::class, 1);
    }

    /**
     * A no-op "transition" to the order's own current status must not
     * send a notification — nothing actually changed.
     */
    public function test_a_no_op_transition_does_not_send_a_notification(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $order = LeasybackOrder::factory()->withStatus(OrderStatus::Confirmed)->create(['vehicle_id' => $vehicle->vehicle_id]);

        app(TransitionOrderStatus::class)->__invoke($order, 'confirmed', 'admin', 'Tester', null);

        Mail::assertNothingQueued();
    }
}
