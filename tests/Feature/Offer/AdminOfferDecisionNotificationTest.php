<?php

namespace Tests\Feature\Offer;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Admin's in-app notification for the customer's decision on an offer.
 *
 * Accepting used to reach the customer by mail and the partner webhook and
 * Admin not at all; rejecting reached nobody. The only staff-facing signal
 * either way was the order page's own task list.
 *
 * In-app only on purpose — no mail is asserted here because none is sent.
 */
class AdminOfferDecisionNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
    }

    public function test_accepting_an_offer_notifies_admin(): void
    {
        $admin = $this->admin();
        [$owner, $order, $offer] = $this->publishedOffer();

        $this->actingAs($owner)->from('/dashboard')
            ->post(route('offers.select', $offer->offer_id))
            ->assertRedirect();

        $this->assertSame('selected', $offer->fresh()->offer_status);

        Notification::assertSentTo($admin, SystemNotification::class, function ($notification) use ($order) {
            $payload = $notification->toArray($order);

            $this->assertSame(NotificationType::OfferAccepted->value, $payload['type']);
            $this->assertStringContainsString('WI-AB 1234', $payload['body']);
            // final_total_gross is computed on save: 119 + 59,50.
            $this->assertStringContainsString('178,50', $payload['body']);
            $this->assertSame($order->id, $payload['meta']['order_id']);
            $this->assertSame(route('admin.orders.show', $order->id, false), $payload['url']);

            return true;
        });
    }

    public function test_rejecting_an_offer_notifies_admin_with_the_customer_comment(): void
    {
        $admin = $this->admin();
        [$owner, $order, $offer] = $this->publishedOffer();

        $this->actingAs($owner)->from('/dashboard')
            ->post(route('offers.reject', $offer->offer_id), ['customer_comment' => 'Zu teuer.'])
            ->assertRedirect();

        $this->assertSame('rejected', $offer->fresh()->offer_status);

        Notification::assertSentTo($admin, SystemNotification::class, function ($notification) use ($order) {
            $payload = $notification->toArray($order);

            $this->assertSame(NotificationType::OfferRejected->value, $payload['type']);
            $this->assertStringContainsString('WI-AB 1234', $payload['body']);
            $this->assertStringContainsString('Zu teuer.', $payload['body']);
            $this->assertSame($order->id, $payload['meta']['order_id']);

            return true;
        });
    }

    /**
     * Nobody is told twice about one decision. A double-click reaches
     * selectOffer() a second time, which answers from the decision already on
     * disk and sends nothing.
     */
    public function test_a_replayed_acceptance_notifies_admin_only_once(): void
    {
        $admin = $this->admin();
        [$owner, , $offer] = $this->publishedOffer();

        $this->actingAs($owner)->from('/dashboard')->post(route('offers.select', $offer->offer_id));
        $this->actingAs($owner)->from('/dashboard')->post(route('offers.select', $offer->offer_id));

        Notification::assertSentToTimes($admin, SystemNotification::class, 1);
    }

    /**
     * An admin accepting on the customer's behalf is already on the order.
     */
    public function test_admin_accepting_on_behalf_does_not_notify_admin(): void
    {
        $admin = $this->admin();
        [, , $offer] = $this->publishedOffer();

        $this->actingAs($admin)->from('/admin/orders')
            ->patch(route('admin.orders.offers.select', $offer->offer_id))
            ->assertRedirect();

        $this->assertSame('selected', $offer->fresh()->offer_status);

        Notification::assertNothingSentTo($admin);
    }

    public function test_a_deactivated_admin_is_not_notified(): void
    {
        $active = $this->admin();
        $inactive = $this->admin(['is_active' => false]);
        [$owner, , $offer] = $this->publishedOffer();

        $this->actingAs($owner)->from('/dashboard')->post(route('offers.select', $offer->offer_id));

        Notification::assertSentTo($active, SystemNotification::class);
        Notification::assertNothingSentTo($inactive);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function admin(array $attributes = []): User
    {
        return User::factory()->create(['user_type' => UserType::Admin, ...$attributes]);
    }

    /**
     * @return array{0: User, 1: LeasybackOrder, 2: LeasybackOffer}
     */
    private function publishedOffer(): array
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create(['b2c_user_id' => $owner->id, 'license_plate' => 'WI-AB 1234']);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);
        $offer = LeasybackOffer::factory()->published()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
        ]);

        return [$owner, $order, $offer];
    }
}
