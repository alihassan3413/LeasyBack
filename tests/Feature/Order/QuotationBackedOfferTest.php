<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\B2B;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Services\PartnerOfferAnnouncer;
use App\Modules\UserProfile\Order\Services\RepairOfferService;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Support\OfferPricingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Quotation-backed customer offers, in both channels.
 *
 * Until now a B2C offer was four numbers an admin typed. The workshop that
 * would do the work, the damage those numbers covered and the price it had
 * actually quoted existed in the database and were connected to the offer by
 * nothing at all. This is the join: one offer, one quotation, one frozen record
 * of what the customer agreed to.
 *
 * The one thing that still differs by channel is gross — §9 keeps a company on
 * net, a private customer pays and sees the gross — and that lives in
 * OfferPricingPolicy rather than in a check per layer.
 */
class QuotationBackedOfferTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    // ------------------------------------------------------------- creation

    public function test_admin_builds_a_b2c_offer_from_a_submitted_quotation(): void
    {
        $order = $this->b2cOrder(['500.00', '300.00']);
        $quotation = $this->quotedBy($order, ['400.00', '250.00'], 'Karosserie Meier GmbH');

        $this->createOffer($order, $quotation)->assertRedirect();

        $offer = LeasybackOffer::where('order_id', $order->id)->sole();
        $presentation = B2bOfferPresentation::where('offer_id', $offer->offer_id)->sole();

        $this->assertSame('draft', $offer->offer_status);
        $this->assertSame($quotation->id, $presentation->workshop_quotation_id);
        $this->assertSame('650.00', (string) $presentation->repair_total_net);
        $this->assertSame('800.00', (string) $presentation->appraisal_total_net);
        $this->assertSame('150.00', (string) $presentation->saving_net);
        $this->assertCount(2, $presentation->lines);
    }

    public function test_an_unsubmitted_quotation_cannot_become_an_offer(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $quotation = $this->invite($order);

        $this->createOffer($order, $quotation)->assertSessionHasErrors('offer');

        $this->assertSame(0, LeasybackOffer::count());
    }

    public function test_a_revoked_quotation_cannot_become_an_offer(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $quotation = $this->quotedBy($order, ['400.00']);
        $quotation->update(['revoked_at' => now()]);

        $this->createOffer($order, $quotation)->assertSessionHasErrors('offer');

        $this->assertSame(0, LeasybackOffer::count());
    }

    public function test_another_orders_quotation_cannot_be_used(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $foreign = $this->quotedBy($this->b2cOrder(['900.00']), ['700.00']);

        $this->createOffer($order, $foreign)->assertSessionHasErrors('offer');

        $this->assertSame(0, LeasybackOffer::where('order_id', $order->id)->count());
    }

    public function test_another_vehicles_quotation_cannot_be_used(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $foreign = $this->quotedBy($this->b2bOrder(['900.00']), ['700.00']);

        $this->createOffer($order, $foreign)->assertSessionHasErrors('offer');

        $this->assertSame(0, LeasybackOffer::where('order_id', $order->id)->count());
    }

    public function test_a_tampered_quotation_id_is_refused(): void
    {
        $order = $this->b2cOrder(['500.00']);

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.b2b-offer.store', $order->id), ['workshop_quotation_id' => (string) Str::uuid()])
            ->assertSessionHasErrors('offer');

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.b2b-offer.store', $order->id), ['workshop_quotation_id' => 'not-a-uuid'])
            ->assertSessionHasErrors('workshop_quotation_id');

        $this->assertSame(0, LeasybackOffer::count());
    }

    public function test_a_customer_cannot_build_an_offer(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $quotation = $this->quotedBy($order, ['400.00']);

        $this->actingAs(User::factory()->create(['user_type' => UserType::Privatkunde]))
            ->post(route('admin.orders.b2b-offer.store', $order->id), ['workshop_quotation_id' => $quotation->id])
            ->assertForbidden();

        $this->assertSame(0, LeasybackOffer::count());
    }

    public function test_an_offer_references_exactly_one_quotation(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $cheap = $this->quotedBy($order, ['300.00'], 'Günstig GmbH');
        $dear = $this->quotedBy($order, ['450.00'], 'Teuer GmbH');

        $this->createOffer($order, $cheap);

        $presentation = B2bOfferPresentation::sole();
        $this->assertSame($cheap->id, $presentation->workshop_quotation_id);
        $this->assertNotSame($dear->id, $presentation->workshop_quotation_id);
        $this->assertSame('Günstig GmbH', $presentation->workshopName());
    }

    // ---------------------------------------------------------------- gross

    public function test_a_b2c_offer_carries_a_correctly_derived_gross_total(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['100.05']));

        $offer = LeasybackOffer::sole();

        // 100.05 × 1.19 = 119.0595. Half up to the cent is 119.06; truncating —
        // which is what bcmul does on its own — would have said 119.05.
        $this->assertSame('100.05', (string) $offer->final_total_net);
        $this->assertSame('119.06', (string) $offer->final_total_gross);
        $this->assertSame('119.06', OfferPricingPolicy::gross('100.05', '0.19'));
        $this->assertSame('1000.00', OfferPricingPolicy::gross('840.34', '0.19'));
    }

    public function test_the_b2c_customer_payload_carries_both_units_consistently(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['800.00']));

        $presentation = $this->customerOffer($order)['presentation'];

        $this->assertSame('0.1900', $presentation['vat_rate']);
        $this->assertSame('800.00', $presentation['repair_total_net']);
        $this->assertSame('952.00', $presentation['repair_total_gross']);
        $this->assertSame('1000.00', $presentation['appraisal_total_net']);
        $this->assertSame('1190.00', $presentation['appraisal_total_gross']);
        $this->assertSame('200.00', $presentation['saving_net']);
        // The saving holds in both units: 1190.00 − 952.00 = 238.00.
        $this->assertSame('238.00', $presentation['saving_gross']);
        $this->assertSame('952.00', $presentation['lines'][0]['repair_amount_gross']);
    }

    public function test_a_b2b_offer_stays_net_only(): void
    {
        $order = $this->b2bOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['800.00']));

        $offer = LeasybackOffer::sole();
        $presentation = B2bOfferPresentation::sole();

        $this->assertNull($presentation->vat_rate);
        $this->assertSame('0.00', (string) $offer->final_total_gross);

        $customerOffer = $this->customerOffer($order);
        foreach (['final_total_gross', 'repair_cost_gross'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $customerOffer, "B2B payload must not carry [{$forbidden}]");
        }

        foreach (['vat_rate', 'repair_total_gross', 'saving_gross'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $customerOffer['presentation']);
        }

        $this->assertSame('800.00', $customerOffer['presentation']['repair_total_net']);
    }

    // ----------------------------------------------------------- immutability

    public function test_editing_positions_after_publication_does_not_move_the_offer(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['800.00']));

        AppraisalPosition::where('order_id', $order->id)->update(['original_amount_net' => '9999.00']);
        AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'sort_order' => 9,
            'component' => 'Nachträglich entdeckt',
            'original_amount_net' => '4000.00',
            'source' => AppraisalPosition::SOURCE_MANUAL,
        ]);

        $presentation = B2bOfferPresentation::sole();
        $this->assertCount(1, $presentation->lines);
        $this->assertSame('1000.00', (string) $presentation->appraisal_total_net);
        $this->assertSame('800.00', (string) $presentation->repair_total_net);
        $this->assertSame('952.00', (string) LeasybackOffer::sole()->final_total_gross);
    }

    public function test_changing_the_quotation_after_publication_does_not_move_the_offer(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $quotation = $this->quotedBy($order, ['800.00'], 'Erste Werkstatt GmbH');
        $this->publishOffer($order, $quotation);

        $quotation->update(['company_name' => 'Umbenannt GmbH', 'total_net' => '10.00']);
        DB::table('b2b_workshop_quotation_items')->update(['amount_net' => '10.00']);

        $presentation = B2bOfferPresentation::sole()->fresh();
        $this->assertSame('Erste Werkstatt GmbH', $presentation->workshopName());
        $this->assertSame('800.00', (string) $presentation->repair_total_net);
        $this->assertSame('800.00', $presentation->lines[0]['repair_amount_net']);
    }

    /**
     * The rate is copied onto the row precisely so a later change to the
     * configured rate cannot restate a price the customer already saw.
     */
    public function test_changing_the_configured_vat_rate_does_not_move_a_published_offer(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['800.00']));

        config(['offers.vat_rate' => '0.25']);

        $this->assertSame('0.1900', (string) B2bOfferPresentation::sole()->vat_rate);
        $this->assertSame('952.00', $this->customerOffer($order)['presentation']['repair_total_gross']);
        $this->assertSame('952.00', (string) LeasybackOffer::sole()->final_total_gross);
    }

    public function test_deleting_the_source_quotation_leaves_the_workshop_traceable(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $quotation = $this->quotedBy($order, ['800.00'], 'Verschwunden GmbH');
        $this->publishOffer($order, $quotation);

        WorkshopQuotation::whereKey($quotation->id)->delete();

        $presentation = B2bOfferPresentation::sole()->fresh();
        $this->assertNull($presentation->workshop_quotation_id, 'the live FK is expected to null out');
        $this->assertSame('Verschwunden GmbH', $presentation->workshopName());
        $this->assertSame('800.00', (string) $presentation->repair_total_net);
    }

    /**
     * Publishing is the cut-off, not offer creation: an admin correcting a
     * position on a draft is fixing a mistake, and that has to reach the
     * snapshot. A second publish attempt cannot re-freeze it.
     */
    public function test_the_snapshot_is_taken_at_publish_and_only_once(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $quotation = $this->quotedBy($order, ['800.00']);
        $this->createOffer($order, $quotation);

        AppraisalPosition::where('order_id', $order->id)->update(['chargeable_amount_net' => '1200.00']);

        $offer = LeasybackOffer::sole();
        $this->publish($offer);

        $presentation = B2bOfferPresentation::sole();
        $presentedAt = $presentation->presented_at;
        $this->assertSame('1200.00', (string) $presentation->appraisal_total_net, 'a draft correction must reach the snapshot');

        app(RepairOfferService::class)->snapshotOnPublish($offer->fresh());

        $this->assertEquals($presentedAt, B2bOfferPresentation::sole()->presented_at);
    }

    // ------------------------------------------------------- customer payload

    public function test_the_b2c_customer_sees_the_offer_with_workshop_lines_and_validity(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['800.00'], 'Lack & Karosserie GmbH'), [
            'valid_until' => now()->addWeek()->toDateString(),
            'customer_note' => 'Ersatzwagen inklusive.',
        ]);

        $presentation = $this->customerOffer($order)['presentation'];

        $this->assertSame('Lack & Karosserie GmbH', $presentation['workshop_name']);
        $this->assertSame(now()->addWeek()->toDateString(), $presentation['valid_until']);
        $this->assertFalse($presentation['is_expired']);
        $this->assertSame('Ersatzwagen inklusive.', $presentation['customer_note']);
        $this->assertNotNull($presentation['presented_at']);
        $this->assertSame('Bauteil 0', $presentation['lines'][0]['component']);
    }

    public function test_the_customer_payload_withholds_the_workshops_contact_details(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['800.00']));

        $encoded = (string) json_encode($this->customerOffer($order));

        foreach (['kontakt@werkstatt.test', 'Kontakt Person', '+49 30 123456'] as $internal) {
            $this->assertStringNotContainsString($internal, $encoded, "customer payload leaked [{$internal}]");
        }
    }

    public function test_a_manual_offer_reaches_the_customer_with_no_presentation(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->manualOffer($order);

        $this->assertNull($this->customerOffer($order)['presentation']);
    }

    // ------------------------------------------------------- accept / reject

    public function test_a_b2c_customer_accepts_a_quotation_backed_offer(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['800.00'], 'Gewinner GmbH'));
        $offer = LeasybackOffer::sole();

        $this->actingAs($this->ownerOf($order))
            ->from('/dashboard')
            ->post(route('offers.select', $offer->offer_id))
            ->assertRedirect();

        $this->assertSame('selected', $offer->fresh()->offer_status);
        // Workshop provenance is what makes the acceptance actionable.
        $this->assertSame('Gewinner GmbH', B2bOfferPresentation::sole()->workshopName());
    }

    public function test_a_b2c_customer_rejects_a_quotation_backed_offer_with_a_comment(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['800.00']));
        $offer = LeasybackOffer::sole();

        $this->actingAs($this->ownerOf($order))
            ->from('/dashboard')
            ->post(route('offers.reject', $offer->offer_id), ['customer_comment' => 'Zu teuer.'])
            ->assertRedirect();

        $presentation = B2bOfferPresentation::sole();
        $this->assertSame('rejected', $offer->fresh()->offer_status);
        $this->assertNotNull($presentation->rejected_at);
        $this->assertSame('Zu teuer.', $presentation->customer_comment);
    }

    public function test_a_rejected_offer_can_no_longer_be_accepted(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['800.00']));
        $offer = LeasybackOffer::sole();
        $owner = $this->ownerOf($order);

        $this->actingAs($owner)->from('/dashboard')->post(route('offers.reject', $offer->offer_id));
        $this->actingAs($owner)->from('/dashboard')->post(route('offers.select', $offer->offer_id))
            ->assertSessionHasErrors('offer');

        $this->assertSame('rejected', $offer->fresh()->offer_status);
    }

    public function test_a_stranger_can_neither_accept_nor_reject(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['800.00']));
        $offer = LeasybackOffer::sole();
        $stranger = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($stranger)->post(route('offers.select', $offer->offer_id))->assertNotFound();
        $this->actingAs($stranger)->post(route('offers.reject', $offer->offer_id))->assertNotFound();

        $this->assertSame('published', $offer->fresh()->offer_status);
    }

    /**
     * Fix 02's invariant, re-asserted now that a B2C order can hold two real
     * quotation-backed offers at once — the case it was written for and could
     * not previously be exercised in this channel.
     */
    public function test_only_one_of_two_quotation_backed_b2c_offers_can_be_accepted(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['800.00'], 'A GmbH'));
        $this->publishOffer($order, $this->quotedBy($order, ['600.00'], 'B GmbH'));

        [$first, $second] = LeasybackOffer::orderBy('offer_sequence')->get()->all();
        $owner = $this->ownerOf($order);

        $this->actingAs($owner)->from('/dashboard')->post(route('offers.select', $second->offer_id))->assertRedirect();
        $this->actingAs($owner)->from('/dashboard')->post(route('offers.select', $first->offer_id))
            ->assertSessionHasErrors('offer');

        $this->assertSame('selected', $second->fresh()->offer_status);
        $this->assertSame('closed', $first->fresh()->offer_status);
        $this->assertSame(1, LeasybackOffer::where('offer_status', 'selected')->count());
    }

    public function test_an_expired_b2c_offer_cannot_be_accepted(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['800.00']));
        B2bOfferPresentation::query()->update(['valid_until' => now()->subDay()->toDateString()]);

        $this->actingAs($this->ownerOf($order))
            ->from('/dashboard')
            ->post(route('offers.select', LeasybackOffer::sole()->offer_id))
            ->assertSessionHasErrors('offer');

        $this->assertSame('published', LeasybackOffer::sole()->offer_status);
    }

    // ----------------------------------------------------------- admin payload

    public function test_the_admin_payload_distinguishes_backed_from_manual_offers(): void
    {
        $order = $this->b2cOrder(['1000.00']);
        $this->publishOffer($order, $this->quotedBy($order, ['800.00'], 'Werkstatt Nord GmbH'));
        $this->manualOffer($order, publish: false);

        $offers = collect($this->adminOrder($order)['offers'])->keyBy('offer_sequence');

        $this->assertNotNull($offers[1]['presentation']);
        $this->assertSame('Werkstatt Nord GmbH', $offers[1]['workshop']['company_name']);
        $this->assertSame('kontakt@werkstatt.test', $offers[1]['workshop']['contact_email']);

        $this->assertNull($offers[2]['presentation']);
        $this->assertNull($offers[2]['workshop']);
    }

    // --------------------------------------------------------------- structure

    public function test_no_channel_specific_offer_service_or_table_exists(): void
    {
        $this->publishOffer($this->b2cOrder(['500.00']), null);
        $this->publishOffer($this->b2bOrder(['500.00']), null);

        $this->assertSame(2, DB::table('b2b_offer_presentations')->count());

        foreach ([
            'App\Modules\UserProfile\Order\Services\B2cOfferService',
            'App\Modules\UserProfile\Order\Services\B2bOfferService',
            'App\Models\B2cOfferPresentation',
        ] as $forbidden) {
            $this->assertFalse(class_exists($forbidden), "{$forbidden} must not exist");
        }
    }

    public function test_partner_announcing_is_no_longer_part_of_offer_construction(): void
    {
        $service = new \ReflectionClass(RepairOfferService::class);

        foreach ($service->getMethods() as $method) {
            $this->assertStringNotContainsStringIgnoringCase(
                'webhook',
                $method->getName(),
                'partner fan-out belongs to PartnerOfferAnnouncer',
            );
        }

        $this->assertTrue(class_exists(PartnerOfferAnnouncer::class));
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createOffer(LeasybackOrder $order, ?WorkshopQuotation $quotation, array $extra = []): TestResponse
    {
        return $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.b2b-offer.store', $order->id), [
                'workshop_quotation_id' => $quotation?->id,
                ...$extra,
            ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function publishOffer(LeasybackOrder $order, ?WorkshopQuotation $quotation, array $extra = []): LeasybackOffer
    {
        $quotation ??= $this->quotedBy($order, ['400.00']);

        $this->createOffer($order, $quotation, $extra)->assertRedirect();

        $offer = LeasybackOffer::where('order_id', $order->id)->orderByDesc('offer_sequence')->first();
        $this->publish($offer);

        return $offer->fresh();
    }

    private function publish(LeasybackOffer $offer): void
    {
        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $offer->order_id))
            ->patch(route('admin.orders.offers.publish', $offer->offer_id))
            ->assertRedirect();
    }

    private function manualOffer(LeasybackOrder $order, bool $publish = true): LeasybackOffer
    {
        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.offers.store', $order->id), [
                'repair_cost_net' => '100.00', 'repair_cost_gross' => '119.00',
                'depreciation_value_net' => '0', 'depreciation_value_gross' => '0',
                'workshop_repair_quote_net' => '0', 'workshop_repair_quote_gross' => '0',
                'missing_parts_cost_net' => '0', 'missing_parts_cost_gross' => '0',
            ])
            ->assertRedirect();

        $offer = LeasybackOffer::where('order_id', $order->id)->orderByDesc('offer_sequence')->first();

        if ($publish) {
            $this->publish($offer);
        }

        return $offer->fresh();
    }

    private function invite(LeasybackOrder $order): WorkshopQuotation
    {
        return app(WorkshopQuotationService::class)
            ->invite($order, $this->makeAdmin(), ['workshop_label' => 'Werkstatt'])['quotation'];
    }

    /**
     * @param  array<int, string>  $amounts  One net amount per position, in order.
     */
    private function quotedBy(LeasybackOrder $order, array $amounts, string $company = 'Werkstatt GmbH'): WorkshopQuotation
    {
        $quotation = $this->invite($order);
        $positions = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->get();

        app(WorkshopQuotationService::class)->submit($quotation, [
            'company_name' => $company,
            'contact_person' => 'Kontakt Person',
            'contact_email' => 'kontakt@werkstatt.test',
            'contact_phone' => '+49 30 123456',
            'items' => $positions
                ->map(fn (AppraisalPosition $position, int $index) => [
                    'appraisal_position_id' => $position->id,
                    'amount_net' => $amounts[$index] ?? null,
                ])
                ->all(),
        ]);

        return $quotation->fresh();
    }

    /**
     * The payload shape both offer panels resolve from, pinned because the
     * order of this list is a trap rather than information.
     *
     * A customer keeps seeing an offer they rejected, and offers arrive sorted
     * by `offer_sequence` — so once someone rejects one and accepts the next,
     * the rejected offer is *first* and the accepted one is second. Anything
     * that picks "the decided offer" by position rather than by status lands on
     * the wrong one: the customer panel showed the rejected offer, its workshop
     * and its prices, and Admin's repair-appointment form was seeded with the
     * losing workshop's dates.
     *
     * Both now resolve by status. If this ordering is ever changed, that is a
     * deliberate decision and this test is where it gets noticed.
     */
    public function test_a_rejected_offer_precedes_the_accepted_one_in_the_payload(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $owner = $this->ownerOf($order);

        $rejected = $this->publishOffer($order, $this->quotedBy($order, ['400.00'], 'Abgelehnt GmbH'));
        $this->actingAs($owner)->from('/dashboard')
            ->post(route('offers.reject', $rejected->offer_id))
            ->assertSessionHasNoErrors();

        $accepted = $this->publishOffer($order, $this->quotedBy($order, ['300.00'], 'Angenommen GmbH'));
        $this->actingAs($owner)->from('/dashboard')
            ->post(route('offers.select', $accepted->offer_id))
            ->assertSessionHasNoErrors();

        $offers = $this->customerOffers($order);

        $this->assertCount(2, $offers, 'the customer keeps seeing both offers');
        $this->assertSame(['rejected', 'selected'], array_column($offers, 'offer_status'));

        // The accepted offer is the second entry — resolving by position would
        // reach for the rejected one.
        $this->assertSame($accepted->offer_id, $offers[1]['offer_id']);
        $this->assertSame('Angenommen GmbH', $offers[1]['presentation']['workshop_name']);
        $this->assertSame('Abgelehnt GmbH', $offers[0]['presentation']['workshop_name']);

        $this->assertCount(1, array_filter($offers, fn (array $offer) => $offer['offer_status'] === 'selected'));
    }

    /**
     * Every customer-visible offer on this order, in payload order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function customerOffers(LeasybackOrder $order): array
    {
        $page = $this->actingAs($this->ownerOf($order))->get('/dashboard')->viewData('page');

        foreach ($page['props']['vehicles'] ?? [] as $vehicle) {
            foreach ($vehicle['orders'] ?? [] as $payloadOrder) {
                if (($payloadOrder['id'] ?? null) === $order->id) {
                    return json_decode((string) json_encode($payloadOrder['offers'] ?? []), true);
                }
            }
        }

        $this->fail('order not found in the customer payload');
    }

    /**
     * @return array<string, mixed>
     */
    private function customerOffer(LeasybackOrder $order): array
    {
        $page = $this->actingAs($this->ownerOf($order))->get('/dashboard')->viewData('page');

        foreach ($page['props']['vehicles'] ?? [] as $vehicle) {
            foreach ($vehicle['orders'] ?? [] as $payloadOrder) {
                foreach ($payloadOrder['offers'] ?? [] as $offer) {
                    if ($payloadOrder['id'] === $order->id) {
                        return $offer;
                    }
                }
            }
        }

        $this->fail('no customer-visible offer found for this order');
    }

    /**
     * @return array<string, mixed>
     */
    private function adminOrder(LeasybackOrder $order): array
    {
        return json_decode((string) json_encode(
            $this->actingAs($this->makeAdmin())
                ->get(route('admin.orders.show', $order->id))
                ->viewData('page')['props']['order'],
        ), true);
    }

    private function ownerOf(LeasybackOrder $order): User
    {
        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->sole();

        if ($vehicle->b2c_user_id !== null) {
            return User::findOrFail($vehicle->b2c_user_id);
        }

        return $this->makeOwner($this->companiesByVehicle[$vehicle->vehicle_id]);
    }

    /** @var array<string, B2B> */
    private array $companiesByVehicle = [];

    /**
     * @param  array<int, string>  $appraisalAmounts
     */
    private function b2cOrder(array $appraisalAmounts): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => User::factory()->create(['user_type' => UserType::Privatkunde])->id,
        ]);

        return $this->withPositions(LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'inspected',
        ]), $appraisalAmounts);
    }

    /**
     * @param  array<int, string>  $appraisalAmounts
     */
    private function b2bOrder(array $appraisalAmounts): LeasybackOrder
    {
        $company = $this->makeCompany(fake()->unique()->company());
        $vehicle = $this->makeB2bVehicle($company);
        $this->companiesByVehicle[$vehicle->vehicle_id] = $company;

        return $this->withPositions($this->makeB2bOrder($vehicle, 'inspected'), $appraisalAmounts);
    }

    /**
     * @param  array<int, string>  $appraisalAmounts
     */
    private function withPositions(LeasybackOrder $order, array $appraisalAmounts): LeasybackOrder
    {
        foreach ($appraisalAmounts as $index => $amount) {
            AppraisalPosition::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'sort_order' => $index,
                'component' => "Bauteil {$index}",
                'damage_description' => 'Beschädigung',
                'original_amount_net' => $amount,
                'source' => AppraisalPosition::SOURCE_MANUAL,
            ]);
        }

        return $order;
    }
}
