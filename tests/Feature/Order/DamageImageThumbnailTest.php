<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use App\Modules\UserProfile\Vehicle\Services\DamageImageThumbnailService;
use App\Modules\UserProfile\Vehicle\Support\ReportDocumentImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Damage galleries used to load full-size appraisal photos, several megabytes
 * each, to fill a grid of 88px thumbnails. A derived WebP copy is served
 * instead, and because it is only an optimisation every path here also asserts
 * the original keeps working when the thumbnail is absent.
 */
class DamageImageThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        Storage::fake('documents');
    }

    public function test_uploading_a_damage_image_generates_a_webp_thumbnail(): void
    {
        $document = $this->uploadImage('schaden.jpg', 1600, 900);
        $thumbnailPath = ReportDocumentImage::thumbnailPathFor($document->path);

        Storage::disk('documents')->assertExists($thumbnailPath);

        $size = getimagesizefromstring(Storage::disk('documents')->get($thumbnailPath));

        $this->assertSame('image/webp', $size['mime']);
        $this->assertSame(DamageImageThumbnailService::WIDTH, $size[0]);
        $this->assertSame(225, $size[1]);
    }

    public function test_the_thumbnail_is_smaller_than_the_original(): void
    {
        $document = $this->uploadImage('schaden.jpg', 1600, 900);
        $disk = Storage::disk('documents');

        $this->assertLessThan(
            $disk->size($document->path),
            $disk->size(ReportDocumentImage::thumbnailPathFor($document->path)),
        );
    }

    public function test_the_original_image_is_left_untouched_and_still_served(): void
    {
        $document = $this->uploadImage('schaden.jpg', 1600, 900);

        $size = getimagesizefromstring(Storage::disk('documents')->get($document->path));

        $this->assertSame(1600, $size[0]);
        $this->assertSame(900, $size[1]);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.vehicles.reports.image', $document->id));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_the_admin_route_serves_the_thumbnail_when_asked_for_one(): void
    {
        $document = $this->uploadImage('schaden.jpg', 1600, 900);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.vehicles.reports.image', ['documentId' => $document->id, 'size' => 'thumb']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(
            Storage::disk('documents')->get(ReportDocumentImage::thumbnailPathFor($document->path)),
            $response->streamedContent(),
        );
    }

    public function test_a_missing_thumbnail_falls_back_to_the_original(): void
    {
        $order = $this->inspectedOrder();
        $document = $this->storedImage($order);

        Storage::disk('documents')->assertMissing(ReportDocumentImage::thumbnailPathFor($document->path));

        $response = $this->actingAs($this->admin())
            ->get(route('admin.vehicles.reports.image', ['documentId' => $document->id, 'size' => 'thumb']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(Storage::disk('documents')->get($document->path), $response->streamedContent());
    }

    public function test_an_unreadable_image_does_not_break_the_upload(): void
    {
        $vehicle = Vehicle::factory()->create();
        LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id, 'auftragsnummer' => 'AUF-THUMB-01']);

        $this->actingAs($this->admin())
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => 'AUF-THUMB-01',
                'document_type' => 'gutachten',
                'file' => UploadedFile::fake()->createWithContent('schaden.jpg', 'not actually an image'),
            ])
            ->assertRedirect();

        $document = VehicleReportDocument::where('auftragsnummer', 'AUF-THUMB-01')->firstOrFail();

        Storage::disk('documents')->assertExists($document->path);
        Storage::disk('documents')->assertMissing(ReportDocumentImage::thumbnailPathFor($document->path));

        $this->actingAs($this->admin())
            ->get(route('admin.vehicles.reports.image', ['documentId' => $document->id, 'size' => 'thumb']))
            ->assertOk();
    }

    public function test_a_pdf_upload_produces_no_thumbnail(): void
    {
        $vehicle = Vehicle::factory()->create();
        LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id, 'auftragsnummer' => 'AUF-THUMB-02']);

        $this->actingAs($this->admin())
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => 'AUF-THUMB-02',
                'document_type' => 'rechnung',
                'file' => UploadedFile::fake()->create('rechnung.pdf', 40, 'application/pdf'),
            ])
            ->assertRedirect();

        $document = VehicleReportDocument::where('auftragsnummer', 'AUF-THUMB-02')->firstOrFail();

        $this->assertNull(ReportDocumentImage::thumbnailPathFor($document->path));
        $this->assertCount(0, Storage::disk('documents')->files(dirname($document->path).'/thumbnails'));
    }

    public function test_deleting_the_document_removes_its_thumbnail(): void
    {
        $document = $this->uploadImage('schaden.jpg', 1600, 900);
        $thumbnailPath = ReportDocumentImage::thumbnailPathFor($document->path);

        Storage::disk('documents')->assertExists($thumbnailPath);

        $this->actingAs($this->admin())
            ->delete(route('admin.vehicles.reports.delete', $document->id))
            ->assertRedirect();

        Storage::disk('documents')->assertMissing($thumbnailPath);
        Storage::disk('documents')->assertMissing($document->path);
    }

    public function test_the_workshop_serves_a_thumbnail_for_an_attached_image(): void
    {
        $order = $this->inspectedOrder();
        $document = $this->storedImage($order);
        (new DamageImageThumbnailService)->generate($document->path);
        $this->positionWithImages($order, [$document->id]);
        $token = $this->quotationToken($order);

        $response = $this->get(route('workshop.quotations.images.show', [
            'token' => $token,
            'documentId' => $document->id,
            'size' => 'thumb',
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_workshop_payload_exposes_a_thumbnail_url_per_image(): void
    {
        $order = $this->inspectedOrder();
        $document = $this->storedImage($order);
        $this->positionWithImages($order, [$document->id]);
        $token = $this->quotationToken($order);

        $response = $this->get(route('workshop.quotations.show', $token));
        $response->assertOk();

        $image = $response->viewData('page')['props']['quotation']['positions'][0]['images'][0];

        $this->assertSame(
            route('workshop.quotations.images.show', [$token, $document->id, 'size' => 'thumb']),
            $image['thumbnail_url'],
        );
        $this->assertStringNotContainsString('size=thumb', $image['url']);
    }

    public function test_an_unrelated_token_cannot_reach_a_thumbnail(): void
    {
        $order = $this->inspectedOrder();
        $document = $this->storedImage($order);
        (new DamageImageThumbnailService)->generate($document->path);
        $this->positionWithImages($order, [$document->id]);

        $this->get(route('workshop.quotations.images.show', [
            'token' => Str::random(64),
            'documentId' => $document->id,
            'size' => 'thumb',
        ]))->assertNotFound();
    }

    public function test_a_guest_cannot_reach_an_admin_thumbnail(): void
    {
        $document = $this->uploadImage('schaden.jpg', 800, 600);

        // uploadImage() had to act as an admin to create the document; that
        // session would otherwise still be authenticated here.
        auth()->logout();
        $this->flushSession();

        $this->get(route('admin.vehicles.reports.image', ['documentId' => $document->id, 'size' => 'thumb']))
            ->assertRedirect(route('login'));
    }

    public function test_a_non_admin_cannot_reach_an_admin_thumbnail(): void
    {
        $document = $this->uploadImage('schaden.jpg', 800, 600);
        $customer = User::factory()->create(['user_type' => UserType::Privatkunde]);

        $this->actingAs($customer)
            ->get(route('admin.vehicles.reports.image', ['documentId' => $document->id, 'size' => 'thumb']))
            ->assertForbidden();
    }

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function uploadImage(string $name, int $width, int $height): VehicleReportDocument
    {
        $vehicle = Vehicle::factory()->create();
        $auftragsnummer = 'AUF-'.Str::upper(Str::random(8));

        LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'auftragsnummer' => $auftragsnummer,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.vehicles.reports.upload', $vehicle->vehicle_id), [
                'auftragsnummer' => $auftragsnummer,
                'document_type' => 'gutachten',
                'file' => UploadedFile::fake()->image($name, $width, $height),
            ])
            ->assertRedirect();

        return VehicleReportDocument::where('auftragsnummer', $auftragsnummer)->firstOrFail();
    }

    private function storedImage(LeasybackOrder $order): VehicleReportDocument
    {
        $name = Str::uuid().'.jpg';
        $path = "vehicle-reports/{$order->auftragsnummer}/{$name}";

        Storage::disk('documents')->put($path, UploadedFile::fake()->image($name, 1600, 900)->getContent());

        return VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => 'gutachten',
            'path' => $path,
        ]);
    }

    private function inspectedOrder(): LeasybackOrder
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

    private function positionWithImages(LeasybackOrder $order, array $documentIds): AppraisalPosition
    {
        return AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'sort_order' => 0,
            'component' => 'Stoßfänger vorne',
            'damage_description' => 'Kratzer',
            'original_amount_net' => '500.00',
            'source' => AppraisalPosition::SOURCE_MANUAL,
            'damage_image_document_ids' => $documentIds,
        ]);
    }

    private function quotationToken(LeasybackOrder $order): string
    {
        $token = Str::random(64);

        WorkshopQuotation::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'token_hash' => hash('sha256', $token),
            'workshop_label' => 'Karosserie Meier',
            'show_appraisal_amounts' => true,
            'expires_at' => now()->addDays(14),
        ]);

        return $token;
    }
}
