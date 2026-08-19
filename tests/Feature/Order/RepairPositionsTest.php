<?php

namespace Tests\Feature\Order;

use App\Enums\DocumentType;
use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Repair positions, in both channels.
 *
 * The table, the model and the service were always order-scoped and never
 * company-aware, but every entry point was guarded on the vehicle being B2B —
 * so a private customer's car could hold appraisal PDFs and damage photos and
 * still have nowhere to record what the damage actually costs. The guard was
 * scope, not a business rule.
 *
 * This file is also the first coverage the position workflow has ever had, so
 * it pins the B2B behaviour it inherited as well as the B2C behaviour it gains.
 */
class RepairPositionsTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    // ------------------------------------------------------------ B2C basics

    public function test_a_b2c_order_can_be_given_repair_positions(): void
    {
        $order = $this->b2cOrder();

        $this->save($order, [
            $this->position(['component' => 'Stoßstange vorne', 'original_amount_net' => '450.00']),
        ])->assertRedirect();

        $stored = AppraisalPosition::where('order_id', $order->id)->sole();
        $this->assertSame('Stoßstange vorne', $stored->component);
        $this->assertSame('450.00', (string) $stored->original_amount_net);
        $this->assertSame(AppraisalPosition::SOURCE_MANUAL, $stored->source);
        $this->assertSame($order->auftragsnummer, $stored->auftragsnummer);
    }

    public function test_a_b2c_position_can_be_edited_in_place(): void
    {
        $order = $this->b2cOrder();
        $this->save($order, [$this->position(['component' => 'Kotflügel'])]);

        $id = AppraisalPosition::where('order_id', $order->id)->value('id');

        $this->save($order, [
            $this->position([
                'id' => $id,
                'component' => 'Kotflügel links',
                'original_amount_net' => '900.00',
                'chargeable_amount_net' => '600.00',
            ]),
        ])->assertRedirect();

        $stored = AppraisalPosition::where('order_id', $order->id)->sole();
        // Same row, not a delete-and-recreate: an edit must not orphan the
        // quotation items that point at this position.
        $this->assertSame($id, $stored->id);
        $this->assertSame('Kotflügel links', $stored->component);
        $this->assertSame('600.00', $stored->effectiveAmountNet());
    }

    public function test_a_b2c_position_can_be_deleted_by_omitting_it(): void
    {
        $order = $this->b2cOrder();
        $this->save($order, [
            $this->position(['component' => 'Tür']),
            $this->position(['component' => 'Spiegel']),
        ]);

        $keep = AppraisalPosition::where('order_id', $order->id)->where('component', 'Tür')->value('id');

        $this->save($order, [$this->position(['id' => $keep, 'component' => 'Tür'])])->assertRedirect();

        $this->assertSame(['Tür'], AppraisalPosition::where('order_id', $order->id)->pluck('component')->all());
    }

    public function test_a_full_set_sync_adds_edits_and_removes_in_one_save(): void
    {
        $order = $this->b2cOrder();
        $this->save($order, [
            $this->position(['component' => 'bleibt']),
            $this->position(['component' => 'verschwindet']),
        ]);

        $kept = AppraisalPosition::where('order_id', $order->id)->where('component', 'bleibt')->value('id');

        $this->save($order, [
            $this->position(['id' => $kept, 'component' => 'bleibt, umbenannt']),
            $this->position(['component' => 'neu']),
        ])->assertRedirect();

        $stored = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->get();
        $this->assertSame(['bleibt, umbenannt', 'neu'], $stored->pluck('component')->all());
        $this->assertSame($kept, $stored->first()->id);
        $this->assertSame([0, 1], $stored->pluck('sort_order')->all());
    }

    public function test_a_b2c_order_supports_many_positions_and_keeps_their_order(): void
    {
        $order = $this->b2cOrder();

        $submitted = [];
        for ($i = 0; $i < 12; $i++) {
            $submitted[] = $this->position(['component' => "Position {$i}"]);
        }

        $this->save($order, $submitted)->assertRedirect();

        $stored = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->pluck('component');
        $this->assertCount(12, $stored);
        $this->assertSame('Position 0', $stored->first());
        $this->assertSame('Position 11', $stored->last());
    }

    public function test_clearing_every_position_is_allowed(): void
    {
        $order = $this->b2cOrder();
        $this->save($order, [$this->position()]);

        $this->save($order, [])->assertRedirect();

        $this->assertSame(0, AppraisalPosition::where('order_id', $order->id)->count());
    }

    // ---------------------------------------------------------------- totals

    /**
     * The chargeable amount wins where one was entered, the original stands in
     * where it was not — the same rule that decides what a workshop is asked to
     * beat, so it has to hold for a private customer's car too.
     */
    public function test_totals_use_the_chargeable_amount_where_one_is_given(): void
    {
        $order = $this->b2cOrder();

        $this->save($order, [
            $this->position(['original_amount_net' => '1000.00', 'chargeable_amount_net' => '750.00']),
            $this->position(['original_amount_net' => '250.50', 'chargeable_amount_net' => null]),
        ]);

        $totals = $this->orderPayload($order)['appraisal_totals'];

        $this->assertSame(2, $totals['count']);
        $this->assertSame('1250.50', $totals['original_total_net']);
        $this->assertSame('1000.50', $totals['chargeable_total_net']);
    }

    // ------------------------------------------------------ document isolation

    public function test_a_b2c_position_may_reference_its_own_damage_image(): void
    {
        $order = $this->b2cOrder();
        $document = $this->documentFor($order);

        $this->save($order, [
            $this->position(['damage_image_document_ids' => [$document->id]]),
        ])->assertRedirect();

        $this->assertSame(
            [$document->id],
            AppraisalPosition::where('order_id', $order->id)->sole()->damage_image_document_ids,
        );
    }

    public function test_a_b2c_position_cannot_reference_another_orders_document(): void
    {
        $order = $this->b2cOrder();
        $foreign = $this->documentFor($this->b2cOrder());

        $this->save($order, [
            $this->position(['damage_image_document_ids' => [$foreign->id]]),
        ])->assertSessionHasErrors('positions.0.damage_image_document_ids.0');

        $this->assertSame(0, AppraisalPosition::where('order_id', $order->id)->count());
    }

    /**
     * VehicleReportService accepts the order number and the vehicle id as
     * independent inputs and never checks that they agree, so a row carrying
     * this order's number against a different car is reachable. It must not
     * become a damage image here.
     */
    public function test_a_document_filed_under_this_order_but_another_vehicle_is_refused(): void
    {
        $order = $this->b2cOrder();

        $mismatched = VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => Vehicle::factory()->create()->vehicle_id,
            'document_type' => DocumentType::Gutachten->value,
        ]);

        $this->save($order, [
            $this->position(['damage_image_document_ids' => [$mismatched->id]]),
        ])->assertSessionHasErrors('positions.0.damage_image_document_ids.0');

        $this->assertSame(0, AppraisalPosition::where('order_id', $order->id)->count());
    }

    public function test_posting_another_orders_position_id_cannot_hijack_that_position(): void
    {
        $victim = $this->b2cOrder();
        $this->save($victim, [$this->position(['component' => 'fremd'])]);
        $victimPositionId = AppraisalPosition::where('order_id', $victim->id)->value('id');

        $attacker = $this->b2cOrder();
        $this->save($attacker, [
            $this->position(['id' => $victimPositionId, 'component' => 'übernommen']),
        ])->assertRedirect();

        // The other order's row is untouched, and the submitted id was treated
        // as a new position rather than a reference to somebody else's.
        $this->assertSame('fremd', AppraisalPosition::whereKey($victimPositionId)->value('component'));
        $this->assertSame($victim->id, AppraisalPosition::whereKey($victimPositionId)->value('order_id'));
        $this->assertNotSame($victimPositionId, AppraisalPosition::where('order_id', $attacker->id)->value('id'));
    }

    // -------------------------------------------------------- authorization

    public function test_a_private_customer_cannot_write_positions(): void
    {
        $order = $this->b2cOrder();
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($customer)
            ->put(route('admin.orders.appraisal-positions', $order->id), ['positions' => [$this->position()]])
            ->assertForbidden();

        $this->assertSame(0, AppraisalPosition::where('order_id', $order->id)->count());
    }

    public function test_a_guest_cannot_write_positions(): void
    {
        $order = $this->b2cOrder();

        $this->put(route('admin.orders.appraisal-positions', $order->id), ['positions' => [$this->position()]])
            ->assertRedirect(route('login'));

        $this->assertSame(0, AppraisalPosition::where('order_id', $order->id)->count());
    }

    public function test_an_unknown_order_still_404s(): void
    {
        $this->actingAs($this->makeAdmin())
            ->put(route('admin.orders.appraisal-positions', (string) Str::uuid()), ['positions' => []])
            ->assertNotFound();
    }

    // ---------------------------------------------------------- admin payload

    public function test_the_admin_b2c_order_payload_carries_positions_and_totals(): void
    {
        $order = $this->b2cOrder();
        $this->save($order, [$this->position(['component' => 'Heckklappe', 'original_amount_net' => '320.00'])]);

        $payload = $this->orderPayload($order);

        $this->assertSame('B2C', $payload['vehicle_belongs']);
        $this->assertCount(1, $payload['appraisal_positions']);
        $this->assertSame('Heckklappe', $payload['appraisal_positions'][0]['component']);
        $this->assertSame('320.00', $payload['appraisal_totals']['chargeable_total_net']);
    }

    /**
     * The card is rendered from the payload, so "the page receives the card's
     * data" means an empty list rather than the null that used to make the card
     * vanish. Its own empty state then invites the first position.
     */
    public function test_a_b2c_order_without_positions_sends_an_empty_list_not_null(): void
    {
        $payload = $this->orderPayload($this->b2cOrder());

        $this->assertSame([], $payload['appraisal_positions']);
        $this->assertSame(0, $payload['appraisal_totals']['count']);
        $this->assertSame('0', $payload['appraisal_totals']['chargeable_total_net']);
    }

    /**
     * Unblocking positions must not drag the rest of the B2B page across with
     * them: billing, notes and collection stay B2B-only.
     *
     * Workshop quotations were on this list too and have since been shared on
     * purpose — a quotation is what a position is *for* — so they moved to
     * WorkshopQuotationChannelTest, which owns that boundary now.
     */
    public function test_unblocking_positions_does_not_expose_other_b2b_sections_to_b2c(): void
    {
        $payload = $this->orderPayload($this->b2cOrder());

        $this->assertNull($payload['billing']);
        $this->assertNull($payload['notes']);
        $this->assertNull($payload['collection']);
        $this->assertNull($payload['tasks']);
    }

    // ------------------------------------------------------------------ B2B

    public function test_b2b_positions_still_work_exactly_as_before(): void
    {
        $order = $this->b2bOrder();

        $this->save($order, [
            $this->position(['component' => 'Fahrertür', 'original_amount_net' => '800.00', 'chargeable_amount_net' => '650.00']),
        ])->assertRedirect();

        $payload = $this->orderPayload($order);

        $this->assertSame('B2B', $payload['vehicle_belongs']);
        $this->assertCount(1, $payload['appraisal_positions']);
        $this->assertSame('650.00', $payload['appraisal_totals']['chargeable_total_net']);
        $this->assertNotNull($payload['workshop_quotations']);
    }

    public function test_b2b_document_isolation_is_unchanged(): void
    {
        $order = $this->b2bOrder();

        $this->save($order, [
            $this->position(['damage_image_document_ids' => [$this->documentFor($this->b2bOrder())->id]]),
        ])->assertSessionHasErrors('positions.0.damage_image_document_ids.0');
    }

    // ------------------------------------------------------------ structure

    /**
     * The point of this change is that B2C reuses the canonical model. A
     * parallel table or a `B2c*` service would satisfy every behavioural test
     * above and still be the wrong answer, so it is asserted directly.
     */
    public function test_both_channels_share_one_table_and_one_service(): void
    {
        $b2c = $this->b2cOrder();
        $b2b = $this->b2bOrder();

        $this->save($b2c, [$this->position(['component' => 'privat'])]);
        $this->save($b2b, [$this->position(['component' => 'flotte'])]);

        $this->assertSame(
            2,
            DB::table((new AppraisalPosition)->getTable())->count(),
            'both channels must persist into the one canonical positions table',
        );

        $this->assertFalse(class_exists('App\Models\B2cAppraisalPosition'));
        $this->assertFalse(class_exists('App\Modules\UserProfile\Order\Models\B2cAppraisalPosition'));
        $this->assertFalse(class_exists('App\Modules\UserProfile\Order\Services\B2cAppraisalPositionService'));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<int, array<string, mixed>>  $positions
     */
    private function save(LeasybackOrder $order, array $positions, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->put(route('admin.orders.appraisal-positions', $order->id), ['positions' => $positions]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function position(array $overrides = []): array
    {
        return [
            'component' => 'Bauteil',
            'damage_description' => 'Kratzer',
            'original_amount_net' => '100.00',
            'chargeable_amount_net' => null,
            'repair_method' => 'Smart Repair',
            'damage_image_document_ids' => [],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(LeasybackOrder $order): array
    {
        return $this->actingAs($this->makeAdmin())
            ->get(route('admin.orders.show', $order->id))
            ->viewData('page')['props']['order'];
    }

    private function documentFor(LeasybackOrder $order): VehicleReportDocument
    {
        return VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => DocumentType::Gutachten->value,
        ]);
    }

    private function b2cOrder(string $status = 'inspected'): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2b_id' => null]);

        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => $status,
        ]);
    }

    private function b2bOrder(string $status = OrderStatus::Inspected->value): LeasybackOrder
    {
        return $this->makeB2bOrder($this->makeB2bVehicle($this->makeCompany(fake()->unique()->company())), $status);
    }
}
