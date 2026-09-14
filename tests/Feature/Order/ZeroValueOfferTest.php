<?php

namespace Tests\Feature\Order;

use App\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Nothing priced at nothing may reach a customer: not from the workshop's own
 * form, and not as a published 0,00 € offer.
 */
class ZeroValueOfferTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_a_workshop_cannot_submit_a_quote_with_no_prices(): void
    {
        [$order, $quotation] = $this->invitedQuotation();

        $this->post(route('workshop.quotations.submit', $this->tokenFor($order, $quotation)), [
            'company_name' => 'Karosserie Meier GmbH',
            'contact_person' => 'Jens Meier',
            'contact_email' => 'jens@werkstatt.test',
            'items' => $this->items($order, null),
        ]);

        $this->assertStringContainsString(
            'mindestens eine Position',
            (string) session('errors')?->getBag('default')->first('items'),
        );
        $this->assertSame('invited', $quotation->fresh()->status());
    }

    public function test_a_workshop_cannot_submit_a_quote_priced_at_zero(): void
    {
        [$order, $quotation] = $this->invitedQuotation();

        $this->post(route('workshop.quotations.submit', $this->tokenFor($order, $quotation)), [
            'company_name' => 'Karosserie Meier GmbH',
            'contact_person' => 'Jens Meier',
            'contact_email' => 'jens@werkstatt.test',
            'items' => $this->items($order, '0.00'),
        ]);

        $this->assertNotNull(session('errors')?->getBag('default')->first('items'));
        $this->assertSame('invited', $quotation->fresh()->status());
    }

    public function test_a_workshop_may_still_answer_that_it_cannot_repair_for_the_amount(): void
    {
        [$order, $quotation] = $this->invitedQuotation();

        $this->post(route('workshop.quotations.submit', $this->tokenFor($order, $quotation)), [
            'company_name' => 'Karosserie Meier GmbH',
            'contact_person' => 'Jens Meier',
            'contact_email' => 'jens@werkstatt.test',
            'cannot_repair_for_amount' => true,
            'cannot_repair_note' => 'Zu diesem Betrag nicht machbar.',
            'items' => $this->items($order, null),
        ]);

        $this->assertSame('submitted', $quotation->fresh()->status());
    }

    public function test_a_zero_value_offer_cannot_be_published(): void
    {
        $order = $this->b2cOrder();
        $offer = LeasybackOffer::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_sequence' => 1,
            'offer_status' => 'draft',
            'repair_cost_net' => '0.00',
            'repair_cost_gross' => '0.00',
            'depreciation_value_net' => '0.00',
            'depreciation_value_gross' => '0.00',
            'workshop_repair_quote_net' => '0.00',
            'workshop_repair_quote_gross' => '0.00',
            'missing_parts_cost_net' => '0.00',
            'missing_parts_cost_gross' => '0.00',
        ]);

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.offers.publish', $offer->offer_id));

        $this->assertSame('draft', $offer->fresh()->offer_status);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @return array{0: LeasybackOrder, 1: WorkshopQuotation}
     */
    private function invitedQuotation(): array
    {
        $order = $this->b2cOrder();

        AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'sort_order' => 0,
            'component' => 'Stoßfänger vorne',
            'damage_description' => 'Kratzer',
            'original_amount_net' => '900.00',
            'repair_method' => 'Instandsetzung',
            'source' => AppraisalPosition::SOURCE_MANUAL,
        ]);

        $invite = app(WorkshopQuotationService::class)
            ->invite($order, $this->makeAdmin(), ['workshop_label' => 'Karosserie Meier GmbH']);

        $this->tokens[$order->id] = $invite['token'];

        return [$order, $invite['quotation']];
    }

    /** @var array<string, string> */
    private array $tokens = [];

    private function tokenFor(LeasybackOrder $order, WorkshopQuotation $quotation): string
    {
        return $this->tokens[$order->id];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(LeasybackOrder $order, ?string $amount): array
    {
        return AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->get()
            ->map(fn (AppraisalPosition $p) => [
                'appraisal_position_id' => $p->id,
                'amount_net' => $amount,
                'repair_method' => 'Instandsetzung',
            ])
            ->all();
    }

    private function b2cOrder(): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2b_id' => null]);

        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'inspected',
        ]);
    }
}
