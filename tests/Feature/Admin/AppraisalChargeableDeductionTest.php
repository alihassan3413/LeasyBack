<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Enums\AppraisalExtractionStatus;
use App\Modules\UserProfile\Order\Models\AppraisalExtraction;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Gutachten row that states only one amount leaves chargeable_amount_net
 * empty, which an admin then typed by hand for every position. The review
 * payload now proposes the appraiser's amount less a configured percentage
 * instead — 80,00 € proposes 72,00 € — a proposal like any other: visible in
 * the review table, editable, and written only by an explicit apply.
 */
class AppraisalChargeableDeductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_without_a_second_amount_is_proposed_with_the_deduction(): void
    {
        [$order] = $this->readyExtraction();

        $lines = $this->reviewLines($order);

        $this->assertSame('120.00', $lines[0]['original_amount_net']);
        $this->assertSame('108.00', $lines[0]['chargeable_amount_net']);
    }

    public function test_a_row_that_states_its_own_second_amount_keeps_it(): void
    {
        [$order] = $this->readyExtraction();

        $lines = $this->reviewLines($order);

        $this->assertSame('80.00', $lines[1]['original_amount_net']);
        $this->assertSame('60.00', $lines[1]['chargeable_amount_net']);
    }

    public function test_the_deduction_rounds_half_up_to_two_places(): void
    {
        [$order] = $this->readyExtraction([
            ['original_amount_net' => '123.45', 'chargeable_amount_net' => null],
            ['original_amount_net' => '0.01', 'chargeable_amount_net' => null],
            ['original_amount_net' => '2500.50', 'chargeable_amount_net' => null],
        ]);

        $lines = $this->reviewLines($order);

        // bcmul alone truncates 111.105 to 111.10.
        $this->assertSame('111.11', $lines[0]['chargeable_amount_net']);
        $this->assertSame('0.01', $lines[1]['chargeable_amount_net']);
        $this->assertSame('2250.45', $lines[2]['chargeable_amount_net']);
    }

    public function test_a_configured_percentage_of_zero_proposes_nothing(): void
    {
        config(['gutachten.chargeable_deduction_percent' => '0']);
        [$order] = $this->readyExtraction();

        $lines = $this->reviewLines($order);

        $this->assertNull($lines[0]['chargeable_amount_net']);
        $this->assertSame('60.00', $lines[1]['chargeable_amount_net']);
    }

    public function test_the_percentage_is_configurable(): void
    {
        config(['gutachten.chargeable_deduction_percent' => '7.5']);
        [$order] = $this->readyExtraction();

        $lines = $this->reviewLines($order);

        $this->assertSame('111.00', $lines[0]['chargeable_amount_net']);
    }

    public function test_an_applied_extraction_is_not_reduced_again(): void
    {
        [$order] = $this->readyExtraction(status: AppraisalExtractionStatus::Applied);

        $lines = $this->reviewLines($order);

        $this->assertNull($lines[0]['chargeable_amount_net']);
        $this->assertSame('60.00', $lines[1]['chargeable_amount_net']);
    }

    public function test_applying_the_proposal_persists_the_reduced_amount(): void
    {
        [$order, $extraction] = $this->readyExtraction();
        $lines = $this->reviewLines($order);

        $this->actingAs($this->admin())
            ->post(route('admin.orders.appraisal-extractions.apply', $extraction->id), [
                'positions' => array_map(fn (array $line) => [
                    'component' => $line['component'],
                    'damage_description' => $line['damage_description'],
                    'repair_method' => $line['repair_method'],
                    'original_amount_net' => $line['original_amount_net'],
                    'chargeable_amount_net' => $line['chargeable_amount_net'],
                    'damage_image_document_ids' => [],
                ], $lines),
            ])
            ->assertRedirect();

        $positions = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->get();

        $this->assertSame('108.00', (string) $positions[0]->chargeable_amount_net);
        $this->assertSame('108.00', $positions[0]->effectiveAmountNet());
        $this->assertSame('60.00', (string) $positions[1]->chargeable_amount_net);
    }

    public function test_an_admin_can_still_override_the_proposed_amount(): void
    {
        [$order, $extraction] = $this->readyExtraction();

        $this->actingAs($this->admin())
            ->post(route('admin.orders.appraisal-extractions.apply', $extraction->id), [
                'positions' => [[
                    'component' => 'Stoßfänger hinten',
                    'damage_description' => null,
                    'repair_method' => null,
                    'original_amount_net' => '120.00',
                    'chargeable_amount_net' => '200.00',
                    'damage_image_document_ids' => [],
                ]],
            ])
            ->assertRedirect();

        $this->assertSame(
            '200.00',
            (string) AppraisalPosition::where('order_id', $order->id)->firstOrFail()->chargeable_amount_net,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reviewLines(LeasybackOrder $order): array
    {
        return $this->actingAs($this->admin())
            ->get(route('admin.orders.show', $order->id))
            ->viewData('page')['props']['order']['appraisal_extractions'][0]['lines'];
    }

    private function admin(): User
    {
        return User::firstWhere('user_type', UserType::Admin)
            ?? User::factory()->create(['user_type' => UserType::Admin, 'name' => 'Admin Person']);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $lines
     * @return array{0: LeasybackOrder, 1: AppraisalExtraction}
     */
    private function readyExtraction(?array $lines = null, AppraisalExtractionStatus $status = AppraisalExtractionStatus::Ready): array
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

        $lines ??= [
            ['original_amount_net' => '120.00', 'chargeable_amount_net' => null],
            ['original_amount_net' => '80.00', 'chargeable_amount_net' => '60.00'],
        ];

        $extraction = AppraisalExtraction::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'status' => $status,
            'source' => 'parser',
            'completed_at' => now(),
            'proposal' => [
                'appraisal_number' => '42772146',
                'appraisal_date' => '2025-12-02',
                'total_net' => '200.00',
                'lines' => array_map(fn (array $line, int $index) => [
                    'component' => 'Stoßfänger hinten',
                    'damage_description' => 'verkratzt / verschürft',
                    'repair_method' => 'Smart Repair',
                    'page_number' => 3,
                    'source_text' => 'Zeile '.($index + 1),
                    'confidence' => 0.9,
                    'damage_number' => $index + 1,
                    ...$line,
                ], $lines, array_keys($lines)),
            ],
        ]);

        return [$order, $extraction];
    }
}
