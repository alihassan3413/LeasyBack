<?php

namespace Tests\Feature\PartnerApi;

use App\Enums\OrderStatus;
use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\PartnerApi\Services\PartnerDocumentDownloadLink;
use App\Modules\UserProfile\Vehicle\Models\VehicleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerOrderHistory;
use Tests\TestCase;

class PartnerDocumentEndpointTest extends TestCase
{
    use BuildsPartnerClients;
    use BuildsPartnerOrderHistory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    /**
     * Put real bytes behind a document row so the streaming path is exercised
     * rather than mocked.
     */
    private function storeFileFor(string $path, string $contents = '%PDF-1.4 fake'): void
    {
        Storage::disk('documents')->put($path, $contents);
    }

    public function test_an_order_lists_its_published_reports_and_vehicle_documents(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);

        $report = $this->makeReportDocument($order, 'gutachten', title: 'Erstgutachten');
        $vehicleDocument = VehicleDocument::factory()->create([
            'vehicle_id' => $order->vehicle_id,
            'document_type' => 'Leasingvertrag',
            'original_file_name' => 'vertrag.pdf',
            'content_type' => 'application/pdf',
            'file_size' => 4242,
        ]);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.documents.index', $order->id))
            ->assertOk()
            ->assertJsonCount(2, 'data.documents');

        $documents = collect($response->json('data.documents'))->keyBy('id');

        $this->assertSame('report', $documents[$report->id]['source']);
        $this->assertSame('gutachten', $documents[$report->id]['type']);
        $this->assertSame('Gutachten', $documents[$report->id]['type_label']);
        $this->assertSame('Erstgutachten.pdf', $documents[$report->id]['filename']);
        $this->assertSame('application/pdf', $documents[$report->id]['content_type']);
        $this->assertSame($order->auftragsnummer, $documents[$report->id]['order']['reference']);

        $this->assertSame('vehicle', $documents[$vehicleDocument->document_id]['source']);
        $this->assertSame('vertrag.pdf', $documents[$vehicleDocument->document_id]['filename']);
        $this->assertSame(4242, $documents[$vehicleDocument->document_id]['size_bytes']);
    }

    public function test_the_storage_path_never_appears_in_a_response(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        $report = $this->makeReportDocument($order, 'rechnung', title: 'Rechnung');
        $this->storeFileFor($report->path);

        foreach ([
            route('partner.v1.orders.documents.index', $order->id),
            route('partner.v1.documents.show', $report->id),
            route('partner.v1.documents.download', $report->id),
        ] as $url) {
            $body = $this->withHeaders($this->bearer($token))->getJson($url)->assertOk()->getContent();

            $this->assertStringNotContainsString($report->path, $body);
            $this->assertStringNotContainsString('vehicle-reports/', $body);
            $this->assertStringNotContainsString('"path"', $body);
        }
    }

    public function test_an_unpublished_report_document_is_not_listed_or_readable(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        $draft = $this->makeReportDocument($order, 'gutachten', published: false, title: 'Entwurf');
        $this->storeFileFor($draft->path);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.documents.index', $order->id))
            ->assertOk()
            ->assertJsonCount(0, 'data.documents');

        // Not merely hidden from the listing — unreachable by id, and the
        // bytes cannot be reached either, because no link can be minted.
        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.documents.show', $draft->id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'document_not_found');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.documents.download', $draft->id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'document_not_found');
    }

    public function test_documents_can_be_filtered_by_type_and_source(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Reinspection);

        $this->makeReportDocument($order, 'gutachten');
        $this->makeReportDocument($order, 'nachgutachten');
        VehicleDocument::factory()->create(['vehicle_id' => $order->vehicle_id]);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.documents.index', $order->id).'?type=nachgutachten')
            ->assertOk()
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonPath('data.documents.0.type', 'nachgutachten');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.documents.index', $order->id).'?source=report')
            ->assertOk()
            ->assertJsonCount(2, 'data.documents');
    }

    public function test_another_companys_document_is_not_found(): void
    {
        $other = $this->makePartnerCompany('Fremde GmbH');
        $foreignOrder = $this->makeB2bOrder($other->b2b_id, OrderStatus::Inspected);
        $foreignReport = $this->makeReportDocument($foreignOrder, 'gutachten');
        $foreignVehicleDocument = VehicleDocument::factory()->create([
            'vehicle_id' => $foreignOrder->vehicle_id,
        ]);

        [, $token] = $this->makeAuthenticatedPartner();

        foreach ([$foreignReport->id, $foreignVehicleDocument->document_id] as $id) {
            $this->withHeaders($this->bearer($token))
                ->getJson(route('partner.v1.documents.show', $id))
                ->assertNotFound()
                ->assertJsonPath('error.code', 'document_not_found');
        }

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.orders.documents.index', $foreignOrder->id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'order_not_found');
    }

    public function test_a_b2c_vehicles_document_is_not_reachable(): void
    {
        $b2cDocument = VehicleDocument::factory()->create();

        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.documents.show', $b2cDocument->document_id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'document_not_found');
    }

    public function test_a_download_returns_a_short_lived_signed_link_that_streams_the_file(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::InvoiceProcessed);
        $report = $this->makeReportDocument($order, 'rechnung', title: 'Schlussrechnung');
        $this->storeFileFor($report->path, 'invoice-bytes');

        $response = $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.documents.download', $report->id))
            ->assertOk()
            ->assertJsonPath('data.document.filename', 'Schlussrechnung.pdf')
            ->assertJsonPath('data.document.content_type', 'application/pdf')
            ->assertJsonPath('data.download.expires_in_seconds', PartnerDocumentDownloadLink::TTL_SECONDS);

        $url = $response->json('data.download.url');

        $this->assertStringContainsString('/documents/'.$report->id.'/content', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringNotContainsString($report->path, $url);

        // The link is a credential of its own — no bearer token is sent here.
        $streamed = $this->get($url)->assertOk();

        $this->assertSame('invoice-bytes', $streamed->streamedContent());
        $this->assertStringContainsString('Schlussrechnung.pdf', $streamed->headers->get('content-disposition'));
        $this->assertStringContainsString('no-store', (string) $streamed->headers->get('cache-control'));
    }

    public function test_an_expired_download_link_is_refused(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::InvoiceProcessed);
        $report = $this->makeReportDocument($order, 'rechnung');
        $this->storeFileFor($report->path);

        $url = $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.documents.download', $report->id))
            ->assertOk()
            ->json('data.download.url');

        $this->travel(PartnerDocumentDownloadLink::TTL_SECONDS + 60)->seconds();

        $this->getJson($url)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'download_link_expired');
    }

    public function test_a_tampered_download_link_is_refused(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::InvoiceProcessed);
        $report = $this->makeReportDocument($order, 'rechnung');
        $other = $this->makeReportDocument($order, 'gutachten');
        $this->storeFileFor($report->path);
        $this->storeFileFor($other->path);

        $url = $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.documents.download', $report->id))
            ->assertOk()
            ->json('data.download.url');

        // Repointing a valid signature at a different document — even one the
        // same partner may read — breaks the signature.
        $this->getJson(str_replace($report->id, $other->id, $url))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'download_link_invalid');

        $this->getJson(route('partner.v1.documents.content', $report->id))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'download_link_invalid');
    }

    public function test_a_download_link_dies_with_its_client(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::InvoiceProcessed);
        $report = $this->makeReportDocument($order, 'rechnung');
        $this->storeFileFor($report->path);

        $url = $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.documents.download', $report->id))
            ->assertOk()
            ->json('data.download.url');

        $client->forceFill(['is_active' => false])->save();

        // Still inside the TTL, still correctly signed — and refused, because
        // the link is re-authorised on every fetch.
        $this->getJson($url)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'document_not_found');
    }

    public function test_a_signed_link_cannot_reach_another_companys_document(): void
    {
        $other = $this->makePartnerCompany('Fremde GmbH');
        $foreignOrder = $this->makeB2bOrder($other->b2b_id, OrderStatus::InvoiceProcessed);
        $foreignReport = $this->makeReportDocument($foreignOrder, 'rechnung');
        $this->storeFileFor($foreignReport->path);

        [$client, $token] = $this->makeAuthenticatedPartner();
        $ownOrder = $this->makeB2bOrder($client->b2b_id, OrderStatus::InvoiceProcessed);
        $ownReport = $this->makeReportDocument($ownOrder, 'rechnung');
        $this->storeFileFor($ownReport->path);

        $url = $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.documents.download', $ownReport->id))
            ->assertOk()
            ->json('data.download.url');

        // Even re-signed correctly for this client, the foreign id is not in
        // its company: verify() re-checks ownership, it does not trust the
        // signature to have checked it.
        $signed = URL::temporarySignedRoute(
            'partner.v1.documents.content',
            now()->addMinutes(5),
            ['document' => $foreignReport->id, 'client' => $client->id],
        );

        $this->getJson($signed)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'document_not_found');

        // Control: the partner's own link still works.
        $this->get($url)->assertOk();
    }

    public function test_an_uploaded_filename_cannot_escape_its_directory(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);

        $document = VehicleDocument::factory()->create([
            'vehicle_id' => $order->vehicle_id,
            'original_file_name' => '../../etc/passwd',
        ]);
        $this->storeFileFor($document->path);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.documents.show', $document->document_id))
            ->assertOk()
            ->assertJsonPath('data.document.filename', 'passwd');
    }

    public function test_documents_require_the_documents_scope_and_the_company_permission(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner(
            abilities: [PartnerAbility::ReadOrders->value],
        );
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::Inspected);
        $report = $this->makeReportDocument($order, 'gutachten');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.documents.show', $report->id))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope')
            ->assertJsonPath('error.details.required_ability', 'documents.read');

        $full = $this->issueToken($client)->plainTextToken;
        $this->setCompanyPermissions($client, ['company.view']);

        $this->withHeaders($this->bearer($full))
            ->getJson(route('partner.v1.orders.documents.index', $order->id))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_company_permission');
    }

    public function test_a_document_row_without_its_file_is_a_404_not_a_500(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $order = $this->makeB2bOrder($client->b2b_id, OrderStatus::InvoiceProcessed);
        $report = $this->makeReportDocument($order, 'rechnung');

        $url = $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.documents.download', $report->id))
            ->assertOk()
            ->json('data.download.url');

        $this->getJson($url)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'document_not_found');
    }
}
