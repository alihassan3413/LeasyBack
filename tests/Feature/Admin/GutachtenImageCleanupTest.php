<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Jobs\ExtractGutachtenImages;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ExtractGutachtenImages files the damage photos it pulls out of a Gutachten
 * under a directory named after that Gutachten, and nothing else links the two
 * — source_assessment_document_id belongs to the TÜV SÜD pull and stays null on
 * extracted images. Deleting the source therefore has to clean them up by that
 * prefix, or they stay in the damage image picker with no way back to the
 * document they came from, and re-uploading a corrected Gutachten stacks a
 * second set on top of the first.
 */
class GutachtenImageCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_gutachten_removes_its_extracted_images(): void
    {
        Storage::fake('documents');
        $gutachten = $this->gutachten();
        $images = $this->extractedImages($gutachten, 3);

        $this->actingAs($this->admin())
            ->delete(route('admin.vehicles.reports.delete', $gutachten->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('vehicle_report_documents', ['id' => $gutachten->id]);

        foreach ($images as $image) {
            $this->assertDatabaseMissing('vehicle_report_documents', ['id' => $image->id]);
            $this->assertDatabaseHas('vehicle_report_document_logs', [
                'document_id' => $image->id,
                'action' => 'deleted',
            ]);
        }
    }

    public function test_deleting_a_gutachten_removes_the_stored_image_files(): void
    {
        Storage::fake('documents');
        $gutachten = $this->gutachten();
        $images = $this->extractedImages($gutachten, 2);

        foreach ($images as $image) {
            Storage::disk('documents')->assertExists($image->path);
        }

        $this->actingAs($this->admin())
            ->delete(route('admin.vehicles.reports.delete', $gutachten->id))
            ->assertRedirect();

        Storage::disk('documents')->assertMissing($gutachten->path);

        foreach ($images as $image) {
            Storage::disk('documents')->assertMissing($image->path);
        }
    }

    public function test_deleting_an_unrelated_document_leaves_extracted_images_alone(): void
    {
        Storage::fake('documents');
        $gutachten = $this->gutachten();
        $images = $this->extractedImages($gutachten, 2);
        $unrelated = $this->document($gutachten, 'rechnung', 'vehicle-reports/'.$gutachten->auftragsnummer.'/rechnung.pdf');

        $this->actingAs($this->admin())
            ->delete(route('admin.vehicles.reports.delete', $unrelated->id))
            ->assertRedirect();

        $this->assertDatabaseHas('vehicle_report_documents', ['id' => $gutachten->id]);

        foreach ($images as $image) {
            $this->assertDatabaseHas('vehicle_report_documents', ['id' => $image->id]);
            Storage::disk('documents')->assertExists($image->path);
        }
    }

    /**
     * An AnsichtsFoto is transferred from TÜV SÜD to a flat
     * vehicle-reports/{auftragsnummer}/ path under the same order as the
     * Gutachten, so it is the document most likely to be caught by a prefix
     * match that is too loose.
     */
    public function test_deleting_a_gutachten_does_not_touch_tuv_sud_ansichtsfoto_documents(): void
    {
        Storage::fake('documents');
        $gutachten = $this->gutachten();
        $this->extractedImages($gutachten, 2);

        $ansichtsFoto = $this->document(
            $gutachten,
            'AnsichtsFoto',
            'vehicle-reports/'.$gutachten->auftragsnummer.'/ansicht-vorne.jpg',
        );

        $this->actingAs($this->admin())
            ->delete(route('admin.vehicles.reports.delete', $gutachten->id))
            ->assertRedirect();

        $this->assertDatabaseHas('vehicle_report_documents', [
            'id' => $ansichtsFoto->id,
            'document_type' => 'AnsichtsFoto',
        ]);
        Storage::disk('documents')->assertExists($ansichtsFoto->path);
    }

    public function test_deleting_a_gutachten_leaves_another_gutachtens_images_alone(): void
    {
        Storage::fake('documents');
        $first = $this->gutachten();
        $second = VehicleReportDocument::factory()->create([
            'vehicle_id' => $first->vehicle_id,
            'auftragsnummer' => $first->auftragsnummer,
            'document_type' => 'gutachten',
            'path' => 'vehicle-reports/'.$first->auftragsnummer.'/nachgutachten.pdf',
        ]);

        $firstImages = $this->extractedImages($first, 2);
        $secondImages = $this->extractedImages($second, 2);

        $this->actingAs($this->admin())
            ->delete(route('admin.vehicles.reports.delete', $first->id))
            ->assertRedirect();

        foreach ($firstImages as $image) {
            $this->assertDatabaseMissing('vehicle_report_documents', ['id' => $image->id]);
        }

        foreach ($secondImages as $image) {
            $this->assertDatabaseHas('vehicle_report_documents', ['id' => $image->id]);
            Storage::disk('documents')->assertExists($image->path);
        }
    }

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function gutachten(): VehicleReportDocument
    {
        $vehicle = Vehicle::factory()->create();

        $document = VehicleReportDocument::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'document_type' => 'gutachten',
            'path' => 'vehicle-reports/AUF-GUT-0001/gutachten.pdf',
            'auftragsnummer' => 'AUF-GUT-0001',
        ]);

        Storage::disk('documents')->put($document->path, '%PDF-1.4');

        return $document;
    }

    /**
     * @return array<int, VehicleReportDocument>
     */
    private function extractedImages(VehicleReportDocument $gutachten, int $count): array
    {
        $directory = ExtractGutachtenImages::directoryFor($gutachten->id);

        $images = [];

        for ($index = 0; $index < $count; $index++) {
            $images[] = $this->document(
                $gutachten,
                ExtractGutachtenImages::DOCUMENT_TYPE,
                "vehicle-reports/{$gutachten->auftragsnummer}/{$directory}/p003-00{$index}.jpg",
            );
        }

        return $images;
    }

    private function document(VehicleReportDocument $sibling, string $type, string $path): VehicleReportDocument
    {
        $document = VehicleReportDocument::factory()->create([
            'vehicle_id' => $sibling->vehicle_id,
            'auftragsnummer' => $sibling->auftragsnummer,
            'document_type' => $type,
            'path' => $path,
        ]);

        Storage::disk('documents')->put($path, 'binary');

        return $document;
    }
}
