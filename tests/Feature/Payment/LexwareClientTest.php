<?php

namespace Tests\Feature\Payment;

use App\Modules\UserProfile\Payment\Contracts\LexwareGateway;
use App\Modules\UserProfile\Payment\Data\LexwareContactRequest;
use App\Modules\UserProfile\Payment\Data\LexwareInvoiceLine;
use App\Modules\UserProfile\Payment\Data\LexwareInvoiceRequest;
use App\Modules\UserProfile\Payment\Exceptions\LexwareGatewayException;
use App\Modules\UserProfile\Payment\Services\LexwareClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Lexware Office request/response contract, pinned against the recorded
 * V1 transport. Nothing here reaches the network.
 */
class LexwareClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.lexware.mode' => 'test',
            'services.lexware.base_url' => 'https://api.lexware.io',
            'services.lexware.api_key' => 'test-key',
        ]);
    }

    // ------------------------------------------------------- the configuration

    public function test_a_disabled_integration_refuses_to_construct(): void
    {
        config(['services.lexware.mode' => 'disabled']);

        $this->expectException(LexwareGatewayException::class);
        $this->expectExceptionMessage('LEXWARE_INTEGRATION_MODE is not enabled');

        new LexwareClient;
    }

    public function test_a_missing_api_key_refuses_to_construct(): void
    {
        config(['services.lexware.api_key' => '']);

        $this->expectException(LexwareGatewayException::class);
        $this->expectExceptionMessage('LEXWARE_API_KEY is not configured');

        new LexwareClient;
    }

    public function test_a_plaintext_base_url_refuses_to_construct(): void
    {
        config(['services.lexware.base_url' => 'http://api.lexware.io']);

        $this->expectException(LexwareGatewayException::class);
        $this->expectExceptionMessage('must be an https URL');

        new LexwareClient;
    }

    public function test_both_enabled_modes_construct(): void
    {
        foreach (['test', 'live'] as $mode) {
            config(['services.lexware.mode' => $mode]);

            $this->assertInstanceOf(LexwareClient::class, new LexwareClient);
        }
    }

    public function test_the_container_resolves_the_gateway_to_the_http_client(): void
    {
        $this->assertInstanceOf(LexwareClient::class, $this->app->make(LexwareGateway::class));
    }

    // ------------------------------------------------------------- the invoice

    public function test_creating_an_invoice_sends_the_proven_draft_payload(): void
    {
        Http::fake(['api.lexware.io/v1/invoices' => Http::response([
            'id' => 'draft-1', 'version' => 1, 'resourceUri' => '/v1/invoices/draft-1',
        ])]);

        $result = (new LexwareClient)->createInvoice($this->invoice());

        $this->assertSame('draft-1', $result->id);
        $this->assertSame(1, $result->version);
        $this->assertSame('/v1/invoices/draft-1', $result->resourceUri);

        Http::assertSent(function (Request $request) {
            $payload = $request->data();

            $this->assertSame('POST', $request->method());
            $this->assertSame('https://api.lexware.io/v1/invoices', $request->url());
            $this->assertSame('Bearer test-key', $request->header('Authorization')[0]);
            $this->assertSame('application/json', $request->header('Accept')[0]);

            $this->assertSame('contact-1', $payload['address']['contactId']);
            $this->assertSame('net', $payload['taxConditions']['taxType']);
            $this->assertSame('EUR', $payload['totalPrice']['currency']);
            $this->assertSame('service', $payload['shippingConditions']['shippingType']);
            $this->assertSame('Rechnung', $payload['title']);
            $this->assertSame('LeasyBack Auftrag LB-2026-1', $payload['introduction']);
            $this->assertStringContainsString('WBA12345678901234', $payload['remark']);

            $line = $payload['lineItems'][0];
            $this->assertSame('custom', $line['type']);
            $this->assertSame('Seitenwand links', $line['name']);
            $this->assertSame('Instandsetzen', $line['description']);
            $this->assertSame(1.0, $line['quantity']);
            $this->assertSame('Stück', $line['unitName']);
            $this->assertSame(200, $line['unitPrice']['netAmount']);
            $this->assertSame('EUR', $line['unitPrice']['currency']);
            $this->assertSame(19, $line['unitPrice']['taxRatePercentage']);
            $this->assertSame(0, $line['discountPercentage']);

            $this->assertArrayNotHasKey('finalize', $payload);
            $this->assertArrayNotHasKey('paymentConditions', $payload);
            $this->assertArrayNotHasKey('voucherNumber', $payload);

            return true;
        });
    }

    public function test_business_dates_are_sent_as_berlin_noon_with_the_right_offset(): void
    {
        Http::fake(['api.lexware.io/*' => Http::response(['id' => 'draft-1', 'version' => 1])]);

        $client = new LexwareClient;
        $client->createInvoice($this->invoice(voucherDate: '2026-08-01', performanceDate: '2026-08-01'));
        $client->createInvoice($this->invoice(voucherDate: '2026-01-10', performanceDate: '2026-01-10'));

        $sent = [];

        Http::assertSent(function (Request $request) use (&$sent) {
            $sent[] = $request->data();

            return true;
        });

        $this->assertSame('2026-08-01T12:00:00.000+02:00', $sent[0]['voucherDate']);
        $this->assertSame('2026-08-01T12:00:00.000+02:00', $sent[0]['shippingConditions']['shippingDate']);
        $this->assertSame('2026-01-10T12:00:00.000+01:00', $sent[1]['voucherDate']);
        $this->assertSame('2026-01-10T12:00:00.000+01:00', $sent[1]['shippingConditions']['shippingDate']);
    }

    public function test_amounts_are_sent_net_in_euros_and_quantities_as_decimals(): void
    {
        Http::fake(['api.lexware.io/*' => Http::response(['id' => 'draft-1'])]);

        (new LexwareClient)->createInvoice($this->invoice(lines: [
            new LexwareInvoiceLine(name: 'Ersatzteil', netAmountCents: 12_345, taxRatePercentage: 19, quantity: 2.5),
        ]));

        Http::assertSent(function (Request $request) {
            $line = $request->data()['lineItems'][0];

            $this->assertSame(123.45, $line['unitPrice']['netAmount']);
            $this->assertSame(2.5, $line['quantity']);

            return true;
        });
    }

    public function test_retrieving_an_invoice_reads_version_and_voucher_status(): void
    {
        Http::fake(['api.lexware.io/v1/invoices/draft-1' => Http::response([
            'id' => 'draft-1', 'version' => 1, 'voucherStatus' => 'draft', 'title' => 'Rechnung',
        ])]);

        $result = (new LexwareClient)->retrieveInvoice('draft-1');

        $this->assertSame('draft-1', $result->id);
        $this->assertSame(1, $result->version);
        $this->assertSame('draft', $result->voucherStatus);
        $this->assertTrue($result->isDraft());
        $this->assertSame('Rechnung', $result->body['title']);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://api.lexware.io/v1/invoices/draft-1');
    }

    // ------------------------------------------------------------ the contacts

    public function test_creating_a_company_contact_sends_the_proven_payload(): void
    {
        Http::fake(['api.lexware.io/v1/contacts' => Http::response(['id' => 'contact-1', 'version' => 0])]);

        $id = (new LexwareClient)->createCompanyContact(new LexwareContactRequest(
            companyName: 'Musterflotte GmbH',
            street: 'Flottenstraße 12',
            zip: '10115',
            city: 'Berlin',
        ));

        $this->assertSame('contact-1', $id);

        Http::assertSent(function (Request $request) {
            $payload = $request->data();

            $this->assertSame('POST', $request->method());
            $this->assertSame(0, $payload['version']);
            $this->assertSame([], (array) $payload['roles']['customer']);
            $this->assertSame('Musterflotte GmbH', $payload['company']['name']);
            $this->assertFalse($payload['company']['allowTaxFreeInvoices']);
            $this->assertSame([], $payload['company']['contactPersons']);
            $this->assertSame([
                'street' => 'Flottenstraße 12',
                'zip' => '10115',
                'city' => 'Berlin',
                'countryCode' => 'DE',
            ], $payload['addresses']['billing'][0]);

            $this->assertArrayNotHasKey('person', $payload);
            $this->assertArrayNotHasKey('emailAddresses', $payload);

            return true;
        });
    }

    public function test_a_contact_response_without_an_id_is_an_api_error(): void
    {
        Http::fake(['api.lexware.io/v1/contacts' => Http::response(['version' => 0])]);

        $this->expectException(LexwareGatewayException::class);
        $this->expectExceptionMessage('without an id');

        (new LexwareClient)->createCompanyContact(new LexwareContactRequest('X', 'Y', '1', 'Z'));
    }

    public function test_listing_customer_contacts_requests_one_page_of_customers(): void
    {
        Http::fake(['api.lexware.io/v1/contacts*' => Http::response([
            'content' => [['id' => 'contact-1'], ['id' => 'contact-2']],
        ])]);

        $contacts = (new LexwareClient)->listCustomerContacts();

        $this->assertCount(2, $contacts);
        $this->assertSame('contact-1', $contacts[0]['id']);

        Http::assertSent(function (Request $request) {
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('page=0', $request->url());
            $this->assertStringContainsString('size=250', $request->url());
            $this->assertStringContainsString('customer=true', $request->url());

            return true;
        });
    }

    // -------------------------------------------------------------- the errors

    public function test_a_rejected_request_keeps_the_status_and_the_error_body(): void
    {
        Http::fake(['api.lexware.io/*' => Http::response(
            ['IssueList' => [['type' => 'validation_failure', 'source' => 'lineItems']]],
            400,
        )]);

        try {
            (new LexwareClient)->createInvoice($this->invoice());
            $this->fail('the client accepted a rejected request');
        } catch (LexwareGatewayException $exception) {
            $this->assertSame(400, $exception->httpStatus);
            $this->assertSame('validation_failure', $exception->errorBody['IssueList'][0]['type']);
            $this->assertStringContainsString('HTTP 400', $exception->getMessage());
            $this->assertFalse($exception->isConfigurationError());
        }
    }

    public function test_a_server_error_is_reported_with_its_status(): void
    {
        Http::fake(['api.lexware.io/*' => Http::response([], 503)]);

        try {
            (new LexwareClient)->retrieveInvoice('draft-1');
            $this->fail('the client accepted a failed request');
        } catch (LexwareGatewayException $exception) {
            $this->assertSame(503, $exception->httpStatus);
        }
    }

    public function test_an_unreachable_api_is_a_transport_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

        try {
            (new LexwareClient)->createInvoice($this->invoice());
            $this->fail('the client swallowed a connection failure');
        } catch (LexwareGatewayException $exception) {
            $this->assertNull($exception->httpStatus);
            $this->assertStringContainsString('could not be reached', $exception->getMessage());
        }
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * @param  list<LexwareInvoiceLine>|null  $lines
     */
    private function invoice(
        string $voucherDate = '2026-08-01',
        string $performanceDate = '2026-08-01',
        ?array $lines = null,
    ): LexwareInvoiceRequest {
        return new LexwareInvoiceRequest(
            contactId: 'contact-1',
            lineItems: $lines ?? [new LexwareInvoiceLine(
                name: 'Seitenwand links',
                netAmountCents: 20_000,
                taxRatePercentage: 19,
                description: 'Instandsetzen',
            )],
            voucherDate: $voucherDate,
            performanceDate: $performanceDate,
            introduction: 'LeasyBack Auftrag LB-2026-1',
            remark: 'K-LB 1 · BMW · 320 · VIN: WBA12345678901234',
        );
    }
}
