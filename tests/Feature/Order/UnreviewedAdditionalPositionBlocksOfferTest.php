<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopAdditionalPosition;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Models\WorkshopQuotationItem;
use App\Modules\UserProfile\Order\Services\WorkshopAdditionalPositionReviewService;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Damage a workshop reported on its own is not an appraisal position until an
 * admin accepts it. Building a customer offer before that decision would quote
 * the customer a set of positions that is about to change, so the offer route
 * refuses until every additional damage on that quotation has been reviewed.
 */
class UnreviewedAdditionalPositionBlocksOfferTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unreviewed_additional_damage_blocks_the_offer(): void
    {
        [$order, $quotation] = $this->submittedQuotation();
        $this->additional($quotation, WorkshopAdditionalPosition::STATUS_PENDING);

        $this->createOffer($order, $quotation)->assertSessionHasErrors('offer');

        $this->assertSame(0, LeasybackOffer::where('order_id', $order->id)->count());
    }

    public function test_the_refusal_names_how_many_are_waiting(): void
    {
        [$order, $quotation] = $this->submittedQuotation();
        $this->additional($quotation, WorkshopAdditionalPosition::STATUS_PENDING);
        $this->additional($quotation, WorkshopAdditionalPosition::STATUS_PENDING, 'Kotflügel');

        $errors = $this->createOffer($order, $quotation)->assertSessionHasErrors('offer')->getSession()->get('errors');

        $this->assertStringContainsString('2 zusätzliche Schäden', $errors->first('offer'));
    }

    public function test_one_waiting_damage_is_phrased_in_the_singular(): void
    {
        [$order, $quotation] = $this->submittedQuotation();
        $this->additional($quotation, WorkshopAdditionalPosition::STATUS_PENDING);

        $errors = $this->createOffer($order, $quotation)->assertSessionHasErrors('offer')->getSession()->get('errors');

        $this->assertStringContainsString('einen zusätzlichen Schaden', $errors->first('offer'));
    }

    public function test_an_accepted_damage_no_longer_blocks_the_offer(): void
    {
        [$order, $quotation] = $this->submittedQuotation();
        $this->additional($quotation, WorkshopAdditionalPosition::STATUS_ACCEPTED);

        $this->createOffer($order, $quotation)->assertSessionHasNoErrors();

        $this->assertSame(1, LeasybackOffer::where('order_id', $order->id)->count());
    }

    public function test_a_rejected_damage_no_longer_blocks_the_offer(): void
    {
        [$order, $quotation] = $this->submittedQuotation();
        $this->additional($quotation, WorkshopAdditionalPosition::STATUS_REJECTED);

        $this->createOffer($order, $quotation)->assertSessionHasNoErrors();

        $this->assertSame(1, LeasybackOffer::where('order_id', $order->id)->count());
    }

    public function test_a_quotation_with_no_additional_damage_is_unaffected(): void
    {
        [$order, $quotation] = $this->submittedQuotation();

        $this->createOffer($order, $quotation)->assertSessionHasNoErrors();

        $this->assertSame(1, LeasybackOffer::where('order_id', $order->id)->count());
    }

    /**
     * A pending damage on a sibling quotation is that quotation's problem; it
     * must not block an offer built from this one.
     */
    public function test_a_pending_damage_on_another_quotation_does_not_block(): void
    {
        [$order, $quotation] = $this->submittedQuotation();
        $sibling = $this->quotationFor($order, submitted: true);
        $this->additional($sibling, WorkshopAdditionalPosition::STATUS_PENDING);

        $this->createOffer($order, $quotation)->assertSessionHasNoErrors();

        $this->assertSame(1, LeasybackOffer::where('order_id', $order->id)->count());
    }

    /**
     * The price is the workshop's, so the comparison must show it on the
     * workshop side. It used to appear only under the appraisal with a dash
     * opposite it, which reads as "the workshop did not quote this" and
     * inflated the saving by its own amount.
     */
    public function test_accepting_puts_the_amount_in_the_workshop_column(): void
    {
        [$order, $quotation] = $this->submittedQuotation();
        $additional = $this->additional($quotation, WorkshopAdditionalPosition::STATUS_PENDING);

        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        app(WorkshopAdditionalPositionReviewService::class)
            ->accept($additional, $admin);

        $row = collect(app(WorkshopQuotationService::class)->forOrder($order->id))
            ->firstWhere('id', $quotation->id)['comparison'];

        $accepted = collect($row)->firstWhere('component', 'Tür vorne links');

        $this->assertSame('250.00', $accepted['workshop_amount_net'], 'the workshop price belongs in the workshop column');
        $this->assertSame('250.00', $accepted['appraisal_amount_net']);
        $this->assertSame('0.00', $accepted['difference_net'], 'no saving is invented from the workshop own figure');
    }

    public function test_the_accepted_damage_counts_toward_the_offer_repair_total(): void
    {
        [$order, $quotation] = $this->submittedQuotation();
        $additional = $this->additional($quotation, WorkshopAdditionalPosition::STATUS_PENDING);

        app(WorkshopAdditionalPositionReviewService::class)
            ->accept($additional, User::factory()->create(['user_type' => UserType::Admin]));

        $this->createOffer($order, $quotation)->assertSessionHasNoErrors();

        // 400,00 quoted on the Gutachten position + 250,00 the workshop found.
        $offer = LeasybackOffer::where('order_id', $order->id)->sole();
        $this->assertSame('650.00', (string) $offer->final_total_net);
    }

    // ------------------------------------------------------------------ setup

    /**
     * @return array{0: LeasybackOrder, 1: WorkshopQuotation}
     */
    private function submittedQuotation(): array
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => User::factory()->create(['user_type' => UserType::Privatkunde])->id,
        ]);

        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'inspected',
        ]);

        $position = AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'sort_order' => 0,
            'component' => 'Stoßfänger hinten',
            'damage_description' => 'Kratzer',
            'original_amount_net' => '500.00',
            'source' => AppraisalPosition::SOURCE_MANUAL,
        ]);

        $quotation = $this->quotationFor($order, submitted: true);

        WorkshopQuotationItem::create([
            'quotation_id' => $quotation->id,
            'appraisal_position_id' => $position->id,
            'amount_net' => '400.00',
            'not_repairable' => false,
        ]);

        return [$order, $quotation];
    }

    private function quotationFor(LeasybackOrder $order, bool $submitted): WorkshopQuotation
    {
        return WorkshopQuotation::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'token_hash' => hash('sha256', Str::random(64)),
            'workshop_label' => 'Karosserie '.Str::random(5),
            'show_appraisal_amounts' => true,
            'expires_at' => now()->addDays(14),
            'submitted_at' => $submitted ? now() : null,
            'total_net' => '400.00',
        ]);
    }

    private function additional(WorkshopQuotation $quotation, string $status, string $component = 'Tür vorne links'): WorkshopAdditionalPosition
    {
        return WorkshopAdditionalPosition::create([
            'quotation_id' => $quotation->id,
            'sort_order' => 0,
            'component' => $component,
            'damage_description' => 'Delle',
            'repair_method' => 'Lackieren',
            'amount_net' => '250.00',
            'review_status' => $status,
        ]);
    }

    private function createOffer(LeasybackOrder $order, WorkshopQuotation $quotation): TestResponse
    {
        return $this->actingAs(User::factory()->create(['user_type' => UserType::Admin]))
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.b2b-offer.store', $order->id), ['workshop_quotation_id' => $quotation->id]);
    }
}
