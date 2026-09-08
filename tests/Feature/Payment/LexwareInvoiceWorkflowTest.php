<?php

namespace Tests\Feature\Payment;

use App\Enums\DocumentType;
use App\Enums\UserType;
use App\Models\Address;
use App\Models\Contact;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Payment\Contracts\LexwareGateway;
use App\Modules\UserProfile\Payment\Enums\LexwareInvoiceStatus;
use App\Modules\UserProfile\Payment\Exceptions\LexwareGatewayException;
use App\Modules\UserProfile\Payment\Jobs\IssueRepairInvoice;
use App\Modules\UserProfile\Payment\Models\LexwareContact;
use App\Modules\UserProfile\Payment\Models\LexwareInvoice;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Payment\Services\LexwareInvoiceWorkflow;
use App\Modules\UserProfile\Profile\Models\LeasybackUserProfile;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\Support\FakeLexwareGateway;
use Tests\TestCase;

/**
 * The B2C Lexware invoice lifecycle: passed reinspection through to a
 * published `rechnung` document, and every retry path in between.
 */
class LexwareInvoiceWorkflowTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private FakeLexwareGateway $lexware;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake([IssueRepairInvoice::class]);
        Storage::fake('documents');
        config(['broadcasting.default' => 'null', 'services.lexware.mode' => 'test']);

        $this->lexware = new FakeLexwareGateway;
        $this->app->instance(LexwareGateway::class, $this->lexware);
    }

    // ------------------------------------------------------------ the trigger

    public function test_reaching_delivered_dispatches_the_invoice_job_for_b2c_only(): void
    {
        $order = $this->deliveredOrder();

        Queue::assertPushed(IssueRepairInvoice::class, fn (IssueRepairInvoice $job) => $job->orderId === $order->id);
    }

    public function test_a_passed_reinspection_produces_a_published_rechnung(): void
    {
        $order = $this->deliveredOrder();

        $record = $this->issue($order);

        $this->assertSame(LexwareInvoiceStatus::Documented, $record->status);
        $this->assertSame('RE-1', $record->voucher_number);
        $this->assertSame('open', $record->voucher_status);
        $this->assertSame('/v1/invoices/invoice-1', $record->resource_uri);
        $this->assertSame(1, $record->lexware_version);
        $this->assertNotNull($record->invoiced_at);
        $this->assertNotNull($record->documented_at);

        $document = VehicleReportDocument::findOrFail($record->document_id);

        $this->assertSame(DocumentType::Rechnung->value, $document->document_type);
        $this->assertTrue($document->published);
        $this->assertSame("vehicle-reports/{$order->auftragsnummer}/Rechnung-RE-1.pdf", $document->path);
        Storage::disk('documents')->assertExists($document->path);
    }

    public function test_the_steps_run_in_order_contact_then_invoice_then_pdf(): void
    {
        $this->issue($this->deliveredOrder());

        $this->assertSame([
            'createPersonContact',
            'createFinalizedInvoice',
            'retrieveInvoice',
            'downloadInvoiceFile',
        ], $this->lexware->methods());
    }

    public function test_a_failed_reinspection_creates_nothing(): void
    {
        $order = $this->inRepair();
        $this->advance($order, 'reinspection');
        $this->publishDocument($order, 'nachgutachten');
        $this->advance($order->fresh(), 'reworkshop');

        $this->assertNull($this->workflow()->issueRepairInvoice($order->fresh(), false));
        $this->assertSame(0, LexwareInvoice::count());
        $this->assertSame([], $this->lexware->methods());
    }

    public function test_an_order_that_never_reached_delivered_creates_nothing(): void
    {
        $order = $this->inRepair();

        $this->assertNull($this->workflow()->issueRepairInvoice($order, false));
        $this->assertSame(0, LexwareInvoice::count());
    }

    public function test_b2b_orders_create_nothing(): void
    {
        $order = $this->deliveredOrder();

        $this->assertNull($this->workflow()->issueRepairInvoice($order, true));
        $this->assertSame(0, LexwareInvoice::count());
        $this->assertSame([], $this->lexware->methods());
    }

    // ------------------------------------------------------------ the contact

    public function test_the_customer_is_sent_as_a_private_person_never_a_company(): void
    {
        $this->issue($this->deliveredOrder());

        $payload = $this->lexware->lastCall('createPersonContact')['payload'];

        $this->assertSame(0, $payload['version']);
        $this->assertSame([], (array) $payload['roles']['customer']);
        $this->assertSame('Herr', $payload['person']['salutation']);
        $this->assertSame('Max', $payload['person']['firstName']);
        $this->assertSame('Mustermann', $payload['person']['lastName']);
        $this->assertArrayNotHasKey('company', $payload);
    }

    public function test_the_billing_address_comes_from_the_customer_contact(): void
    {
        $this->issue($this->deliveredOrder());

        $address = $this->lexware->lastCall('createPersonContact')['payload']['addresses']['billing'][0];

        $this->assertSame('Flottenstraße 12', $address['street']);
        $this->assertSame('10115', $address['zip']);
        $this->assertSame('Berlin', $address['city']);
        $this->assertSame('DE', $address['countryCode']);
    }

    public function test_a_known_lexware_contact_is_reused_rather_than_recreated(): void
    {
        $order = $this->deliveredOrder();
        $contactId = LeasybackUserProfile::where('user_id', $this->ownerOf($order)->id)->sole()->contact_id;

        LexwareContact::create(['contact_id' => $contactId, 'lexware_contact_id' => 'contact-existing']);

        $this->issue($order);

        $this->assertSame(0, $this->lexware->callCount('createPersonContact'));
        $this->assertSame(
            'contact-existing',
            $this->lexware->lastCall('createFinalizedInvoice')['payload']['address']['contactId'],
        );
    }

    public function test_repeated_runs_never_create_a_second_contact(): void
    {
        $order = $this->deliveredOrder();

        $this->issue($order);
        $this->issue($order->fresh());
        $this->issue($order->fresh());

        $this->assertSame(1, $this->lexware->callCount('createPersonContact'));
        $this->assertSame(1, LexwareContact::count());
    }

    public function test_a_contact_failure_prevents_any_invoice(): void
    {
        $order = $this->deliveredOrder();
        $this->lexware->failCreateContact = LexwareGatewayException::apiError('contact rejected', 400);

        $this->expectException(LexwareGatewayException::class);

        try {
            $this->workflow()->issueRepairInvoice($order, false);
        } finally {
            $this->assertSame(0, $this->lexware->callCount('createFinalizedInvoice'));
            $this->assertNull(LexwareInvoice::sole()->lexware_invoice_id);
        }
    }

    // ------------------------------------------------------------ the invoice

    public function test_the_invoice_carries_the_order_and_vehicle_references(): void
    {
        $order = $this->deliveredOrder();

        $this->issue($order);

        $payload = $this->lexware->lastCall('createFinalizedInvoice')['payload'];

        $this->assertSame(sprintf('LeasyBack Auftrag %s', $order->auftragsnummer), $payload['introduction']);
        $this->assertStringContainsString('Volkswagen', $payload['remark']);
        $this->assertStringContainsString('VIN: ', $payload['remark']);
        $this->assertSame('net', $payload['taxConditions']['taxType']);
    }

    public function test_line_items_come_from_the_accepted_offer_at_its_stamped_vat_rate(): void
    {
        $this->issue($this->deliveredOrder());

        $lines = $this->lexware->lastCall('createFinalizedInvoice')['payload']['lineItems'];

        $this->assertCount(1, $lines);
        $this->assertSame('Stoßfänger vorne', $lines[0]['name']);
        $this->assertSame(600, $lines[0]['unitPrice']['netAmount']);
        $this->assertSame(19, $lines[0]['unitPrice']['taxRatePercentage']);
    }

    public function test_a_draft_response_is_rejected_and_flagged_for_reconciliation(): void
    {
        $order = $this->deliveredOrder();
        $this->lexware->voucherStatus = 'draft';

        try {
            $this->workflow()->issueRepairInvoice($order, false);
            $this->fail('the workflow accepted a draft invoice');
        } catch (LexwareGatewayException) {
            $record = LexwareInvoice::sole();

            $this->assertSame(LexwareInvoiceStatus::NeedsReconciliation, $record->status);
            $this->assertSame(0, $this->lexware->callCount('downloadInvoiceFile'));
            $this->assertSame(0, VehicleReportDocument::where('document_type', DocumentType::Rechnung->value)->count());
        }
    }

    // ---------------------------------------------------------------- the pdf

    public function test_the_pdf_is_stored_through_the_existing_document_system(): void
    {
        $order = $this->deliveredOrder();

        $record = $this->issue($order);
        $document = VehicleReportDocument::findOrFail($record->document_id);

        $this->assertSame('Rechnung RE-1', $document->document_title);
        $this->assertNull($document->created_by_user_id);
        $this->assertSame('%PDF-1.4 invoice-1', Storage::disk('documents')->get($document->path));
        $this->assertSame('invoice-1', $this->lexware->lastCall('downloadInvoiceFile')['invoice_id']);
    }

    public function test_provide_invoice_completes_once_the_document_exists(): void
    {
        $order = $this->deliveredOrder();

        $this->assertNotContains('provide_invoice', array_column($this->tasks($order)['history'], 'key'));

        $this->issue($order);

        $tasks = $this->tasks($order->fresh());

        $this->assertContains('provide_invoice', array_column($tasks['history'], 'key'));
        $this->assertSame('await_repair_payment', $tasks['next']['key']);
    }

    public function test_the_admin_payload_exposes_the_invoice_for_display(): void
    {
        $order = $this->deliveredOrder();

        $this->issue($order);

        $payload = json_decode((string) json_encode(
            $this->actingAs($this->makeAdmin())
                ->get(route('admin.orders.show', $order->id))
                ->viewData('page')['props']['order'],
        ), true);

        $this->assertSame('RE-1', $payload['lexware_invoice']['voucher_number']);
        $this->assertSame('documented', $payload['lexware_invoice']['status']);
        $this->assertNotNull($payload['lexware_invoice']['invoiced_at']);
        $this->assertArrayNotHasKey('lexware_invoice_id', $payload['lexware_invoice']);
    }

    // -------------------------------------------------------- the retry paths

    public function test_a_second_run_creates_no_second_invoice_and_no_second_document(): void
    {
        $order = $this->deliveredOrder();

        $first = $this->issue($order);
        $second = $this->issue($order->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->lexware->callCount('createFinalizedInvoice'));
        $this->assertSame(1, LexwareInvoice::count());
        $this->assertSame(1, VehicleReportDocument::where('document_type', DocumentType::Rechnung->value)->count());
    }

    public function test_a_pdf_failure_retries_only_the_pdf(): void
    {
        $order = $this->deliveredOrder();
        $this->lexware->failDownload = LexwareGatewayException::apiError('file not ready', 404);

        try {
            $this->workflow()->issueRepairInvoice($order, false);
        } catch (LexwareGatewayException) {
        }

        $record = LexwareInvoice::sole();
        $this->assertSame(LexwareInvoiceStatus::Invoiced, $record->status);
        $this->assertSame('invoice-1', $record->lexware_invoice_id);
        $this->assertNull($record->document_id);

        $resumed = $this->issue($order->fresh());

        $this->assertSame(1, $this->lexware->callCount('createFinalizedInvoice'));
        $this->assertSame(LexwareInvoiceStatus::Documented, $resumed->status);
        $this->assertSame('invoice-1', $resumed->lexware_invoice_id);
    }

    public function test_a_retrieval_failure_keeps_the_invoice_and_retries_retrieval(): void
    {
        $order = $this->deliveredOrder();
        $this->lexware->failRetrieveInvoice = LexwareGatewayException::apiError('gateway timeout', 504);

        try {
            $this->workflow()->issueRepairInvoice($order, false);
        } catch (LexwareGatewayException) {
        }

        $this->assertSame('invoice-1', LexwareInvoice::sole()->lexware_invoice_id);

        $resumed = $this->issue($order->fresh());

        $this->assertSame(1, $this->lexware->callCount('createFinalizedInvoice'));
        $this->assertSame(2, $this->lexware->callCount('retrieveInvoice'));
        $this->assertSame(LexwareInvoiceStatus::Documented, $resumed->status);
    }

    public function test_a_definitive_rejection_allows_a_later_retry(): void
    {
        $order = $this->deliveredOrder();
        $this->lexware->failCreateInvoice = LexwareGatewayException::apiError('validation failed', 400);

        try {
            $this->workflow()->issueRepairInvoice($order, false);
        } catch (LexwareGatewayException) {
        }

        $record = LexwareInvoice::sole();
        $this->assertNull($record->lexware_invoice_id);
        $this->assertNull($record->submitted_at);

        $this->assertSame(LexwareInvoiceStatus::Documented, $this->issue($order->fresh())->status);
        $this->assertSame(2, $this->lexware->callCount('createFinalizedInvoice'));
    }

    public function test_an_ambiguous_timeout_never_posts_a_second_invoice(): void
    {
        $order = $this->deliveredOrder();
        $this->lexware->failCreateInvoice = LexwareGatewayException::transportError(
            'Lexware could not be reached: timeout',
            new \RuntimeException('timeout'),
        );

        try {
            $this->workflow()->issueRepairInvoice($order, false);
        } catch (LexwareGatewayException) {
        }

        $record = LexwareInvoice::sole();
        $this->assertSame(LexwareInvoiceStatus::NeedsReconciliation, $record->status);
        $this->assertNotNull($record->submitted_at);

        $this->assertSame(LexwareInvoiceStatus::NeedsReconciliation, $this->issue($order->fresh())->status);
        $this->assertSame(1, $this->lexware->callCount('createFinalizedInvoice'));
        $this->assertSame(0, VehicleReportDocument::where('document_type', DocumentType::Rechnung->value)->count());
    }

    public function test_a_claimed_submission_is_never_sent_twice(): void
    {
        $order = $this->deliveredOrder();

        LexwareInvoice::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'purpose' => LexwareInvoice::PURPOSE_REPAIR,
            'status' => LexwareInvoiceStatus::Pending,
            'submitted_at' => now(),
        ]);

        $this->expectException(LexwareGatewayException::class);

        try {
            $this->workflow()->issueRepairInvoice($order, false);
        } finally {
            $this->assertSame(0, $this->lexware->callCount('createFinalizedInvoice'));
            $this->assertSame(1, LexwareInvoice::count());
        }
    }

    // ----------------------------------------------------------------- helpers

    private function workflow(): LexwareInvoiceWorkflow
    {
        return app(LexwareInvoiceWorkflow::class);
    }

    private function issue(LeasybackOrder $order): LexwareInvoice
    {
        $record = $this->workflow()->issueRepairInvoice($order, false);

        $this->assertNotNull($record);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function tasks(LeasybackOrder $order): array
    {
        return json_decode((string) json_encode(
            $this->actingAs($this->makeAdmin())
                ->get(route('admin.orders.show', $order->id))
                ->viewData('page')['props']['order'],
        ), true)['tasks'];
    }

    // ------------------------------------------------------------- the states

    private function deliveredOrder(): LeasybackOrder
    {
        $order = $this->inRepair();
        $this->advance($order, 'reinspection');
        $this->publishDocument($order, 'nachgutachten');
        $this->advance($order->fresh(), 'delivered');

        return $order->fresh();
    }

    private function inRepair(): LeasybackOrder
    {
        $order = $this->withPositions($this->b2cOrder());
        $this->accept($order, $this->publishedOffer($order));
        $this->commission($order);
        $this->saveAppointment($order->fresh());

        return $order->fresh();
    }

    // ----------------------------------------------------------- the fixtures

    private function b2cOrder(): LeasybackOrder
    {
        $address = Address::factory()->create([
            'street' => 'Flottenstraße',
            'number' => '12',
            'zip_code' => '10115',
            'city' => 'Berlin',
            'country' => 'Deutschland',
        ]);

        $contact = Contact::factory()->create([
            'address_id' => $address->address_id,
            'salutation' => 'Herr',
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
        ]);

        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);

        LeasybackUserProfile::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'contact_id' => $contact->contact_id,
        ]);

        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => $user->id,
            'make' => 'Volkswagen',
            'model' => 'Passat',
        ]);

        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'inspected',
        ]);

        OrderPaymentMethod::factory()->saved()->create(['order_id' => $order->id]);

        return $order;
    }

    private function withPositions(LeasybackOrder $order): LeasybackOrder
    {
        AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'sort_order' => 0,
            'component' => 'Stoßfänger vorne',
            'damage_description' => 'Kratzer',
            'original_amount_net' => '1000.00',
            'repair_method' => 'Instandsetzung',
            'source' => AppraisalPosition::SOURCE_MANUAL,
        ]);

        return $order;
    }

    private function submitted(LeasybackOrder $order): WorkshopQuotation
    {
        $quotation = app(WorkshopQuotationService::class)
            ->invite($order, $this->makeAdmin(), ['workshop_label' => 'Karosserie Meier GmbH'])['quotation'];

        app(WorkshopQuotationService::class)->submit($quotation, [
            'company_name' => 'Karosserie Meier GmbH',
            'contact_person' => 'Kontakt Person',
            'contact_email' => 'kontakt@werkstatt.test',
            'contact_phone' => '+49 30 123456',
            'earliest_repair_start' => '2026-10-15',
            'processing_days' => 3,
            'items' => AppraisalPosition::where('order_id', $order->id)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (AppraisalPosition $position) => [
                    'appraisal_position_id' => $position->id,
                    'amount_net' => '600.00',
                ])
                ->all(),
        ]);

        return $quotation->fresh();
    }

    private function publishedOffer(LeasybackOrder $order): LeasybackOffer
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.b2b-offer.store', $order->id), [
                'workshop_quotation_id' => $this->submitted($order)->id,
            ])
            ->assertSessionHasNoErrors();

        $offer = LeasybackOffer::where('order_id', $order->id)->orderByDesc('offer_sequence')->firstOrFail();

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.offers.publish', $offer->offer_id))
            ->assertSessionHasNoErrors();

        return $offer->fresh();
    }

    private function accept(LeasybackOrder $order, LeasybackOffer $offer): void
    {
        $this->actingAs($this->ownerOf($order))
            ->from('/dashboard')
            ->post(route('offers.select', $offer->offer_id))
            ->assertSessionHasNoErrors();
    }

    private function commission(LeasybackOrder $order): void
    {
        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.commission-workshop', $order->id))
            ->assertSessionHasNoErrors();
    }

    private function saveAppointment(LeasybackOrder $order): void
    {
        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.repair-appointment', $order->id), ['confirmed_repair_start_date' => '2026-09-01'])
            ->assertSessionHasNoErrors();
    }

    private function advance(LeasybackOrder $order, string $status): void
    {
        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.status', $order->id), ['status' => $status])
            ->assertSessionHasNoErrors();
    }

    private function publishDocument(LeasybackOrder $order, string $type): void
    {
        VehicleReportDocument::factory()->create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => $type,
            'document_title' => ucfirst($type),
            'published' => true,
        ]);
    }

    private function ownerOf(LeasybackOrder $order): User
    {
        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->sole();

        return User::findOrFail($vehicle->b2c_user_id);
    }
}
