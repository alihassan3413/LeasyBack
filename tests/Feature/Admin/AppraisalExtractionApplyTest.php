<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\Order\Enums\AppraisalExtractionStatus;
use App\Modules\UserProfile\Order\Jobs\ExtractGutachtenImages;
use App\Modules\UserProfile\Order\Models\AppraisalExtraction;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppraisalExtractionApplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_apply_a_ready_proposal(): void
    {
        [$order, $extraction] = $this->readyExtraction();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post($this->route($extraction), ['positions' => $this->submitted()]);

        $response->assertRedirect()->assertSessionHas('success');

        $positions = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->get();
        $this->assertCount(2, $positions);

        $this->assertSame('Stoßfänger hinten', $positions[0]->component);
        $this->assertSame('verkratzt / verschürft', $positions[0]->damage_description);
        $this->assertSame('Smart Repair', $positions[0]->repair_method);
        $this->assertSame('120.00', $positions[0]->original_amount_net);
        $this->assertNull($positions[0]->chargeable_amount_net);
        $this->assertSame(AppraisalPosition::SOURCE_EXTRACTED, $positions[0]->source);
        $this->assertSame($admin->id, $positions[0]->created_by_user_id);

        $this->assertSame('80.00', $positions[1]->original_amount_net);
        $this->assertSame('60.00', $positions[1]->chargeable_amount_net);
        $this->assertSame([1, 2], $positions->pluck('sort_order')->all());
    }

    public function test_applying_marks_the_extraction_applied_with_who_and_when(): void
    {
        [, $extraction] = $this->readyExtraction();
        $admin = $this->admin();

        $this->actingAs($admin)->post($this->route($extraction), ['positions' => $this->submitted()]);

        $fresh = $extraction->fresh();
        $this->assertSame(AppraisalExtractionStatus::Applied, $fresh->status);
        $this->assertSame($admin->id, $fresh->applied_by_user_id);
        $this->assertNotNull($fresh->applied_at);
    }

    public function test_applying_writes_an_audit_entry(): void
    {
        [$order, $extraction] = $this->readyExtraction();
        $admin = $this->admin();

        $this->actingAs($admin)->post($this->route($extraction), ['positions' => $this->submitted()]);

        $this->assertDatabaseHas('leasyback_order_audit_log', [
            'order_id' => $order->id,
            'action' => 'APPRAISAL_EXTRACTION_APPLIED',
            'changed_by_user_id' => $admin->id,
        ]);

        $entry = \DB::table('leasyback_order_audit_log')->where('action', 'APPRAISAL_EXTRACTION_APPLIED')->first();
        $values = json_decode((string) $entry->new_values, true);
        $this->assertSame($extraction->id, $values['extraction_id']);
        $this->assertSame(2, $values['position_count']);
    }

    public function test_only_the_selected_positions_are_created(): void
    {
        [$order, $extraction] = $this->readyExtraction();

        $this->actingAs($this->admin())->post($this->route($extraction), [
            'positions' => [$this->submitted()[1]],
        ]);

        $positions = AppraisalPosition::where('order_id', $order->id)->get();
        $this->assertCount(1, $positions);
        $this->assertSame('Heckdeckel', $positions[0]->component);
    }

    public function test_edited_values_are_applied_without_touching_the_proposal(): void
    {
        [$order, $extraction] = $this->readyExtraction();

        $edited = $this->submitted();
        $edited[0]['component'] = 'Stoßfänger hinten links';
        $edited[0]['original_amount_net'] = '150.00';

        $this->actingAs($this->admin())->post($this->route($extraction), ['positions' => $edited]);

        $position = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->first();
        $this->assertSame('Stoßfänger hinten links', $position->component);
        $this->assertSame('150.00', $position->original_amount_net);

        $this->assertSame('Stoßfänger hinten', $extraction->fresh()->proposal['lines'][0]['component']);
        $this->assertSame('120.00', $extraction->fresh()->proposal['lines'][0]['original_amount_net']);
    }

    public function test_existing_positions_are_kept_and_appended_to(): void
    {
        [$order, $extraction] = $this->readyExtraction();
        AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'sort_order' => 0,
            'component' => 'Manuell erfasst',
            'original_amount_net' => '10.00',
            'source' => AppraisalPosition::SOURCE_MANUAL,
        ]);

        $this->actingAs($this->admin())->post($this->route($extraction), ['positions' => $this->submitted()]);

        $positions = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->get();
        $this->assertCount(3, $positions);
        $this->assertSame('Manuell erfasst', $positions[0]->component);
        $this->assertSame([AppraisalPosition::SOURCE_MANUAL, AppraisalPosition::SOURCE_EXTRACTED, AppraisalPosition::SOURCE_EXTRACTED], $positions->pluck('source')->all());
    }

    public function test_a_proposal_cannot_be_applied_twice(): void
    {
        [$order, $extraction] = $this->readyExtraction();

        $this->actingAs($this->admin())->post($this->route($extraction), ['positions' => $this->submitted()]);
        $this->actingAs($this->admin())
            ->post($this->route($extraction), ['positions' => $this->submitted()])
            ->assertSessionHasErrors('positions');

        $this->assertSame(2, AppraisalPosition::where('order_id', $order->id)->count());
    }

    public function test_a_failed_extraction_cannot_be_applied(): void
    {
        [$order, $extraction] = $this->readyExtraction();
        $extraction->transitionTo(AppraisalExtractionStatus::Discarded);

        $this->actingAs($this->admin())
            ->post($this->route($extraction), ['positions' => $this->submitted()])
            ->assertSessionHasErrors('positions');

        $this->assertSame(0, AppraisalPosition::where('order_id', $order->id)->count());
    }

    public function test_a_pending_extraction_cannot_be_applied(): void
    {
        [$order, $extraction] = $this->readyExtraction(AppraisalExtractionStatus::Pending);

        $this->actingAs($this->admin())
            ->post($this->route($extraction), ['positions' => $this->submitted()])
            ->assertSessionHasErrors('positions');

        $this->assertSame(0, AppraisalPosition::where('order_id', $order->id)->count());
    }

    public function test_an_order_outside_the_appraisal_phase_cannot_be_applied_to(): void
    {
        [$order, $extraction] = $this->readyExtraction();
        $order->update(['order_status' => 'workshop']);

        $this->actingAs($this->admin())
            ->post($this->route($extraction), ['positions' => $this->submitted()])
            ->assertSessionHasErrors('positions');

        $this->assertSame(0, AppraisalPosition::where('order_id', $order->id)->count());
        $this->assertSame(AppraisalExtractionStatus::Ready, $extraction->fresh()->status);
    }

    public function test_an_order_with_an_accepted_offer_cannot_be_applied_to(): void
    {
        [$order, $extraction] = $this->readyExtraction();
        LeasybackOffer::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'offer_status' => 'selected',
        ]);

        $this->actingAs($this->admin())
            ->post($this->route($extraction), ['positions' => $this->submitted()])
            ->assertSessionHasErrors('positions');

        $this->assertSame(0, AppraisalPosition::where('order_id', $order->id)->count());
    }

    public function test_invalid_positions_are_refused(): void
    {
        [$order, $extraction] = $this->readyExtraction();

        foreach ([
            [['component' => '', 'original_amount_net' => '10.00']],
            [['component' => 'Tür', 'original_amount_net' => 'abc']],
            [['component' => 'Tür', 'original_amount_net' => '-5.00']],
            [],
        ] as $positions) {
            $this->actingAs($this->admin())
                ->post($this->route($extraction), ['positions' => $positions])
                ->assertSessionHasErrors();
        }

        $this->assertSame(0, AppraisalPosition::where('order_id', $order->id)->count());
        $this->assertSame(AppraisalExtractionStatus::Ready, $extraction->fresh()->status);
    }

    public function test_a_customer_cannot_apply_a_proposal(): void
    {
        [$order, $extraction] = $this->readyExtraction();

        $this->actingAs(User::factory()->create(['user_type' => UserType::Privatkunde]))
            ->post($this->route($extraction), ['positions' => $this->submitted()])
            ->assertForbidden();

        $this->assertSame(0, AppraisalPosition::where('order_id', $order->id)->count());
    }

    public function test_an_unknown_extraction_is_not_found(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.orders.appraisal-extractions.apply', Str::uuid()), ['positions' => $this->submitted()])
            ->assertNotFound();
    }

    public function test_the_order_payload_carries_the_proposal_lines_for_review(): void
    {
        [$order, $extraction] = $this->readyExtraction();

        $payload = $this->actingAs($this->admin())
            ->get(route('admin.orders.show', $order->id))
            ->viewData('page')['props']['order']['appraisal_extractions'][0];

        $this->assertCount(2, $payload['lines']);
        $this->assertSame('Stoßfänger hinten', $payload['lines'][0]['component']);
        $this->assertSame(3, $payload['lines'][0]['page_number']);
        $this->assertSame(0.9, $payload['lines'][0]['confidence']);
        $this->assertStringContainsString('Stossfänger hinten', (string) $payload['lines'][0]['source_text']);
        $this->assertNull($payload['applied_at']);
        $this->assertNull($payload['applied_by_name']);

        $this->actingAs($this->admin())->post($this->route($extraction), ['positions' => $this->submitted()]);

        $applied = $this->actingAs($this->admin())
            ->get(route('admin.orders.show', $order->id))
            ->viewData('page')['props']['order']['appraisal_extractions'][0];

        $this->assertSame('applied', $applied['status']);
        $this->assertNotNull($applied['applied_at']);
        $this->assertSame($this->admin()->name, $applied['applied_by_name']);
    }

    public function test_the_review_payload_carries_image_suggestions(): void
    {
        [$order, $extraction] = $this->readyExtraction();
        $photo = $this->timPhoto($order, 'Beschädigung 1: Stoßfänger hinten - verkratzt / verschürft - Smart Repair');

        $lines = $this->actingAs($this->admin())
            ->get(route('admin.orders.show', $order->id))
            ->viewData('page')['props']['order']['appraisal_extractions'][0]['lines'];

        $this->assertSame(1, $lines[0]['damage_number']);
        $this->assertCount(1, $lines[0]['suggested_images']);
        $this->assertSame($photo->id, $lines[0]['suggested_images'][0]['document_id']);
        $this->assertSame('damage_number', $lines[0]['suggested_images'][0]['strategy']);
        $this->assertSame(route('admin.vehicles.reports.image', $photo->id), $lines[0]['suggested_images'][0]['url']);
        $this->assertSame([], $lines[1]['suggested_images']);
    }

    public function test_selected_images_are_applied_to_the_created_positions(): void
    {
        [$order, $extraction] = $this->readyExtraction();
        $photo = $this->timPhoto($order, 'Beschädigung 1: Stoßfänger hinten');
        $second = $this->timPhoto($order, 'Beschädigung 1: Stoßfänger hinten Detail');

        $positions = $this->submitted();
        $positions[0]['damage_image_document_ids'] = [$photo->id, $second->id];

        $this->actingAs($this->admin())->post($this->route($extraction), ['positions' => $positions])->assertSessionHas('success');

        $created = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->get();
        $this->assertSame([$photo->id, $second->id], $created[0]->damage_image_document_ids);
        $this->assertNull($created[1]->damage_image_document_ids);
    }

    public function test_images_removed_in_the_review_are_not_applied(): void
    {
        [$order, $extraction] = $this->readyExtraction();
        $this->timPhoto($order, 'Beschädigung 1: Stoßfänger hinten');

        $positions = $this->submitted();
        $positions[0]['damage_image_document_ids'] = [];

        $this->actingAs($this->admin())->post($this->route($extraction), ['positions' => $positions])->assertSessionHas('success');

        $this->assertNull(AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->first()->damage_image_document_ids);
    }

    public function test_a_document_of_another_order_is_refused_as_a_damage_image(): void
    {
        [$order, $extraction] = $this->readyExtraction();
        [$otherOrder] = $this->readyExtraction();
        $foreign = $this->timPhoto($otherOrder, 'Beschädigung 1: Stoßfänger hinten');

        $positions = $this->submitted();
        $positions[0]['damage_image_document_ids'] = [$foreign->id];

        $this->actingAs($this->admin())
            ->post($this->route($extraction), ['positions' => $positions])
            ->assertSessionHasErrors('positions.0.damage_image_document_ids.0');

        $this->assertSame(0, AppraisalPosition::where('order_id', $order->id)->count());
    }

    public function test_an_unknown_document_id_is_refused_as_a_damage_image(): void
    {
        [$order, $extraction] = $this->readyExtraction();

        $positions = $this->submitted();
        $positions[0]['damage_image_document_ids'] = [(string) Str::uuid()];

        $this->actingAs($this->admin())
            ->post($this->route($extraction), ['positions' => $positions])
            ->assertSessionHasErrors('positions.0.damage_image_document_ids.0');

        $this->assertSame(0, AppraisalPosition::where('order_id', $order->id)->count());
    }

    public function test_manual_positions_keep_their_own_images(): void
    {
        [$order, $extraction] = $this->readyExtraction();
        $photo = $this->timPhoto($order, 'Beschädigung 1: Stoßfänger hinten');

        $manual = AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'sort_order' => 0,
            'component' => 'Manuell erfasst',
            'original_amount_net' => '10.00',
            'source' => AppraisalPosition::SOURCE_MANUAL,
            'damage_image_document_ids' => [$photo->id],
        ]);

        $positions = $this->submitted();
        $positions[0]['damage_image_document_ids'] = [$photo->id];

        $this->actingAs($this->admin())->post($this->route($extraction), ['positions' => $positions])->assertSessionHas('success');

        $this->assertSame([$photo->id], $manual->fresh()->damage_image_document_ids);
        $this->assertSame(AppraisalPosition::SOURCE_MANUAL, $manual->fresh()->source);
        $this->assertSame(3, AppraisalPosition::where('order_id', $order->id)->count());
    }

    public function test_gutachten_pdf_images_are_suggested_in_the_review_payload(): void
    {
        [$order] = $this->readyExtraction();
        $first = $this->gutachtenBild($order, 1, 69);
        $second = $this->gutachtenBild($order, 1, 70);
        $other = $this->gutachtenBild($order, 2, 71);

        $lines = $this->actingAs($this->admin())
            ->get(route('admin.orders.show', $order->id))
            ->viewData('page')['props']['order']['appraisal_extractions'][0]['lines'];

        $this->assertSame([$first->id, $second->id], array_column($lines[0]['suggested_images'], 'document_id'));
        $this->assertSame(['damage_number', 'damage_number'], array_column($lines[0]['suggested_images'], 'strategy'));
        $this->assertSame([$other->id], array_column($lines[1]['suggested_images'], 'document_id'));
        $this->assertSame(route('admin.vehicles.reports.image', $first->id), $lines[0]['suggested_images'][0]['url']);
    }

    public function test_suggested_gutachten_images_are_saved_when_applied(): void
    {
        [$order, $extraction] = $this->readyExtraction();
        $first = $this->gutachtenBild($order, 1, 69);
        $second = $this->gutachtenBild($order, 1, 70);

        $positions = $this->submitted();
        $positions[0]['damage_image_document_ids'] = [$first->id, $second->id];

        $this->actingAs($this->admin())->post($this->route($extraction), ['positions' => $positions])->assertSessionHas('success');

        $created = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->first();

        $this->assertSame([$first->id, $second->id], $created->damage_image_document_ids);
        $this->assertSame(AppraisalPosition::SOURCE_EXTRACTED, $created->source);
    }

    private function gutachtenBild(LeasybackOrder $order, int $damageNumber, int $index): VehicleReportDocument
    {
        return VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => ExtractGutachtenImages::DOCUMENT_TYPE,
            'document_title' => "Beschädigung {$damageNumber}: Gutachten Seite 14, Bild {$index}",
            'path' => "vehicle-reports/{$order->auftragsnummer}/gutachten-bilder/doc/p014-{$index}.jpg",
            'source_assessment_document_id' => null,
        ]);
    }

    private function timPhoto(LeasybackOrder $order, string $caption): VehicleReportDocument
    {
        $assessmentId = DB::table('vehicle_assessments')->insertGetId([
            'uid' => (string) Str::uuid(),
            'auftragsnummer' => $order->auftragsnummer,
            'created_at' => now(),
        ]);

        $assessmentDocumentId = DB::table('assessment_documents')->insertGetId([
            'assessment_id' => $assessmentId,
            'doc_type' => 'AnsichtsFoto',
            'external_id' => random_int(1000, 9999),
            'caption' => $caption,
            'image_kind' => 'Beschädigung',
            'sort_order' => 1,
            's3_bucket' => 'bucket',
            's3_key' => 'tim/'.Str::uuid().'.jpg',
            's3_url' => 's3://bucket/photo.jpg',
            'created_at' => now(),
        ]);

        return VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => 'AnsichtsFoto',
            'path' => 'vehicle-reports/'.$order->auftragsnummer.'/'.Str::uuid().'.jpg',
            'source_assessment_document_id' => $assessmentDocumentId,
        ]);
    }

    private function route(AppraisalExtraction $extraction): string
    {
        return route('admin.orders.appraisal-extractions.apply', $extraction->id);
    }

    private function submitted(): array
    {
        return [
            [
                'component' => 'Stoßfänger hinten',
                'damage_description' => 'verkratzt / verschürft',
                'repair_method' => 'Smart Repair',
                'original_amount_net' => '120.00',
                'chargeable_amount_net' => null,
            ],
            [
                'component' => 'Heckdeckel',
                'damage_description' => 'Abrieb',
                'repair_method' => 'Smart Repair',
                'original_amount_net' => '80.00',
                'chargeable_amount_net' => '60.00',
            ],
        ];
    }

    private function admin(): User
    {
        return User::firstWhere('user_type', UserType::Admin)
            ?? User::factory()->create(['user_type' => UserType::Admin, 'name' => 'Admin Person']);
    }

    private function readyExtraction(AppraisalExtractionStatus $status = AppraisalExtractionStatus::Ready): array
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

        $extraction = AppraisalExtraction::factory()->create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'status' => $status,
            'source' => 'parser',
            'completed_at' => $status === AppraisalExtractionStatus::Ready ? now() : null,
            'proposal' => [
                'appraisal_number' => '42772146',
                'appraisal_date' => '2025-12-02',
                'total_net' => '200.00',
                'lines' => [
                    [
                        'component' => 'Stoßfänger hinten',
                        'damage_description' => 'verkratzt / verschürft',
                        'repair_method' => 'Smart Repair',
                        'original_amount_net' => '120.00',
                        'chargeable_amount_net' => null,
                        'page_number' => 3,
                        'source_text' => '2 Stossfänger hinten - verkratzt / verschürft - 120,00 €',
                        'confidence' => 0.9,
                        'damage_number' => 1,
                    ],
                    [
                        'component' => 'Heckdeckel',
                        'damage_description' => 'Abrieb',
                        'repair_method' => 'Smart Repair',
                        'original_amount_net' => '80.00',
                        'chargeable_amount_net' => '60.00',
                        'page_number' => 3,
                        'source_text' => '1 Heckdeckel - Abrieb - 80,00 €',
                        'confidence' => 0.9,
                        'damage_number' => 2,
                    ],
                ],
            ],
        ]);

        return [$order, $extraction];
    }
}
