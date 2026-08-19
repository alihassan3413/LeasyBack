<?php

namespace Tests\Feature\Offer;

use App\Enums\UserType;
use App\Mail\Orders\RepairApprovalConfirmedMail;
use App\Models\LeasybackOffer as ShimOffer;
use App\Models\OfferAuditLog;
use App\Models\OrderAuditLog;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Offer\Services\OfferService;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * "An order has at most one selected offer."
 *
 * The decision used to be guarded by an `exists()` check that ran before the
 * transaction opened, with nothing behind it in the schema — so a double-click,
 * a retried request, or a customer and an admin deciding at the same moment
 * could each leave two accepted offers on one order, each closing the other's
 * siblings. These tests pin the rule at both layers, and pin the difference
 * between a replay of one decision and an attempt to make a second one.
 */
class SingleSelectedOfferTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    // ------------------------------------------------------------ happy path

    public function test_a_customer_can_select_one_published_offer(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $offer = $this->publishedOffer($order, 1);
        $sibling = $this->publishedOffer($order, 2);

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->post(route('offers.select', $offer->offer_id))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'Angebot wurde ausgewählt.');

        $this->assertSame('selected', $offer->fresh()->offer_status);
        $this->assertSame('closed', $sibling->fresh()->offer_status);
        $this->assertNotNull($sibling->fresh()->closed_at);
    }

    // ----------------------------------------------------------- idempotency

    public function test_selecting_the_same_offer_twice_is_idempotent(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $offer = $this->publishedOffer($order, 1);
        $this->publishedOffer($order, 2);

        $this->actingAs($owner)->from(route('dashboard'))
            ->post(route('offers.select', $offer->offer_id))
            ->assertSessionHas('success', 'Angebot wurde ausgewählt.');

        $firstDecidedAt = $offer->fresh()->selected_at;

        $this->actingAs($owner)->from(route('dashboard'))
            ->post(route('offers.select', $offer->offer_id))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'Dieses Angebot ist bereits angenommen.')
            ->assertSessionHasNoErrors();

        // The decision keeps its original timestamp — a replay reports the
        // state, it does not re-make it.
        $this->assertEquals($firstDecidedAt, $offer->fresh()->selected_at);
        $this->assertSame(1, LeasybackOffer::where('order_id', $order->id)->where('offer_status', 'selected')->count());
    }

    public function test_a_replay_writes_no_second_audit_entry_and_sends_no_second_mail(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $offer = $this->publishedOffer($order, 1);
        $sibling = $this->publishedOffer($order, 2);

        $this->actingAs($owner)->from(route('dashboard'))->post(route('offers.select', $offer->offer_id));
        $this->actingAs($owner)->from(route('dashboard'))->post(route('offers.select', $offer->offer_id));
        $this->actingAs($owner)->from(route('dashboard'))->post(route('offers.select', $offer->offer_id));

        $this->assertSame(1, OfferAuditLog::where('offer_id', $offer->offer_id)
            ->where('action', 'selected_by_customer')->count());
        $this->assertSame(1, OfferAuditLog::where('offer_id', $sibling->offer_id)
            ->where('action', 'closed_after_customer_selection')->count());
        $this->assertSame(1, OrderAuditLog::where('order_id', $order->id)
            ->where('action', 'OFFER_SELECTED')->count());

        Mail::assertQueued(RepairApprovalConfirmedMail::class, 1);
    }

    public function test_the_api_reports_a_replay_as_success_rather_than_an_error(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $offer = $this->publishedOffer($order, 1);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/vehicle/offers/customer/select/'.$offer->offer_id)
            ->assertOk()
            ->assertJson(['already_selected' => false, 'message' => 'Offer selected successfully']);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/vehicle/offers/customer/select/'.$offer->offer_id)
            ->assertOk()
            ->assertJson(['already_selected' => true, 'message' => 'Offer was already selected']);
    }

    // -------------------------------------------------------------- conflict

    public function test_selecting_a_different_offer_after_one_is_selected_is_refused(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $first = $this->publishedOffer($order, 1);
        $second = $this->publishedOffer($order, 2);

        $this->actingAs($owner)->from(route('dashboard'))->post(route('offers.select', $first->offer_id));

        $this->actingAs($owner)
            ->from(route('dashboard'))
            ->post(route('offers.select', $second->offer_id))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors(['offer' => 'Für diesen Auftrag wurde bereits Angebot 1 angenommen. Eine Entscheidung kann nicht geändert werden.']);

        $this->assertSame('selected', $first->fresh()->offer_status);
        $this->assertSame('closed', $second->fresh()->offer_status);
    }

    // -------------------------------------------------------- database layer

    public function test_the_database_refuses_two_selected_offers_on_one_order(): void
    {
        [, $order] = $this->b2cOrder();

        LeasybackOffer::factory()->selected()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_sequence' => 1,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        LeasybackOffer::factory()->selected()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_sequence' => 2,
        ]);
    }

    /**
     * The index is partial, so it constrains only the decision — an order may
     * still accumulate as many draft, published, closed and rejected offers as
     * its history requires.
     */
    public function test_the_index_does_not_restrict_undecided_offers(): void
    {
        [, $order] = $this->b2cOrder();

        $this->publishedOffer($order, 1);
        $this->publishedOffer($order, 2);
        LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_sequence' => 3,
            'offer_status' => 'closed',
        ]);

        $this->assertSame(3, LeasybackOffer::where('order_id', $order->id)->count());
    }

    // ---------------------------------------------------------- lost the race

    /**
     * What a genuinely simultaneous pair looks like from the loser's side: its
     * in-transaction read saw no decision, the other request committed first,
     * and the index refused the write. The answer must be the same conflict a
     * sequential second attempt would have produced — not a 500.
     */
    public function test_a_lost_race_against_a_different_offer_answers_the_same_conflict(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $winner = $this->publishedOffer($order, 1);
        $loser = $this->publishedOffer($order, 2);

        $this->selectBehindTheServicesBack($winner);

        try {
            app(OfferService::class)->selectOffer($this->shim($loser), $owner);
            $this->fail('Expected the losing selection to be refused.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertStringContainsString('bereits Angebot 1 angenommen', $e->getResponse()->getData(true)['error']);
        }

        $this->assertSame(1, LeasybackOffer::where('order_id', $order->id)->where('offer_status', 'selected')->count());
    }

    /**
     * And the double-click version of the same race: both requests wanted the
     * same offer, so the loser converges on success instead of an error.
     */
    public function test_a_lost_race_against_the_same_offer_resolves_as_an_idempotent_success(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $offer = $this->publishedOffer($order, 1);

        $this->selectBehindTheServicesBack($offer);

        $result = app(OfferService::class)->selectOffer($this->shim($offer), $owner);

        $this->assertTrue($result['already_selected']);
        $this->assertSame($offer->offer_id, $result['offer']->offer_id);
        Mail::assertNothingQueued();
    }

    // ------------------------------------------------------ customer v. admin

    public function test_customer_and_admin_competing_on_different_offers_leave_exactly_one_winner(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $customerChoice = $this->publishedOffer($order, 1);
        $adminChoice = $this->publishedOffer($order, 2);

        $this->actingAs($owner)->from(route('dashboard'))
            ->post(route('offers.select', $customerChoice->offer_id));

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.offers.select', $adminChoice->offer_id))
            ->assertSessionHasErrors('offer');

        $this->assertSame(1, LeasybackOffer::where('order_id', $order->id)->where('offer_status', 'selected')->count());
        $this->assertSame('selected', $customerChoice->fresh()->offer_status);

        // The winning decision keeps its own attribution.
        $this->assertSame($owner->id, $customerChoice->fresh()->selected_by_user_id);
        $this->assertSame(1, OfferAuditLog::where('offer_id', $customerChoice->offer_id)
            ->where('action', 'selected_by_customer')->count());
        $this->assertSame(0, OfferAuditLog::where('action', 'selected_by_admin_on_behalf')->count());
    }

    public function test_an_admin_confirming_the_customers_own_choice_is_treated_as_a_replay(): void
    {
        [$owner, $order] = $this->b2cOrder();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $offer = $this->publishedOffer($order, 1);

        $this->actingAs($owner)->from(route('dashboard'))->post(route('offers.select', $offer->offer_id));

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.offers.select', $offer->offer_id))
            ->assertSessionHas('success', 'Dieses Angebot war bereits angenommen.')
            ->assertSessionHasNoErrors();

        // Attribution stays with whoever actually decided.
        $this->assertSame($owner->id, $offer->fresh()->selected_by_user_id);
        Mail::assertQueued(RepairApprovalConfirmedMail::class, 1);
    }

    // ------------------------------------------------------------------- B2B

    public function test_the_same_invariant_applies_to_a_b2b_order(): void
    {
        $company = $this->makeCompany();
        $owner = $this->makeOwner($company);
        $vehicle = $this->makeB2bVehicle($company);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        $first = $this->publishedOffer($order, 1);
        $second = $this->publishedOffer($order, 2);

        $this->actingAs($owner)->from(route('dashboard'))
            ->post(route('offers.select', $first->offer_id))
            ->assertSessionHas('success', 'Angebot wurde ausgewählt.');

        $this->actingAs($owner)->from(route('dashboard'))
            ->post(route('offers.select', $second->offer_id))
            ->assertSessionHasErrors('offer');

        $this->assertSame('selected', $first->fresh()->offer_status);
        $this->assertSame(1, LeasybackOffer::where('order_id', $order->id)->where('offer_status', 'selected')->count());
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array{0: User, 1: LeasybackOrder}
     */
    private function b2cOrder(): array
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2c_user_id' => $owner->id,
            'b2b_id' => null,
        ]);
        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id]);

        return [$owner, $order];
    }

    private function publishedOffer(LeasybackOrder $order, int $sequence): LeasybackOffer
    {
        return LeasybackOffer::factory()->published()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_sequence' => $sequence,
        ]);
    }

    /**
     * The service type-hints the App\Models shim, which is what every
     * controller resolves — so a direct service call goes through it too.
     */
    private function shim(LeasybackOffer $offer): ShimOffer
    {
        return ShimOffer::where('offer_id', $offer->offer_id)->firstOrFail();
    }

    /**
     * Stands in for the competing request having committed in the window
     * between the service's in-transaction read and its write — the only state
     * a real race can leave that the read cannot see.
     */
    private function selectBehindTheServicesBack(LeasybackOffer $offer): void
    {
        $offer->forceFill([
            'offer_status' => 'selected',
            'selected_at' => now(),
        ])->save();
    }
}
