<?php

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleReportImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    public function test_an_admin_can_view_a_report_image(): void
    {
        $document = $this->reportDocument($this->order(), Str::uuid().'.jpg', UploadedFile::fake()->image('damage.jpg'));

        $response = $this->actingAs($this->admin())->get(route('admin.vehicles.reports.image', $document->id));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString($document->path, (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(Storage::disk('documents')->get($document->path), $response->streamedContent());
    }

    public function test_a_png_is_served_with_its_own_content_type(): void
    {
        $document = $this->reportDocument($this->order(), Str::uuid().'.png', UploadedFile::fake()->image('damage.png'));

        $this->actingAs($this->admin())
            ->get(route('admin.vehicles.reports.image', $document->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_a_customer_cannot_view_a_report_image_even_on_their_own_vehicle(): void
    {
        $owner = User::factory()->create(['user_type' => UserType::Privatkunde]);
        $document = $this->reportDocument(
            $this->order($owner),
            Str::uuid().'.jpg',
            UploadedFile::fake()->image('damage.jpg'),
            ['published' => true],
        );

        $this->actingAs($owner)->get(route('admin.vehicles.reports.image', $document->id))->assertForbidden();
    }

    public function test_a_guest_cannot_view_a_report_image(): void
    {
        $document = $this->reportDocument($this->order(), Str::uuid().'.jpg', UploadedFile::fake()->image('damage.jpg'));

        $this->get(route('admin.vehicles.reports.image', $document->id))->assertRedirect(route('login'));
    }

    public function test_a_non_image_document_is_not_streamed(): void
    {
        $document = $this->reportDocument($this->order(), 'gutachten.pdf', UploadedFile::fake()->create('gutachten.pdf', 10, 'application/pdf'));

        $this->actingAs($this->admin())->get(route('admin.vehicles.reports.image', $document->id))->assertNotFound();
    }

    public function test_an_unknown_document_is_not_found(): void
    {
        $this->actingAs($this->admin())->get(route('admin.vehicles.reports.image', Str::uuid()))->assertNotFound();
    }

    public function test_an_image_whose_file_is_missing_is_not_found(): void
    {
        $document = $this->reportDocument($this->order(), Str::uuid().'.jpg', UploadedFile::fake()->image('damage.jpg'));
        Storage::disk('documents')->delete($document->path);

        $this->actingAs($this->admin())->get(route('admin.vehicles.reports.image', $document->id))->assertNotFound();
    }

    public function test_the_admin_order_detail_flags_which_report_documents_are_images(): void
    {
        $order = $this->order();
        $image = $this->reportDocument($order, Str::uuid().'.JPEG', UploadedFile::fake()->image('damage.jpg'));
        $pdf = $this->reportDocument($order, 'gutachten.pdf', UploadedFile::fake()->create('gutachten.pdf', 10, 'application/pdf'));

        $response = $this->actingAs($this->admin())->get(route('admin.orders.show', $order->id));

        $response->assertOk();

        $flags = collect($response->viewData('page')['props']['order']['report_documents'])->pluck('is_image', 'id');

        $this->assertTrue($flags[$image->id]);
        $this->assertFalse($flags[$pdf->id]);
    }

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function order(?User $owner = null): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => $owner?->id ?? User::factory()->create(['user_type' => UserType::Privatkunde])->id,
        ]);

        return LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'inspected',
        ]);
    }

    private function reportDocument(LeasybackOrder $order, string $name, UploadedFile $file, array $overrides = []): VehicleReportDocument
    {
        $path = "vehicle-reports/{$order->auftragsnummer}/{$name}";

        Storage::disk('documents')->put($path, $file->getContent());

        return VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => 'gutachten',
            'path' => $path,
            ...$overrides,
        ]);
    }
}
