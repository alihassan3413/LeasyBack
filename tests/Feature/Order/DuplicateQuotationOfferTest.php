<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One submitted workshop quotation yields at most one live customer offer.
 *
 * "Als Kundenangebot übernehmen" had no guard of any kind: every click built
 * another draft from the same quotation, each with its own sequence number and
 * its own publish button, so one workshop quote could become five offers of
 * the same repair — each of which the customer could then be shown.
 */
class DuplicateQuotationOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_taking_the_same_quotation_twice_is_refused(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $quotation = $this->quotedBy($order, ['400.00']);

        $this->createOffer($order, $quotation)->assertRedirect();
        $this->createOffer($order, $quotation)->assertSessionHasErrors('offer');

        $this->assertSame(1, LeasybackOffer::where('order_id', $order->id)->count());
        $this->assertSame(1, B2bOfferPresentation::where('workshop_quotation_id', $quotation->id)->count());
    }

    public function test_repeated_clicking_never_produces_a_second_offer(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $quotation = $this->quotedBy($order, ['400.00']);

        foreach (range(1, 5) as $ignored) {
            $this->createOffer($order, $quotation);
        }

        $this->assertSame(1, LeasybackOffer::where('order_id', $order->id)->count());
    }

    public function test_a_published_offer_also_blocks_a_second_one(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $quotation = $this->quotedBy($order, ['400.00']);

        $this->createOffer($order, $quotation)->assertRedirect();
        $offer = LeasybackOffer::where('order_id', $order->id)->sole();

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.offers.publish', $offer->offer_id))
            ->assertRedirect();

        $this->createOffer($order, $quotation)->assertSessionHasErrors('offer');

        $this->assertSame(1, LeasybackOffer::where('order_id', $order->id)->count());
    }

    public function test_discarding_the_offer_frees_the_quotation_again(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $quotation = $this->quotedBy($order, ['400.00']);

        $this->createOffer($order, $quotation)->assertRedirect();
        $first = LeasybackOffer::where('order_id', $order->id)->sole();

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.offers.cancel', $first->offer_id), ['cancellation_reason' => 'Falsche Werkstatt'])
            ->assertRedirect();

        $this->createOffer($order, $quotation)->assertRedirect();

        $this->assertSame(2, LeasybackOffer::where('order_id', $order->id)->count());
        $this->assertSame('cancelled', $first->fresh()->offer_status);
        $this->assertSame(
            1,
            LeasybackOffer::where('order_id', $order->id)->whereIn('offer_status', ['draft', 'published', 'selected'])->count(),
        );
    }

    public function test_two_different_quotations_each_yield_their_own_offer(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $first = $this->quotedBy($order, ['400.00'], 'Werkstatt Eins');
        $second = $this->quotedBy($order, ['380.00'], 'Werkstatt Zwei');

        $this->createOffer($order, $first)->assertRedirect();
        $this->createOffer($order, $second)->assertRedirect();

        $this->assertSame(2, LeasybackOffer::where('order_id', $order->id)->count());
    }

    public function test_the_card_stops_offering_an_action_once_the_quotation_is_taken(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $quotation = $this->quotedBy($order, ['400.00']);

        $before = $this->quotationPayload($order, $quotation);
        $this->assertNull($before['customer_offer']);

        $this->createOffer($order, $quotation)->assertRedirect();
        $offer = LeasybackOffer::where('order_id', $order->id)->sole();

        $after = $this->quotationPayload($order, $quotation);
        $this->assertSame($offer->offer_id, $after['customer_offer']['offer_id']);
        $this->assertSame((int) $offer->offer_sequence, $after['customer_offer']['offer_sequence']);
        $this->assertSame('draft', $after['customer_offer']['offer_status']);
    }

    public function test_the_card_offers_the_action_again_after_the_offer_is_discarded(): void
    {
        $order = $this->b2cOrder(['500.00']);
        $quotation = $this->quotedBy($order, ['400.00']);

        $this->createOffer($order, $quotation)->assertRedirect();
        $offer = LeasybackOffer::where('order_id', $order->id)->sole();

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.offers.cancel', $offer->offer_id), ['cancellation_reason' => 'Verworfen'])
            ->assertRedirect();

        $this->assertNull($this->quotationPayload($order, $quotation)['customer_offer']);
    }

    /**
     * @return array<string, mixed>
     */
    private function quotationPayload(LeasybackOrder $order, WorkshopQuotation $quotation): array
    {
        $rows = app(WorkshopQuotationService::class)->forOrder($order->id);

        foreach ($rows as $row) {
            if ($row['id'] === $quotation->id) {
                return $row;
            }
        }

        $this->fail('Quotation not present in the order payload.');
    }

    private function createOffer(LeasybackOrder $order, WorkshopQuotation $quotation): TestResponse
    {
        return $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.b2b-offer.store', $order->id), [
                'workshop_quotation_id' => $quotation->id,
            ]);
    }

    /**
     * @param  array<int, string>  $amounts
     */
    private function quotedBy(LeasybackOrder $order, array $amounts, string $company = 'Werkstatt GmbH'): WorkshopQuotation
    {
        $quotation = app(WorkshopQuotationService::class)
            ->invite($order, $this->makeAdmin(), ['workshop_label' => $company])['quotation'];

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
     * @param  array<int, string>  $appraisalAmounts
     */
    private function b2cOrder(array $appraisalAmounts): LeasybackOrder
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

        return $order->fresh();
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }
}
