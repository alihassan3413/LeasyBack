<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionProposal;
use App\Modules\UserProfile\Order\Data\AppraisalProposalLine;
use App\Modules\UserProfile\Order\Jobs\ExtractGutachtenImages;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\DamageImageMatcher;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DamageImageMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_caption_damage_number_matches_the_extracted_position(): void
    {
        $order = $this->order();
        $first = $this->timPhoto($order, 'Beschädigung 1: Verkleidungen/Abdeckungen: Heckdeckel, Innenverkleidung - Abrieb - Smart Repair');
        $second = $this->timPhoto($order, 'Beschädigung 2: Stossfänger hinten: Stossfänger hinten - verkratzt / verschürft - Smart Repair');

        $suggestions = $this->matcher()->suggest($order, $this->proposal());

        $this->assertSame([$first->id], $suggestions[0]['document_ids']);
        $this->assertSame(DamageImageMatcher::STRATEGY_DAMAGE_NUMBER, $suggestions[0]['strategy']);
        $this->assertSame([$second->id], $suggestions[1]['document_ids']);
    }

    public function test_several_photos_of_one_damage_number_are_all_suggested(): void
    {
        $order = $this->order();
        $first = $this->timPhoto($order, 'Beschädigung 2: Stossfänger hinten - verkratzt / verschürft - Smart Repair');
        $second = $this->timPhoto($order, 'Beschädigung 2: Stossfänger hinten - verkratzt / verschürft - Smart Repair');
        $third = $this->timPhoto($order, 'Beschädigung 2: Stossfänger hinten - Detailaufnahme');

        $suggestions = $this->matcher()->suggest($order, $this->proposal());

        $this->assertEqualsCanonicalizing([$first->id, $second->id, $third->id], $suggestions[1]['document_ids']);
        $this->assertArrayNotHasKey(0, $suggestions);
    }

    public function test_a_caption_naming_several_damages_is_not_assigned(): void
    {
        $order = $this->order();
        $this->timPhoto($order, 'Beschädigung 1: und Beschädigung 2: Übersicht Heck');

        $this->assertSame([], $this->matcher()->suggest($order, $this->proposal()));
    }

    public function test_identical_captions_without_numbers_stay_unassigned(): void
    {
        $order = $this->order();
        $this->timPhoto($order, 'Stossfänger hinten verkratzt Smart Repair');
        $this->timPhoto($order, 'Stossfänger hinten verkratzt Smart Repair');

        $this->assertSame([], $this->matcher()->suggest($order, $this->proposal()));
    }

    public function test_a_single_matching_caption_without_a_number_is_suggested(): void
    {
        $order = $this->order();
        $photo = $this->timPhoto($order, 'Stossfänger hinten verkratzt Smart Repair');
        $this->timPhoto($order, 'Fahrzeugansicht diagonal vorne links');

        $suggestions = $this->matcher()->suggest($order, $this->proposal());

        $this->assertSame([$photo->id], $suggestions[1]['document_ids']);
        $this->assertSame(DamageImageMatcher::STRATEGY_TEXT, $suggestions[1]['strategy']);
    }

    public function test_unrelated_images_are_ignored(): void
    {
        $order = $this->order();
        $this->timPhoto($order, 'Fahrzeugansicht diagonal vorne links');
        $this->timPhoto($order, 'Armaturenbrett Kilometerstand');

        $this->assertSame([], $this->matcher()->suggest($order, $this->proposal()));
    }

    public function test_documents_of_another_order_are_never_suggested(): void
    {
        $order = $this->order();
        $this->timPhoto($this->order(), 'Beschädigung 1: Heckdeckel, Innenverkleidung - Abrieb - Smart Repair');

        $this->assertSame([], $this->matcher()->suggest($order, $this->proposal()));
    }

    public function test_pdf_documents_are_never_suggested(): void
    {
        $order = $this->order();
        $this->document($order, 'erstgutachten.pdf', null);

        $this->assertSame([], $this->matcher()->suggest($order, $this->proposal()));
    }

    public function test_a_manually_uploaded_photo_matches_through_its_title(): void
    {
        $order = $this->order();
        $photo = $this->document($order, 'foto.jpg', null, 'Stossfänger hinten Smart Repair');

        $suggestions = $this->matcher()->suggest($order, $this->proposal());

        $this->assertSame([$photo->id], $suggestions[1]['document_ids']);
    }

    public function test_a_photo_claimed_by_a_damage_number_is_not_reused_by_the_text_fallback(): void
    {
        $order = $this->order();
        $numbered = $this->timPhoto($order, 'Beschädigung 2: Stossfänger hinten - verkratzt / verschürft - Smart Repair');

        $proposal = new AppraisalExtractionProposal(lines: [
            new AppraisalProposalLine('Stossfänger hinten', '120.00', repairMethod: 'Smart Repair', damageNumber: 2),
            new AppraisalProposalLine('Stossfänger hinten', '60.00', repairMethod: 'Smart Repair'),
        ]);

        $suggestions = $this->matcher()->suggest($order, $proposal);

        $this->assertSame([$numbered->id], $suggestions[0]['document_ids']);
        $this->assertArrayNotHasKey(1, $suggestions);
    }

    public function test_a_proposal_without_damage_numbers_still_matches_by_text(): void
    {
        $order = $this->order();
        $photo = $this->timPhoto($order, 'Heckdeckel, Innenverkleidung - Abrieb - Smart Repair');

        $proposal = new AppraisalExtractionProposal(lines: [
            new AppraisalProposalLine('Heckdeckel, Innenverkleidung', '80.00', repairMethod: 'Smart Repair'),
        ]);

        $this->assertSame([$photo->id], $this->matcher()->suggest($order, $proposal)[0]['document_ids']);
    }

    public function test_an_order_without_images_yields_no_suggestions(): void
    {
        $this->assertSame([], $this->matcher()->suggest($this->order(), $this->proposal()));
    }

    public function test_nothing_is_written_to_the_positions(): void
    {
        $order = $this->order();
        $this->timPhoto($order, 'Beschädigung 1: Heckdeckel, Innenverkleidung - Abrieb - Smart Repair');

        $this->matcher()->suggest($order, $this->proposal());

        $this->assertDatabaseCount('b2b_appraisal_positions', 0);
    }

    public function test_gutachten_pdf_images_are_suggested_by_their_damage_number(): void
    {
        $order = $this->order();
        $first = $this->gutachtenBild($order, 1, 14, 69);
        $second = $this->gutachtenBild($order, 1, 14, 70);
        $third = $this->gutachtenBild($order, 2, 14, 71);

        $suggestions = $this->matcher()->suggest($order, $this->proposal());

        $this->assertSame([$first->id, $second->id], $suggestions[0]['document_ids']);
        $this->assertSame(DamageImageMatcher::STRATEGY_DAMAGE_NUMBER, $suggestions[0]['strategy']);
        $this->assertSame([$third->id], $suggestions[1]['document_ids']);
    }

    public function test_damage_numbers_are_never_mixed_between_positions(): void
    {
        $order = $this->order();
        $this->gutachtenBild($order, 3, 14, 80);
        $this->gutachtenBild($order, 4, 14, 81);

        $this->assertSame([], $this->matcher()->suggest($order, $this->proposal()));
    }

    public function test_a_position_without_a_damage_number_falls_back_to_text_matching(): void
    {
        $order = $this->order();
        $this->gutachtenBild($order, 1, 14, 69);
        $photo = $this->timPhoto($order, 'Stossfänger hinten verkratzt Smart Repair');

        $proposal = new AppraisalExtractionProposal(lines: [
            new AppraisalProposalLine('Stossfänger hinten', '120.00', repairMethod: 'Smart Repair'),
        ]);

        $suggestions = $this->matcher()->suggest($order, $proposal);

        $this->assertSame([$photo->id], $suggestions[0]['document_ids']);
        $this->assertSame(DamageImageMatcher::STRATEGY_TEXT, $suggestions[0]['strategy']);
    }

    public function test_gutachten_and_tuv_sued_images_are_suggested_side_by_side(): void
    {
        $order = $this->order();
        $extracted = $this->gutachtenBild($order, 1, 14, 69);
        $tim = $this->timPhoto($order, 'Beschädigung 1: Heckdeckel, Innenverkleidung - Abrieb - Smart Repair');
        $timSecond = $this->timPhoto($order, 'Beschädigung 2: Stossfänger hinten - verkratzt / verschürft - Smart Repair');

        $suggestions = $this->matcher()->suggest($order, $this->proposal());

        $this->assertEqualsCanonicalizing([$extracted->id, $tim->id], $suggestions[0]['document_ids']);
        $this->assertSame([$timSecond->id], $suggestions[1]['document_ids']);
    }

    public function test_a_gutachten_image_without_a_damage_number_is_not_suggested(): void
    {
        $order = $this->order();
        $this->document($order, Str::uuid().'.jpg', null, 'Gutachten Seite 14, Bild 69');

        $this->assertSame([], $this->matcher()->suggest($order, $this->proposal()));
    }

    private function gutachtenBild(LeasybackOrder $order, int $damageNumber, int $page, int $index): VehicleReportDocument
    {
        return VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => ExtractGutachtenImages::DOCUMENT_TYPE,
            'document_title' => "Beschädigung {$damageNumber}: Gutachten Seite {$page}, Bild {$index}",
            'path' => "vehicle-reports/{$order->auftragsnummer}/gutachten-bilder/doc/p{$page}-{$index}.jpg",
            'source_assessment_document_id' => null,
        ]);
    }

    private function matcher(): DamageImageMatcher
    {
        return app(DamageImageMatcher::class);
    }

    private function proposal(): AppraisalExtractionProposal
    {
        return new AppraisalExtractionProposal(lines: [
            new AppraisalProposalLine(
                component: 'Heckdeckel, Innenverkleidung',
                originalAmountNet: '80.00',
                damageDescription: 'Abrieb',
                repairMethod: 'Smart Repair',
                damageNumber: 1,
            ),
            new AppraisalProposalLine(
                component: 'Stossfänger hinten',
                originalAmountNet: '120.00',
                damageDescription: 'verkratzt / verschürft',
                repairMethod: 'Smart Repair',
                damageNumber: 2,
            ),
        ]);
    }

    private function order(): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => User::factory()->create(['user_type' => UserType::Privatkunde])->id,
        ]);

        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'inspected',
        ]);
    }

    private function timPhoto(LeasybackOrder $order, string $caption): VehicleReportDocument
    {
        $assessmentId = DB::table('vehicle_assessments')->insertGetId([
            'uid' => (string) Str::uuid(),
            'auftragsnummer' => $order->auftragsnummer,
            'created_at' => now(),
        ]);

        $documentId = DB::table('assessment_documents')->insertGetId([
            'assessment_id' => $assessmentId,
            'doc_type' => 'AnsichtsFoto',
            'external_id' => random_int(1000, 9999),
            'title' => 'Beschädigungsfoto',
            'caption' => $caption,
            'image_kind' => 'Beschädigung',
            'sort_order' => 1,
            's3_bucket' => 'bucket',
            's3_key' => 'tim/'.Str::uuid().'.jpg',
            's3_url' => 's3://bucket/photo.jpg',
            'created_at' => now(),
        ]);

        return $this->document($order, Str::uuid().'.jpg', $documentId);
    }

    private function document(LeasybackOrder $order, string $name, ?int $assessmentDocumentId, ?string $title = null): VehicleReportDocument
    {
        return VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => $assessmentDocumentId === null ? 'gutachten' : 'AnsichtsFoto',
            'document_title' => $title,
            'path' => "vehicle-reports/{$order->auftragsnummer}/{$name}",
            'source_assessment_document_id' => $assessmentDocumentId,
        ]);
    }
}
