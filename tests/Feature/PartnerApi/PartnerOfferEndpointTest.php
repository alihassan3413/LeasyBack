<?php

namespace Tests\Feature\PartnerApi;

use App\Enums\OrderStatus;
use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerOrderHistory;
use Tests\TestCase;

class PartnerOfferEndpointTest extends TestCase
{
    use BuildsPartnerClients;
    use BuildsPartnerOrderHistory;
    use RefreshDatabase;

    public function test_a_published_offer_is_returned_with_its_positions_and_totals(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        [$offer] = $this->makePresentedOffer($order, 'published');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.offers.index', $order->id))
            ->assertOk()
            ->assertJsonCount(1, 'data.offers')
            ->assertJsonPath('data.offers.0.id', $offer->offer_id)
            ->assertJsonPath('data.offers.0.status', 'published')
            ->assertJsonPath('data.offers.0.is_accepted', false)
            ->assertJsonPath('data.offers.0.is_expired', false)
            ->assertJsonPath('data.offers.0.currency', 'EUR')
            ->assertJsonPath('data.offers.0.totals.appraisal_total_net', '400.00')
            ->assertJsonPath('data.offers.0.totals.repair_total_net', '260.00')
            ->assertJsonPath('data.offers.0.totals.saving_net', '140.00')
            ->assertJsonCount(1, 'data.offers.0.positions')
            ->assertJsonPath('data.offers.0.positions.0.component', 'Stoßfänger vorne')
            ->assertJsonPath('data.offers.0.positions.0.repair_amount_net', '260.00')
            ->assertJsonPath('data.offers.0.positions.0.not_repairable', false);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.offers.show', $offer->offer_id))
            ->assertOk()
            ->assertJsonPath('data.offer.id', $offer->offer_id)
            ->assertJsonPath('data.offer.order.reference', $order->auftragsnummer);
    }

    public function test_an_offer_carries_no_workshop_comparison_or_gross_amounts(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);

        $quotation = WorkshopQuotation::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'token_hash' => hash('sha256', 'quotation-token'),
            'workshop_label' => 'Werkstatt Nord',
            'company_name' => 'Karosserie Nord GmbH',
            'total_net' => '260.00',
            'expires_at' => now()->addWeek(),
            'submitted_at' => now()->subDays(3),
        ]);
        [$offer] = $this->makePresentedOffer($order, 'published', [
            'workshop_quotation_id' => $quotation->id,
        ]);

        $body = $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.offers.show', $offer->offer_id))
            ->assertOk()
            ->getContent();

        // Which workshops were asked, what each quoted, and which one won is
        // Leasyback's procurement — §9 also forbids gross anywhere in it.
        $this->assertStringNotContainsString($quotation->id, $body);
        $this->assertStringNotContainsString('workshop_quotation_id', $body);
        $this->assertStringNotContainsString('gross', $body);
        $this->assertStringNotContainsString('workshop', $body);
        // Internal keys on a snapshot line are not partner data either.
        $this->assertStringNotContainsString('appraisal_position_id', $body);
        $this->assertStringNotContainsString('damage_image_document_ids', $body);
        $this->assertStringNotContainsString('secret-image-id', $body);
    }

    public function test_a_draft_offer_is_never_visible(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        [$draft] = $this->makePresentedOffer($order, 'draft');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.offers.index', $order->id))
            ->assertOk()
            ->assertJsonCount(0, 'data.offers');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.offers.show', $draft->offer_id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'offer_not_found');
    }

    public function test_a_cancelled_offer_is_never_visible(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        [$cancelled] = $this->makePresentedOffer($order, 'cancelled');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.offers.show', $cancelled->offer_id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'offer_not_found');
    }

    public function test_an_accepted_offer_reports_the_frozen_snapshot(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::WorkshopCommissioned);
        [$offer] = $this->makePresentedOffer($order, 'selected');

        // The live appraisal position is edited after acceptance. §10 requires
        // the presented figures to survive that untouched.
        AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'component' => 'Stoßfänger vorne',
            'damage_description' => 'Kratzer, jetzt teurer',
            'original_amount_net' => '9999.00',
            'sort_order' => 1,
        ]);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.offers.show', $offer->offer_id))
            ->assertOk()
            ->assertJsonPath('data.offer.status', 'selected')
            ->assertJsonPath('data.offer.is_accepted', true)
            ->assertJsonPath('data.offer.totals.appraisal_total_net', '400.00')
            ->assertJsonPath('data.offer.positions.0.appraisal_amount_net', '400.00')
            ->assertJsonMissingPath('data.offer.positions.0.original_amount_net');

        $this->assertSame('9999.00', (string) AppraisalPosition::where('order_id', $order->id)->value('original_amount_net'));
    }

    public function test_a_rejected_offer_stays_visible_with_its_comment(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        [$offer] = $this->makePresentedOffer($order, 'rejected', [
            'rejected_at' => now()->subHours(3),
            'customer_comment' => 'Zu teuer.',
        ]);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.offers.show', $offer->offer_id))
            ->assertOk()
            ->assertJsonPath('data.offer.status', 'rejected')
            ->assertJsonPath('data.offer.is_rejected', true)
            ->assertJsonPath('data.offer.customer_comment', 'Zu teuer.');
    }

    public function test_an_expired_offer_is_still_returned_but_flagged(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        [$offer] = $this->makePresentedOffer($order, 'published', [
            'valid_until' => now()->subDays(2)->toDateString(),
        ]);

        // Expiry is a fact about the offer, not a reason to hide it: the
        // partner needs to see why nothing is happening.
        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.offers.show', $offer->offer_id))
            ->assertOk()
            ->assertJsonPath('data.offer.is_expired', true)
            ->assertJsonPath('data.offer.valid_until', now()->subDays(2)->toDateString());
    }

    public function test_an_offer_valid_through_today_is_not_expired(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        [$offer] = $this->makePresentedOffer($order, 'published', [
            'valid_until' => now()->toDateString(),
        ]);

        // An offer is good for the whole of its last day (§10.1).
        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.offers.show', $offer->offer_id))
            ->assertOk()
            ->assertJsonPath('data.offer.is_expired', false);
    }

    public function test_an_offer_without_a_presentation_row_is_not_exposed(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        [$offer] = $this->makePresentedOffer($order, 'published');

        B2bOfferPresentation::where('offer_id', $offer->offer_id)->delete();

        // No presentation row means it was never a B2B customer offer — a
        // B2C-shaped offer has none, and this is what keeps one out.
        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.offers.index', $order->id))
            ->assertOk()
            ->assertJsonCount(0, 'data.offers');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.offers.show', $offer->offer_id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'offer_not_found');
    }

    public function test_another_companys_offer_is_not_found(): void
    {
        $other = $this->makePartnerCompany('Fremde GmbH');
        $foreignOrder = $this->makeB2bOrder($other->b2b_id, OrderStatus::Inspected);
        [$foreignOffer] = $this->makePresentedOffer($foreignOrder, 'published');

        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.offers.show', $foreignOffer->offer_id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'offer_not_found');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.offers.index', $foreignOrder->id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'order_not_found');
    }

    public function test_offers_cannot_be_accepted_or_rejected_through_this_api(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        [$offer] = $this->makePresentedOffer($order, 'published');

        // Phase 3 is read-only. Acceptance commissions a workshop and moves
        // money; it stays in the portal until it is deliberately opened up.
        foreach (['postJson', 'patchJson'] as $method) {
            $this->withHeaders($this->bearer($token))
                ->{$method}(route('partner.v1.offers.show', $offer->offer_id), [])
                ->assertStatus(405)
                ->assertJsonPath('error.code', 'method_not_allowed');
        }

        $this->assertSame('published', $offer->fresh()->offer_status);
    }

    public function test_offers_require_the_offers_scope_and_the_company_permission(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner(
            abilities: [PartnerAbility::ReadOrders->value],
        );
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        [$offer] = $this->makePresentedOffer($order, 'published');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.offers.show', $offer->offer_id))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope')
            ->assertJsonPath('error.details.required_ability', 'offers.read');

        $full = $this->issueToken($client)->plainTextToken;
        $this->setCompanyPermissions($client, ['company.view']);

        $this->withHeaders($this->bearer($full))
            ->getJson(route('partner.v1.orders.offers.index', $order->id))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_company_permission');
    }
}
