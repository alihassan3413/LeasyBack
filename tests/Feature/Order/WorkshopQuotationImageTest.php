<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkshopQuotationImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        Storage::fake('documents');
    }

    public function test_a_workshop_can_view_an_image_attached_to_one_of_its_positions(): void
    {
        $order = $this->inspectedOrder();
        $document = $this->reportImage($order);
        $this->positionWithImages($order, [$document->id]);
        $token = $this->quotationToken($order);

        $response = $this->get(route('workshop.quotations.images.show', [$token, $document->id]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString($document->path, (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(Storage::disk('documents')->get($document->path), $response->streamedContent());
    }

    public function test_an_expired_link_cannot_reach_an_image(): void
    {
        $order = $this->inspectedOrder();
        $document = $this->reportImage($order);
        $this->positionWithImages($order, [$document->id]);
        $token = $this->quotationToken($order, ['expires_at' => now()->subMinute()]);

        $this->get(route('workshop.quotations.images.show', [$token, $document->id]))->assertNotFound();
    }

    public function test_a_revoked_link_cannot_reach_an_image(): void
    {
        $order = $this->inspectedOrder();
        $document = $this->reportImage($order);
        $this->positionWithImages($order, [$document->id]);
        $token = $this->quotationToken($order, ['revoked_at' => now()]);

        $this->get(route('workshop.quotations.images.show', [$token, $document->id]))->assertNotFound();
    }

    public function test_a_submitted_link_cannot_reach_an_image(): void
    {
        $order = $this->inspectedOrder();
        $document = $this->reportImage($order);
        $this->positionWithImages($order, [$document->id]);
        $token = $this->quotationToken($order, ['submitted_at' => now()]);

        $this->get(route('workshop.quotations.images.show', [$token, $document->id]))->assertNotFound();
    }

    public function test_a_link_on_a_cancelled_order_cannot_reach_an_image(): void
    {
        $order = $this->inspectedOrder();
        $document = $this->reportImage($order);
        $this->positionWithImages($order, [$document->id]);
        $token = $this->quotationToken($order);
        $order->update(['order_status' => 'cancelled']);

        $this->get(route('workshop.quotations.images.show', [$token, $document->id]))->assertNotFound();
    }

    public function test_a_link_cannot_reach_an_image_of_another_order(): void
    {
        $order = $this->inspectedOrder();
        $this->positionWithImages($order, [$this->reportImage($order)->id]);
        $token = $this->quotationToken($order);

        $otherOrder = $this->inspectedOrder();
        $foreign = $this->reportImage($otherOrder);
        $this->positionWithImages($otherOrder, [$foreign->id]);

        $this->get(route('workshop.quotations.images.show', [$token, $foreign->id]))->assertNotFound();
    }

    public function test_a_link_cannot_reach_a_foreign_image_even_when_a_position_references_it(): void
    {
        $order = $this->inspectedOrder();
        $foreign = $this->reportImage($this->inspectedOrder());
        $this->positionWithImages($order, [$foreign->id]);
        $token = $this->quotationToken($order);

        $this->get(route('workshop.quotations.images.show', [$token, $foreign->id]))->assertNotFound();
    }

    public function test_a_link_cannot_reach_an_order_document_that_no_position_references(): void
    {
        $order = $this->inspectedOrder();
        $this->positionWithImages($order, [$this->reportImage($order)->id]);
        $unattached = $this->reportImage($order);
        $token = $this->quotationToken($order);

        $this->get(route('workshop.quotations.images.show', [$token, $unattached->id]))->assertNotFound();
    }

    public function test_a_link_cannot_stream_a_non_image_document(): void
    {
        $order = $this->inspectedOrder();
        $pdf = $this->reportDocument($order, 'gutachten.pdf', UploadedFile::fake()->create('gutachten.pdf', 10, 'application/pdf'));
        $this->positionWithImages($order, [$pdf->id]);
        $token = $this->quotationToken($order);

        $this->get(route('workshop.quotations.images.show', [$token, $pdf->id]))->assertNotFound();
    }

    public function test_an_unknown_token_cannot_reach_an_image(): void
    {
        $order = $this->inspectedOrder();
        $document = $this->reportImage($order);
        $this->positionWithImages($order, [$document->id]);
        $this->quotationToken($order);

        $this->get(route('workshop.quotations.images.show', [Str::random(64), $document->id]))->assertNotFound();
    }

    public function test_the_payload_lists_the_images_of_a_position_as_token_scoped_urls(): void
    {
        $order = $this->inspectedOrder();
        $first = $this->reportImage($order);
        $second = $this->reportImage($order);
        $this->positionWithImages($order, [$first->id, $second->id]);
        $token = $this->quotationToken($order);

        $position = $this->publicPayload($token)['positions'][0];

        $this->assertSame(
            ['id', 'component', 'damage_description', 'repair_method', 'requested_amount_net', 'images'],
            array_keys($position),
        );
        $this->assertSame([
            [
                'id' => $first->id,
                'url' => route('workshop.quotations.images.show', [$token, $first->id]),
                'thumbnail_url' => route('workshop.quotations.images.show', [$token, $first->id, 'size' => 'thumb']),
            ],
            [
                'id' => $second->id,
                'url' => route('workshop.quotations.images.show', [$token, $second->id]),
                'thumbnail_url' => route('workshop.quotations.images.show', [$token, $second->id, 'size' => 'thumb']),
            ],
        ], $position['images']);

        $this->get($position['images'][0]['url'])->assertOk();
    }

    public function test_the_payload_never_exposes_storage_paths(): void
    {
        $order = $this->inspectedOrder();
        $document = $this->reportImage($order);
        $this->positionWithImages($order, [$document->id]);
        $token = $this->quotationToken($order);

        $encoded = (string) json_encode($this->publicPayload($token));

        $this->assertStringNotContainsString($document->path, $encoded);
        $this->assertStringNotContainsString('vehicle-reports', $encoded);
        $this->assertStringNotContainsString($order->auftragsnummer, $encoded);
    }

    public function test_a_position_without_images_has_an_empty_images_list(): void
    {
        $order = $this->inspectedOrder();
        $this->positionWithImages($order, []);
        $token = $this->quotationToken($order);

        $this->assertSame([], $this->publicPayload($token)['positions'][0]['images']);
    }

    public function test_the_payload_excludes_a_document_of_another_order(): void
    {
        $order = $this->inspectedOrder();
        $own = $this->reportImage($order);
        $foreign = $this->reportImage($this->inspectedOrder());
        $this->positionWithImages($order, [$own->id, $foreign->id]);
        $token = $this->quotationToken($order);

        $this->assertSame([$own->id], array_column($this->publicPayload($token)['positions'][0]['images'], 'id'));
    }

    public function test_the_payload_excludes_a_document_that_is_not_an_image(): void
    {
        $order = $this->inspectedOrder();
        $image = $this->reportImage($order);
        $pdf = $this->reportDocument($order, 'gutachten.pdf', UploadedFile::fake()->create('gutachten.pdf', 10, 'application/pdf'));
        $this->positionWithImages($order, [$pdf->id, $image->id]);
        $token = $this->quotationToken($order);

        $this->assertSame([$image->id], array_column($this->publicPayload($token)['positions'][0]['images'], 'id'));
    }

    public function test_the_payload_excludes_an_image_whose_file_is_missing(): void
    {
        $order = $this->inspectedOrder();
        $image = $this->reportImage($order);
        $missing = $this->reportImage($order);
        Storage::disk('documents')->delete($missing->path);
        $this->positionWithImages($order, [$image->id, $missing->id]);
        $token = $this->quotationToken($order);

        $this->assertSame([$image->id], array_column($this->publicPayload($token)['positions'][0]['images'], 'id'));
    }

    private function publicPayload(string $token): array
    {
        $response = $this->get(route('workshop.quotations.show', $token));
        $response->assertOk();

        return $response->viewData('page')['props']['quotation'];
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

    private function reportImage(LeasybackOrder $order): VehicleReportDocument
    {
        $name = Str::uuid().'.jpg';

        return $this->reportDocument($order, $name, UploadedFile::fake()->image($name));
    }

    private function reportDocument(LeasybackOrder $order, string $name, UploadedFile $file): VehicleReportDocument
    {
        $path = "vehicle-reports/{$order->auftragsnummer}/{$name}";

        Storage::disk('documents')->put($path, $file->getContent());

        return VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => 'gutachten',
            'path' => $path,
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

    private function quotationToken(LeasybackOrder $order, array $overrides = []): string
    {
        $token = Str::random(64);

        WorkshopQuotation::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'token_hash' => hash('sha256', $token),
            'workshop_label' => 'Karosserie Meier',
            'show_appraisal_amounts' => true,
            'expires_at' => now()->addDays(14),
            ...$overrides,
        ]);

        return $token;
    }
}
