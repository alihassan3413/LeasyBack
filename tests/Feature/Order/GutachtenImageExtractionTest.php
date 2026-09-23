<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Contracts\PdfTextExtractor;
use App\Modules\UserProfile\Order\Data\AppraisalExtractionInput;
use App\Modules\UserProfile\Order\Data\ExtractedImage;
use App\Modules\UserProfile\Order\Data\PdfPageText;
use App\Modules\UserProfile\Order\Jobs\ExtractGutachtenImages;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Services\Extraction\GutachtenDamageImageMapper;
use App\Modules\UserProfile\Order\Services\Extraction\GutachtenImageExtractor;
use App\Modules\UserProfile\Order\Services\Extraction\GutachtenPhotoPages;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use App\Modules\UserProfile\Vehicle\Support\ReportDocumentImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GutachtenImageExtractionTest extends TestCase
{
    use RefreshDatabase;

    private const FILLER = 'Das Fahrzeug wurde im Rahmen der vereinbarten Rueckgabe begutachtet und der Zustand dokumentiert. Die Bewertung erfolgt nach den vereinbarten Standards des Auftraggebers und beruecksichtigt den altersbedingten Zustand sowie die Laufleistung.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        if (! app(GutachtenImageExtractor::class)->isAvailable()) {
            $this->markTestSkipped('pdfimages (poppler-utils) is not installed.');
        }
    }

    public function test_embedded_images_become_image_documents_of_the_same_order(): void
    {
        [$order, $document] = $this->gutachten($this->pdfWithImages(2));

        $this->runJob($document);

        $images = $this->extractedImages($order);

        $this->assertCount(2, $images);

        foreach ($images as $image) {
            $this->assertSame($order->auftragsnummer, $image->auftragsnummer);
            $this->assertSame($order->vehicle_id, $image->vehicle_id);
            $this->assertSame(ExtractGutachtenImages::DOCUMENT_TYPE, $image->document_type);
            $this->assertNull($image->source_assessment_document_id);
            $this->assertFalse($image->published);
            $this->assertTrue(ReportDocumentImage::isImage($image->path));
            $this->assertStringContainsString("gutachten-bilder/{$document->id}/", $image->path);
            $this->assertTrue(Storage::disk('documents')->exists($image->path));
            $this->assertMatchesRegularExpression('/^Gutachten Seite \d+, Bild \d+$/', (string) $image->document_title);
        }
    }

    public function test_the_source_gutachten_document_is_untouched(): void
    {
        [$order, $document] = $this->gutachten($this->pdfWithImages(1));

        $this->runJob($document);

        $fresh = $document->fresh();
        $this->assertSame('gutachten', $fresh->document_type);
        $this->assertTrue(Storage::disk('documents')->exists($fresh->path));
        $this->assertSame(2, VehicleReportDocument::where('auftragsnummer', $order->auftragsnummer)->count());
    }

    public function test_small_decorative_images_are_ignored(): void
    {
        [, $document] = $this->gutachten($this->pdfWithImages(1, 80, 40));

        $this->runJob($document);

        $this->assertSame(0, VehicleReportDocument::where('document_type', ExtractGutachtenImages::DOCUMENT_TYPE)->count());
    }

    public function test_a_pdf_without_images_creates_nothing_and_does_not_fail(): void
    {
        [, $document] = $this->gutachten($this->pdfWithoutImages());

        $this->runJob($document);

        $this->assertSame(0, VehicleReportDocument::where('document_type', ExtractGutachtenImages::DOCUMENT_TYPE)->count());
    }

    public function test_a_document_that_is_not_a_pdf_is_ignored(): void
    {
        [, $document] = $this->gutachten('not a pdf at all', 'schadenbild.jpg');

        $this->runJob($document);

        $this->assertSame(0, VehicleReportDocument::where('document_type', ExtractGutachtenImages::DOCUMENT_TYPE)->count());
    }

    public function test_a_broken_pdf_leaves_the_upload_intact(): void
    {
        [, $document] = $this->gutachten('%PDF-1.7 broken bytes');

        $this->runJob($document);

        $this->assertSame(0, VehicleReportDocument::where('document_type', ExtractGutachtenImages::DOCUMENT_TYPE)->count());
        $this->assertTrue(Storage::disk('documents')->exists($document->fresh()->path));
    }

    public function test_a_missing_document_is_a_no_op(): void
    {
        [, $document] = $this->gutachten($this->pdfWithImages(1));
        $documentId = $document->id;
        $document->delete();

        app()->call([new ExtractGutachtenImages($documentId), 'handle']);

        $this->assertSame(0, VehicleReportDocument::where('document_type', ExtractGutachtenImages::DOCUMENT_TYPE)->count());
    }

    public function test_running_twice_does_not_duplicate_images(): void
    {
        [$order, $document] = $this->gutachten($this->pdfWithImages(2));

        $this->runJob($document);
        $this->runJob($document);

        $this->assertCount(2, $this->extractedImages($order));
    }

    public function test_extracted_images_never_reach_another_order(): void
    {
        [$order, $document] = $this->gutachten($this->pdfWithImages(2));
        [$otherOrder] = $this->gutachten($this->pdfWithImages(1));

        $this->runJob($document);

        $this->assertCount(2, $this->extractedImages($order));
        $this->assertCount(0, $this->extractedImages($otherOrder));
    }

    public function test_uploading_a_gutachten_queues_image_extraction(): void
    {
        Bus::fake();
        [$order, $vehicle] = $this->orderWithVehicle();

        $this->actingAs($this->admin())
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => $order->auftragsnummer,
                'document_type' => 'gutachten',
                'file' => UploadedFile::fake()->create('erstgutachten.pdf', 60, 'application/pdf'),
            ])
            ->assertSessionHas('success');

        $document = VehicleReportDocument::sole();

        Bus::assertDispatched(ExtractGutachtenImages::class, fn (ExtractGutachtenImages $job) => $job->documentId === $document->id);
    }

    public function test_an_invoice_upload_does_not_queue_image_extraction(): void
    {
        Bus::fake();
        [$order, $vehicle] = $this->orderWithVehicle();

        $this->actingAs($this->admin())
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => $order->auftragsnummer,
                'document_type' => 'rechnung',
                'file' => UploadedFile::fake()->create('rechnung.pdf', 60, 'application/pdf'),
            ])
            ->assertSessionHas('success');

        Bus::assertNotDispatched(ExtractGutachtenImages::class);
    }

    public function test_tim_images_keep_their_own_document_type_and_link(): void
    {
        [$order] = $this->orderWithVehicle();

        $assessmentId = DB::table('vehicle_assessments')->insertGetId([
            'uid' => (string) Str::uuid(),
            'auftragsnummer' => $order->auftragsnummer,
            'created_at' => now(),
        ]);

        $assessmentDocumentId = DB::table('assessment_documents')->insertGetId([
            'assessment_id' => $assessmentId,
            'doc_type' => 'AnsichtsFoto',
            'caption' => 'Beschädigung 1: Stoßfänger hinten',
            's3_bucket' => 'bucket',
            's3_key' => 'tim/photo.jpg',
            's3_url' => 's3://bucket/photo.jpg',
            'created_at' => now(),
        ]);

        $tim = VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => 'AnsichtsFoto',
            'path' => "vehicle-reports/{$order->auftragsnummer}/tim-photo.jpg",
            'source_assessment_document_id' => $assessmentDocumentId,
        ]);

        $this->assertSame('AnsichtsFoto', $tim->fresh()->document_type);
        $this->assertSame($assessmentDocumentId, $tim->fresh()->source_assessment_document_id);
        $this->assertNotSame(ExtractGutachtenImages::DOCUMENT_TYPE, $tim->fresh()->document_type);
    }

    public function test_the_extractor_reports_metadata_for_each_image(): void
    {
        $collected = [];

        $count = app(GutachtenImageExtractor::class)->extract($this->pdfWithImages(2), function ($image) use (&$collected) {
            $collected[] = $image;
        });

        $this->assertSame(2, $count);
        $this->assertSame([1, 1], array_column($collected, 'pageNumber'));
        $this->assertSame(['image/jpeg', 'image/jpeg'], array_column($collected, 'mimeType'));

        foreach ($collected as $image) {
            $this->assertGreaterThanOrEqual(400, $image->width);
            $this->assertGreaterThanOrEqual(260, $image->height);
        }
    }

    public function test_temporary_files_are_always_removed(): void
    {
        $before = count(glob(sys_get_temp_dir().'/gutachten-images-*') ?: []);

        app(GutachtenImageExtractor::class)->extract($this->pdfWithImages(1), fn () => null);

        $this->assertSame($before, count(glob(sys_get_temp_dir().'/gutachten-images-*') ?: []));
    }

    public function test_only_damage_appendix_images_are_kept(): void
    {
        [$order, $document] = $this->gutachten($this->pdfWithAppendix());

        $this->runJob($document);

        $images = $this->extractedImages($order);

        $this->assertCount(3, $images);

        foreach ($images as $image) {
            $this->assertStringContainsString('Seite 5', (string) $image->document_title);
        }
    }

    public function test_overview_photos_are_excluded(): void
    {
        [$order, $document] = $this->gutachten($this->pdfWithAppendix());

        $this->runJob($document);

        $titles = array_map(fn ($image) => (string) $image->document_title, $this->extractedImages($order));

        $this->assertSame([], array_filter($titles, fn (string $title) => str_contains($title, 'Seite 3') || str_contains($title, 'Seite 4')));
    }

    public function test_a_prose_mention_of_the_appendix_does_not_start_it(): void
    {
        $pages = app(PdfTextExtractor::class)->extract($this->input($this->pdfWithAppendix()));

        $this->assertSame([5], app(GutachtenPhotoPages::class)->detect($pages));
    }

    public function test_a_pdf_without_a_damage_appendix_keeps_every_image(): void
    {
        [$order, $document] = $this->gutachten($this->buildPdf([
            ['text' => ['Übersichtsfotos'], 'images' => 2],
            ['text' => ['Fahrzeugansicht vorne links'], 'images' => 2],
        ]));

        $this->runJob($document);

        $this->assertCount(4, $this->extractedImages($order));
    }

    public function test_detection_without_a_text_layer_keeps_every_image(): void
    {
        config(['gutachten.pdftotext.min_characters' => 100000]);
        [$order, $document] = $this->gutachten($this->pdfWithAppendix());

        $this->runJob($document);

        $this->assertCount(7, $this->extractedImages($order));
    }

    public function test_detection_failure_never_breaks_the_upload(): void
    {
        config(['gutachten.pdftotext.binary' => '/nonexistent/bin/pdftotext']);
        [$order, $document] = $this->gutachten($this->pdfWithAppendix());

        $this->runJob($document);

        $this->assertCount(7, $this->extractedImages($order));
        $this->assertTrue(Storage::disk('documents')->exists($document->fresh()->path));
    }

    public function test_the_detector_reads_the_standalone_heading_and_captions(): void
    {
        $detector = app(GutachtenPhotoPages::class);

        $this->assertNull($detector->detect([
            new PdfPageText(1, ['Anlagen: 41 Übersichtsfotos, 5 Beschädigungsfotos']),
        ]));

        $this->assertSame([2], $detector->detect([
            new PdfPageText(1, ['Übersichtsfotos']),
            new PdfPageText(2, ['Beschädigungsfotos']),
        ]));

        $this->assertSame([3], $detector->detect([
            new PdfPageText(3, ['Beschädigung 1: Stoßfänger hinten - Kratzer']),
        ]));

        $this->assertSame([1, 2], $detector->detect([
            new PdfPageText(1, ['Schadenbilder']),
            new PdfPageText(2, ['Beschädigung 1: Tür']),
            new PdfPageText(3, ['Übersichtsfotos']),
        ]));
    }

    public function test_the_real_gutachten_maps_its_damage_photos_to_damage_numbers(): void
    {
        $pages = $this->realGutachtenPages();
        $images = array_map(fn (int $index) => $this->imageMetadata(14, $index), range(68, 72));

        $mapping = app(GutachtenDamageImageMapper::class)->map($pages, $images);

        $this->assertSame([68 => 1, 69 => 1, 70 => 2, 71 => 2, 72 => 2], $mapping);
    }

    public function test_several_images_share_one_damage_number(): void
    {
        $pages = [new PdfPageText(3, ['Beschädigungsfotos', 'Beschädigung 4: Tür hinten links - Delle'])];
        $images = [$this->imageMetadata(3, 0), $this->imageMetadata(3, 1), $this->imageMetadata(3, 2)];

        $this->assertSame([0 => 4, 1 => 4, 2 => 4], app(GutachtenDamageImageMapper::class)->map($pages, $images));
    }

    public function test_captions_map_to_images_in_reading_order(): void
    {
        $pages = [new PdfPageText(3, ['Beschädigung 1: Tür', 'Beschädigung 2: Dach', 'Beschädigung 2: Dach Detail'])];
        $images = [$this->imageMetadata(3, 0), $this->imageMetadata(3, 1), $this->imageMetadata(3, 2)];

        $this->assertSame([0 => 1, 1 => 2, 2 => 2], app(GutachtenDamageImageMapper::class)->map($pages, $images));
    }

    public function test_an_ambiguous_page_keeps_every_damage_number_null(): void
    {
        $pages = [new PdfPageText(3, ['Beschädigung 1: Tür', 'Beschädigung 2: Dach'])];
        $images = [$this->imageMetadata(3, 0), $this->imageMetadata(3, 1), $this->imageMetadata(3, 2)];

        $this->assertSame([0 => null, 1 => null, 2 => null], app(GutachtenDamageImageMapper::class)->map($pages, $images));
    }

    public function test_a_page_without_captions_keeps_null(): void
    {
        $pages = [new PdfPageText(3, ['Übersichtsfotos', 'Fahrzeugansicht vorne links'])];
        $images = [$this->imageMetadata(3, 0), $this->imageMetadata(3, 1)];

        $this->assertSame([0 => null, 1 => null], app(GutachtenDamageImageMapper::class)->map($pages, $images));
    }

    public function test_images_of_other_pages_are_mapped_independently(): void
    {
        $pages = [
            new PdfPageText(3, ['Beschädigung 1: Tür']),
            new PdfPageText(4, ['Übersichtsfotos']),
        ];
        $images = [$this->imageMetadata(3, 0), $this->imageMetadata(4, 1)];

        $this->assertSame([0 => 1, 1 => null], app(GutachtenDamageImageMapper::class)->map($pages, $images));
    }

    public function test_stored_images_carry_their_damage_number_in_the_title(): void
    {
        [$order, $document] = $this->gutachten($this->buildPdf([
            ['text' => ['Wertmindernde Faktoren'], 'images' => 0],
            ['text' => ['Übersichtsfotos'], 'images' => 2],
            ['text' => ['Beschädigungsfotos', 'Beschädigung 1: Stossfänger hinten', 'Beschädigung 1: Stossfänger Detail', 'Beschädigung 2: Tür links'], 'images' => 3],
        ]));

        $this->runJob($document);

        $titles = array_map(fn ($image) => (string) $image->document_title, $this->extractedImages($order));

        $this->assertCount(3, $titles);
        $this->assertStringStartsWith('Beschädigung 1: ', $titles[0]);
        $this->assertStringStartsWith('Beschädigung 1: ', $titles[1]);
        $this->assertStringStartsWith('Beschädigung 2: ', $titles[2]);
    }

    public function test_images_without_captions_keep_their_plain_title(): void
    {
        [$order, $document] = $this->gutachten($this->buildPdf([
            ['text' => ['Übersichtsfotos'], 'images' => 2],
        ]));

        $this->runJob($document);

        foreach ($this->extractedImages($order) as $image) {
            $this->assertStringStartsWith('Gutachten Seite ', (string) $image->document_title);
        }
    }

    private function imageMetadata(int $page, int $index): ExtractedImage
    {
        return new ExtractedImage(path: null, pageNumber: $page, index: $index, width: 1280, height: 960, mimeType: 'image/jpeg');
    }

    private function realGutachtenPages(): array
    {
        $text = (string) file_get_contents(base_path('tests/Fixtures/Gutachten/gut.txt'));
        $pages = [];

        foreach (explode("\f", $text) as $index => $page) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $page)), fn (string $line) => $line !== ''));
            $pages[] = new PdfPageText($index + 1, $lines);
        }

        return $pages;
    }

    public function test_the_real_tuv_sued_gutachten_resolves_to_its_damage_photo_page(): void
    {
        $text = file_get_contents(base_path('tests/Fixtures/Gutachten/gut.txt'));
        $pages = [];

        foreach (explode("\f", (string) $text) as $index => $page) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $page)), fn (string $line) => $line !== ''));
            $pages[] = new PdfPageText($index + 1, $lines);
        }

        $this->assertSame([14], app(GutachtenPhotoPages::class)->detect($pages));
    }

    private function input(string $contents): AppraisalExtractionInput
    {
        return new AppraisalExtractionInput(
            extractionId: 'run',
            orderId: 'order',
            auftragsnummer: 'AUF-00000001',
            vehicleId: 'vehicle',
            documentId: 'document',
            fileName: 'gutachten.pdf',
            contents: $contents,
            sha256: hash('sha256', $contents),
        );
    }

    private function runJob(VehicleReportDocument $document): void
    {
        app()->call([new ExtractGutachtenImages($document->id), 'handle']);
    }

    private function extractedImages(LeasybackOrder $order): array
    {
        return VehicleReportDocument::where('auftragsnummer', $order->auftragsnummer)
            ->where('document_type', ExtractGutachtenImages::DOCUMENT_TYPE)
            ->orderBy('path')
            ->get()
            ->all();
    }

    private function admin(): User
    {
        return User::firstWhere('user_type', UserType::Admin) ?? User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function orderWithVehicle(): array
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

        return [$order, $vehicle];
    }

    private function gutachten(string $contents, string $name = 'erstgutachten.pdf'): array
    {
        [$order] = $this->orderWithVehicle();
        $path = "vehicle-reports/{$order->auftragsnummer}/".Str::uuid()."-{$name}";

        Storage::disk('documents')->put($path, $contents);

        return [$order, VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => 'gutachten',
            'document_title' => $name,
            'path' => $path,
        ])];
    }

    private function pdfWithImages(int $count, int $width = 640, int $height = 480): string
    {
        return $this->buildPdf([['text' => ['Wertmindernde Faktoren'], 'images' => $count]], $width, $height);
    }

    private function pdfWithoutImages(): string
    {
        return $this->buildPdf([['text' => ['Wertmindernde Faktoren'], 'images' => 0]], 0, 0);
    }

    private function pdfWithAppendix(): string
    {
        return $this->buildPdf([
            ['text' => ['Wertmindernde Faktoren'], 'images' => 0],
            ['text' => ['Anlagen: 41 Übersichtsfotos, 5 Beschädigungsfotos'], 'images' => 0],
            ['text' => ['Übersichtsfotos'], 'images' => 2],
            ['text' => ['Fahrzeugansicht diagonal vorne links'], 'images' => 2],
            ['text' => ['Beschädigungsfotos', 'Beschädigung 1: Stossfänger hinten - Kratzer'], 'images' => 3],
        ]);
    }

    private function jpeg(int $width, int $height, int $seed): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, (40 + $seed * 60) % 256, 90, 140));
        imagefilledrectangle($image, 10, 10, (int) ($width / 2), (int) ($height / 2), imagecolorallocate($image, 220, (30 + $seed * 40) % 256, 60));

        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function buildPdf(array $pages, int $width = 640, int $height = 480): string
    {
        $objects = [1 => '', 2 => '', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'];
        $next = 4;
        $pageObjects = [];
        $imageIndex = 0;

        foreach ($pages as $page) {
            $imageRefs = [];

            for ($image = 0; $image < (int) $page['images']; $image++) {
                $objects[$next] = $this->imageObject($this->jpeg($width, $height, $imageIndex), $width, $height);
                $imageRefs['Im'.$imageIndex] = $next;
                $next++;
                $imageIndex++;
            }

            $content = '';
            $y = 780;

            foreach ([...(array) $page['text'], self::FILLER] as $text) {
                $content .= sprintf(' BT /F1 11 Tf 40 %d Td (%s) Tj ET', $y, $this->escape((string) $text));
                $y -= 20;
            }
            $offset = 0;

            foreach ($imageRefs as $name => $ignored) {
                $content .= sprintf(' q %d 0 0 %d 40 %d cm /%s Do Q', $width, $height, 500 - $offset * 30, $name);
                $offset++;
            }

            $contentObject = $next++;
            $objects[$contentObject] = '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";

            $xobjects = '';

            foreach ($imageRefs as $name => $number) {
                $xobjects .= "/{$name} {$number} 0 R ";
            }

            $pageObject = $next++;
            $objects[$pageObject] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> /XObject << {$xobjects}>> >> /Contents {$contentObject} 0 R >>";
            $pageObjects[] = "{$pageObject} 0 R";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageObjects).'] /Count '.count($pages).' >>';

        ksort($objects);

        return $this->assemble($objects);
    }

    private function imageObject(string $bytes, int $width, int $height): string
    {
        return "<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($bytes)." >>\nstream\n{$bytes}\nendstream";
    }

    private function escape(string $text): string
    {
        $encoded = iconv('UTF-8', 'CP1252//TRANSLIT', $text);

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded === false ? $text : $encoded);
    }

    private function assemble(array $objects): string
    {
        $pdf = "%PDF-1.7\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }
}
