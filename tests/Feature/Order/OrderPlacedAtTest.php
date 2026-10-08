<?php

namespace Tests\Feature\Order;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * `sent_at` is when an order was placed (became order_placed) — never when
 * the customer asked. A B2B request therefore has none until LeasyBack approves
 * it, and approving it, by either path, records it. Admin shows it as
 * "Auftrag erteilt am", and the confirmed appointment as "Bestätigter Termin".
 */
class OrderPlacedAtTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Carbon::setTestNow('2026-10-08 10:00:00');
    }

    private function requestCollection(): LeasybackOrder
    {
        $company = $this->makeCompany();
        $vehicle = $this->makeB2bVehicle($company);

        $this->actingAs($this->makeOwner($company))
            ->post(route('orders.store', $vehicle->vehicle_id), [
                'requested_collection_date' => now()->addWeeks(2)->toDateString(),
                'requested_collection_time_slot' => '10:00-12:00',
                'collection_address' => ['street' => 'Invalidenstraße', 'zip_code' => '10115', 'city' => 'Berlin'],
            ])
            ->assertSessionHasNoErrors();

        return LeasybackOrder::where('vehicle_id', $vehicle->vehicle_id)->sole();
    }

    /** @return array<string, mixed> */
    private function adminOrder(LeasybackOrder $order): array
    {
        return json_decode((string) json_encode(
            $this->actingAs($this->makeAdmin())->get(route('admin.orders.show', $order->id))->viewData('page')['props']['order'],
        ), true);
    }

    public function test_a_new_b2b_request_is_not_placed_and_has_no_confirmed_appointment(): void
    {
        $order = $this->requestCollection();

        $this->assertSame('order_requested', $order->order_status);
        $this->assertNull($order->sent_at, 'a request is not a placed order');

        $detail = $this->adminOrder($order);
        $this->assertNull($detail['sent_at']);
        $this->assertNull($detail['confirmation_date']);
        $this->assertNull($detail['collection']['confirmed_collection_date']);
        $this->assertNotContains('order_placed', array_column($detail['status_updates'], 'new_status'));
        $this->assertNotNull($detail['created_at'], 'when it was requested is "Angelegt am"');
    }

    public function test_approving_the_request_records_when_it_was_placed(): void
    {
        $order = $this->requestCollection();
        Carbon::setTestNow('2026-10-09 14:30:00');

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.approve', $order->id))
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('order_placed', $order->order_status);
        $this->assertTrue($order->sent_at->equalTo(Carbon::parse('2026-10-09 14:30:00')));

        $detail = $this->adminOrder($order);
        $this->assertNotNull($detail['sent_at']);
        $this->assertNull($detail['collection']['confirmed_collection_date'], 'approved, but no appointment confirmed yet');
    }

    /** Confirming the collection date approves the request implicitly; that is the placement too. */
    public function test_confirming_the_collection_records_the_placement_and_the_appointment(): void
    {
        $order = $this->requestCollection();
        Carbon::setTestNow('2026-10-09 09:00:00');
        $date = now()->addDays(5)->toDateString();

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.collection', $order->id), ['confirmed_collection_date' => $date])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('confirmed', $order->order_status);
        $this->assertTrue($order->sent_at->equalTo(Carbon::parse('2026-10-09 09:00:00')));

        $detail = $this->adminOrder($order);
        $this->assertStringStartsWith($date, (string) $detail['collection']['confirmed_collection_date']);
        $this->assertNull($detail['confirmation_date'], 'confirmation_date is the B2C provider appointment');
    }

    /** Later steps never move the placement time. */
    public function test_the_placement_time_is_not_overwritten_by_later_steps(): void
    {
        $order = $this->requestCollection();
        Carbon::setTestNow('2026-10-09 14:30:00');
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->from('/admin/dashboard')->post(route('admin.orders.approve', $order->id));
        $placedAt = $order->fresh()->sent_at;

        Carbon::setTestNow('2026-10-10 08:00:00');
        $this->actingAs($admin)->from('/admin/dashboard')
            ->patch(route('admin.orders.collection', $order->id), ['confirmed_collection_date' => now()->addDays(3)->toDateString()])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->from('/admin/dashboard')->post(route('admin.orders.approve', $order->id));

        $order->refresh();
        $this->assertSame('confirmed', $order->order_status);
        $this->assertTrue($order->sent_at->equalTo($placedAt));
    }

    public function test_the_admin_page_labels_the_fields_by_what_they_hold(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/Admin/Orders/Show.vue'));

        $this->assertStringContainsString("label: 'Auftrag erteilt am'", $page);
        $this->assertStringContainsString("label: 'Bestätigter Termin'", $page);
        $this->assertStringNotContainsString("label: 'Gesendet am'", $page);

        $order = $this->requestCollection();

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.orders.show', $order->id))
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->component('Admin/Orders/Show')
                ->where('order.sent_at', null)
                ->has('order.status_updates')
            );
    }
}
