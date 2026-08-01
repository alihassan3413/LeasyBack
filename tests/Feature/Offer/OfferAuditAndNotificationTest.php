<?php

namespace Tests\Feature\Offer;

use App\Enums\UserType;
use App\Mail\StatusChangeNotification;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Checkpoint 12: leasyback_offer_audit_log wiring (already-correct action
 * values per docs/B2C_ADMIN_STATUS_MATRIX.md §6, just previously unwired)
 * and the offer-published customer notification.
 */
class OfferAuditAndNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_creating_an_offer_writes_a_created_audit_entry(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->create();

        $this->withHeaders($this->bearer($admin))
            ->postJson("/admin/offers/create/{$order->auftragsnummer}", [
                'repair_cost_net' => 100, 'repair_cost_gross' => 119,
                'depreciation_value_net' => 0, 'depreciation_value_gross' => 0,
                'workshop_repair_quote_net' => 0, 'workshop_repair_quote_gross' => 0,
                'missing_parts_cost_net' => 0, 'missing_parts_cost_gross' => 0,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('leasyback_offer_audit_log', [
            'auftragsnummer' => $order->auftragsnummer,
            'action' => 'created',
        ]);
    }

    public function test_publishing_an_offer_writes_a_published_audit_entry_and_notifies_the_owner(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde, 'email' => 'owner@example.com']);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);
        $offer = LeasybackOffer::factory()->create(['order_id' => $order->id, 'auftragsnummer' => $order->auftragsnummer]);

        $this->withHeaders($this->bearer($admin))
            ->postJson("/admin/offers/publish/{$offer->offer_id}")
            ->assertOk();

        $this->assertDatabaseHas('leasyback_offer_audit_log', [
            'offer_id' => $offer->offer_id,
            'action' => 'published',
        ]);

        Mail::assertQueued(StatusChangeNotification::class, fn ($mail) => $mail->hasTo('owner@example.com'));
    }

    public function test_cancelling_an_offer_writes_a_cancelled_audit_entry(): void
    {
        $admin = $this->admin();
        $order = LeasybackOrder::factory()->create();
        $offer = LeasybackOffer::factory()->create(['order_id' => $order->id, 'auftragsnummer' => $order->auftragsnummer]);

        $this->withHeaders($this->bearer($admin))
            ->postJson("/admin/offers/cancel/{$offer->offer_id}", ['cancellation_reason' => 'test'])
            ->assertOk();

        $this->assertDatabaseHas('leasyback_offer_audit_log', [
            'offer_id' => $offer->offer_id,
            'action' => 'cancelled',
        ]);
    }

    /**
     * selectOffer() writes three kinds of audit rows in one call: the
     * selected offer itself, every auto-closed sibling, and an
     * "offer-related order touchpoint" on the order's own audit trail
     * (per docs/B2C_ADMIN_STATUS_MATRIX.md §6) — order_status itself stays
     * untouched (Checkpoint 6/7's deliberate decision), so without this the
     * order's own history would show no trace an offer was ever selected.
     */
    public function test_selecting_an_offer_writes_selected_closed_and_order_touchpoint_entries(): void
    {
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $customer->id]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);
        $selected = LeasybackOffer::factory()->published()->create(['order_id' => $order->id, 'auftragsnummer' => $order->auftragsnummer, 'offer_sequence' => 1]);
        $sibling = LeasybackOffer::factory()->published()->create(['order_id' => $order->id, 'auftragsnummer' => $order->auftragsnummer, 'offer_sequence' => 2]);

        $this->withHeaders($this->bearer($customer))
            ->postJson("/vehicle/offers/customer/select/{$selected->offer_id}")
            ->assertOk();

        $this->assertDatabaseHas('leasyback_offer_audit_log', [
            'offer_id' => $selected->offer_id,
            'action' => 'selected_by_customer',
        ]);
        $this->assertDatabaseHas('leasyback_offer_audit_log', [
            'offer_id' => $sibling->offer_id,
            'action' => 'closed_after_customer_selection',
        ]);
        $this->assertDatabaseHas('leasyback_order_audit_log', [
            'order_id' => $order->id,
            'action' => 'OFFER_SELECTED',
        ]);
    }
}
