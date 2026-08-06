<?php

namespace Tests\Feature\PartnerApi;

use App\Models\B2B as ShimB2B;
use App\Models\LeasybackOffer;
use App\Models\Vehicle as ShimVehicle;
use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use App\Modules\PartnerApi\Jobs\FanOutPartnerWebhookEvent;
use App\Modules\PartnerApi\Models\PartnerWebhookDelivery;
use App\Modules\PartnerApi\Models\PartnerWebhookEventRecord;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderLogistics;
use App\Modules\UserProfile\Order\Services\B2bOfferService;
use App\Modules\UserProfile\Order\Services\OrderCollectionService;
use App\Modules\UserProfile\Order\Services\OrderService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerWebhooks;
use Tests\TestCase;

/**
 * The outbox: which business writes produce an event, and what the event is
 * allowed to contain.
 *
 * The two transaction tests are the load-bearing ones. Together they say the
 * event row and the business change share a fate — committed together, rolled
 * back together — which is the only way "a partner was told something that
 * never happened" and "something happened and nobody was told" are both
 * impossible.
 */
class PartnerWebhookEmissionTest extends TestCase
{
    use BuildsB2bCompanies;
    use BuildsPartnerClients;
    use BuildsPartnerWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        $this->allowLocalWebhookTargets();
    }

    public function test_a_status_transition_records_exactly_one_status_changed_event(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $this->makeSubscription($client, [PartnerWebhookEvent::OrderStatusChanged->value]);

        $order = $this->makeOrderFor($client->b2b_id);

        app(TransitionOrderStatus::class)($order, 'order_placed', 'test', 'Test');

        $events = PartnerWebhookEventRecord::where('type', 'order.status_changed')->get();

        $this->assertCount(1, $events);
        $this->assertSame('order_requested', $events->first()->payload['previous_status']);
        $this->assertSame('order_placed', $events->first()->payload['order']['status']);
        $this->assertStringStartsWith('evt_', $events->first()->event_id);
    }

    /**
     * Re-requesting the status an order already holds is a documented no-op in
     * TransitionOrderStatus. It must not look like a transition to a partner
     * either, or a poller redelivering the same call would produce a stream of
     * identical events.
     */
    public function test_a_no_op_transition_records_nothing(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $this->makeSubscription($client);

        $order = $this->makeOrderFor($client->b2b_id);

        app(TransitionOrderStatus::class)($order, 'order_requested', 'test', 'Test');

        $this->assertSame(0, PartnerWebhookEventRecord::count());
    }

    /**
     * The whole point of writing the event inside the caller's transaction.
     */
    public function test_a_rolled_back_transaction_leaves_no_event(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $this->makeSubscription($client);

        $order = $this->makeOrderFor($client->b2b_id);

        try {
            DB::transaction(function () use ($order) {
                app(TransitionOrderStatus::class)($order, 'order_placed', 'test', 'Test');

                throw new RuntimeException('Something further out failed.');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('order_requested', $order->fresh()->order_status);
        $this->assertSame(0, PartnerWebhookEventRecord::count());
    }

    /**
     * The four transitions with a specific meaning send both the generic event
     * and their own, so a partner can subscribe to either without filtering.
     */
    public function test_a_meaningful_transition_also_records_its_specific_event(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $this->makeSubscription($client);

        $order = $this->makeOrderFor($client->b2b_id, 'vehicle_collected');

        app(TransitionOrderStatus::class)($order, 'inspected', 'test', 'Test');

        $this->assertSame(
            ['order.appraisal_completed', 'order.status_changed'],
            PartnerWebhookEventRecord::pluck('type')->sort()->values()->all(),
        );
    }

    public function test_no_event_is_recorded_when_nobody_subscribes_to_the_type(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $this->makeSubscription($client, [PartnerWebhookEvent::OfferPublished->value]);

        $order = $this->makeOrderFor($client->b2b_id);

        app(TransitionOrderStatus::class)($order, 'order_placed', 'test', 'Test');

        $this->assertSame(0, PartnerWebhookEventRecord::count());
    }

    public function test_a_b2c_order_produces_no_event_at_all(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $this->makeSubscription($client);

        $vehicle = Vehicle::factory()->create(['license_plate' => 'B-C 1']);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'order_requested',
        ]);

        app(TransitionOrderStatus::class)($order, 'order_placed', 'test', 'Test');

        $this->assertSame(0, PartnerWebhookEventRecord::count());
    }

    /**
     * Fan-out scopes on the event's company and nothing else, so a subscription
     * in another company is never even a candidate.
     */
    public function test_an_event_is_never_fanned_out_to_another_companys_subscription(): void
    {
        [$mine] = $this->makeAuthenticatedPartner();
        [$theirs] = $this->makeAuthenticatedPartner(slug: 'other-partner');

        $mySubscription = $this->makeSubscription($mine);
        $theirSubscription = $this->makeSubscription($theirs);

        $order = $this->makeOrderFor($mine->b2b_id);
        app(TransitionOrderStatus::class)($order, 'order_placed', 'test', 'Test');

        $event = PartnerWebhookEventRecord::where('type', 'order.status_changed')->firstOrFail();
        (new FanOutPartnerWebhookEvent($event->id))->handle();

        $this->assertSame(
            [$mySubscription->id],
            PartnerWebhookDelivery::pluck('partner_webhook_subscription_id')->all(),
        );
        $this->assertDatabaseMissing('partner_webhook_deliveries', [
            'partner_webhook_subscription_id' => $theirSubscription->id,
        ]);
    }

    /**
     * Fan-out is at-least-once at the queue and exactly-once per pair. Running
     * it twice — which the crash-recovery sweeper does — must not double a
     * partner's deliveries.
     */
    public function test_running_fan_out_twice_does_not_duplicate_a_delivery(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $this->makeSubscription($client);

        $order = $this->makeOrderFor($client->b2b_id);
        app(TransitionOrderStatus::class)($order, 'order_placed', 'test', 'Test');

        $event = PartnerWebhookEventRecord::where('type', 'order.status_changed')->firstOrFail();

        (new FanOutPartnerWebhookEvent($event->id))->handle();
        (new FanOutPartnerWebhookEvent($event->id))->handle();

        $this->assertSame(1, PartnerWebhookDelivery::count());
    }

    public function test_creating_an_order_records_an_order_created_event(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $this->makeSubscription($client);

        $company = $this->shimCompany($client->b2b_id);
        $owner = $this->makeOwner($company);
        $created = $this->makeB2bVehicle($company, ['license_plate' => 'B-XY 999']);

        app(OrderService::class)->createB2bCollectionOrder(
            ShimVehicle::where('vehicle_id', $created->vehicle_id)->firstOrFail(),
            $owner,
            [],
        );

        $event = PartnerWebhookEventRecord::where('type', 'order.created')->firstOrFail();

        $this->assertSame('B-XY 999', $event->payload['order']['vehicle']['license_plate']);
        $this->assertNotNull($event->payload['order']['reference']);
    }

    /**
     * §16's whole point: an internal note is internal. The collection row is
     * the one place an internal string sits next to data partners do receive,
     * so the appointment events are where a leak would show up first.
     */
    public function test_a_collection_event_carries_the_appointment_and_never_the_internal_note(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $this->makeSubscription($client);

        $company = $this->shimCompany($client->b2b_id);
        $admin = $this->makeAdmin();
        $created = $this->makeB2bVehicle($company);
        $vehicle = ShimVehicle::where('vehicle_id', $created->vehicle_id)->firstOrFail();
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $created->vehicle_id,
            'order_status' => 'confirmed',
        ]);

        $collection = app(OrderCollectionService::class);
        $confirmed = now()->addDays(3)->toDateString();

        $collection->updateByAdmin($order, $vehicle, $admin, [
            'confirmed_collection_date' => $confirmed,
            'internal_note' => 'Fahrer kennt das Tor nicht — Schlüssel bei Meier.',
        ]);

        $event = PartnerWebhookEventRecord::where('type', 'order.collection_confirmed')->firstOrFail();
        $encoded = json_encode($event->payload);

        $this->assertSame($confirmed, $event->payload['collection']['confirmed_date']);
        $this->assertStringNotContainsString('internal_note', $encoded);
        $this->assertStringNotContainsString('Meier', $encoded);

        // Moving a confirmed date is a different event, and it carries the one
        // the partner may already have acted on.
        $moved = now()->addDays(5)->toDateString();
        $collection->updateByAdmin($order, $vehicle, $admin, ['confirmed_collection_date' => $moved]);

        $rescheduled = PartnerWebhookEventRecord::where('type', 'order.collection_rescheduled')->firstOrFail();

        $this->assertSame($moved, $rescheduled->payload['collection']['confirmed_date']);
        $this->assertSame($confirmed, $rescheduled->payload['collection']['previous_date']);
    }

    /**
     * The snapshot is what makes the offer payload worth carrying at all: a
     * partner who receives `offer.accepted` and re-reads the offer a week later
     * must see the same figures, and a webhook retried after an appraisal edit
     * must resend what was accepted, not what the positions now say.
     */
    public function test_an_offer_event_carries_the_frozen_snapshot_and_no_workshop_data(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $this->makeSubscription($client);

        $company = $this->shimCompany($client->b2b_id);
        $created = $this->makeB2bVehicle($company);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $created->vehicle_id,
            'order_status' => 'inspected',
        ]);

        $offer = LeasybackOffer::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_sequence' => 1,
            'offer_status' => 'published',
            'repair_cost_net' => '820.00',
            'depreciation_value_net' => '0',
            'workshop_repair_quote_net' => '0',
            'missing_parts_cost_net' => '0',
        ]);

        $presentation = B2bOfferPresentation::create([
            'offer_id' => $offer->offer_id,
            'order_id' => $order->id,
            'workshop_quotation_id' => null,
            'lines' => [[
                'component' => 'Stoßfänger vorne',
                'appraisal_amount_net' => '400.00',
                'repair_amount_net' => '260.00',
                'saving_net' => '140.00',
            ]],
            'appraisal_total_net' => '1200.00',
            'repair_total_net' => '820.00',
            'saving_net' => '380.00',
            'presented_at' => now(),
        ]);

        app(B2bOfferService::class)->announceOffer('published', $offer);

        $event = PartnerWebhookEventRecord::where('type', 'offer.published')->firstOrFail();

        $this->assertSame('820.00', $event->payload['offer']['totals']['repair_total_net']);
        $this->assertSame('Stoßfänger vorne', $event->payload['offer']['positions'][0]['component']);
        $this->assertStringNotContainsString('workshop_quotation_id', json_encode($event->payload));

        // The snapshot moves; the recorded event does not.
        $presentation->update(['repair_total_net' => '9999.00']);

        $this->assertSame(
            '820.00',
            PartnerWebhookEventRecord::find($event->id)->payload['offer']['totals']['repair_total_net'],
        );
    }

    /**
     * `offer.expired` has no button behind it, so the sweeper is its writer —
     * and `expired_notified_at` is what makes it fire once.
     */
    public function test_an_expired_offer_emits_exactly_one_event(): void
    {
        [$client] = $this->makeAuthenticatedPartner();
        $this->makeSubscription($client);

        $company = $this->shimCompany($client->b2b_id);
        $created = $this->makeB2bVehicle($company);
        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $created->vehicle_id,
            'order_status' => 'inspected',
        ]);

        $offer = LeasybackOffer::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_sequence' => 1,
            'offer_status' => 'published',
            'repair_cost_net' => '100.00',
            'depreciation_value_net' => '0',
            'workshop_repair_quote_net' => '0',
            'missing_parts_cost_net' => '0',
        ]);

        B2bOfferPresentation::create([
            'offer_id' => $offer->offer_id,
            'order_id' => $order->id,
            'lines' => [],
            'appraisal_total_net' => '100.00',
            'repair_total_net' => '100.00',
            'saving_net' => '0.00',
            'valid_until' => now()->subDay()->toDateString(),
            'presented_at' => now()->subWeek(),
        ]);

        $this->artisan('partner:webhooks:emit-expired-offers')->assertSuccessful();
        $this->artisan('partner:webhooks:emit-expired-offers')->assertSuccessful();

        $this->assertSame(1, PartnerWebhookEventRecord::where('type', 'offer.expired')->count());
    }

    /**
     * BuildsB2bCompanies type-hints the `App\Models` shim, and a partner
     * client's `company` relation returns the canonical model — the shim's
     * parent, which does not satisfy the hint. Re-loaded rather than cast.
     */
    private function shimCompany(string $companyId): ShimB2B
    {
        return ShimB2B::where('b2b_id', $companyId)->firstOrFail();
    }

    private function makeOrderFor(string $companyId, string $status = 'order_requested'): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->forB2b($companyId)->create();

        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
            'leasyback_partner' => 'leasyback',
            'request_payload' => ['order_type' => 'b2b_collection'],
        ]);

        OrderLogistics::firstOrCreate(['auftragsnummer' => $order->auftragsnummer]);

        return $order;
    }
}
