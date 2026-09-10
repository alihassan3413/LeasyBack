<?php

namespace Tests\Feature\Payment;

use App\Enums\DocumentType;
use App\Enums\UserType;
use App\Mail\Orders\RepairInvoiceAvailableMail;
use App\Models\Address;
use App\Models\Contact;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Payment\Contracts\LexwareGateway;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Enums\LexwareInvoiceStatus;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Exceptions\LexwareGatewayException;
use App\Modules\UserProfile\Payment\Exceptions\StripeGatewayException;
use App\Modules\UserProfile\Payment\Jobs\ChargeRepairAmount;
use App\Modules\UserProfile\Payment\Jobs\IssueRepairInvoice;
use App\Modules\UserProfile\Payment\Models\LexwareInvoice;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Payment\Services\RepairBillingWorkflow;
use App\Modules\UserProfile\Profile\Models\LeasybackUserProfile;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\Support\FakeLexwareGateway;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * The B2C repair billing chain: Lexware invoice, Stripe Payment Link, and one
 * customer email carrying both.
 */
class RepairBillingWorkflowTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private FakeLexwareGateway $lexware;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake([IssueRepairInvoice::class]);
        Storage::fake('documents');
        config(['broadcasting.default' => 'null', 'services.lexware.mode' => 'test']);

        $this->lexware = new FakeLexwareGateway;
        $this->app->instance(LexwareGateway::class, $this->lexware);
        $this->stripe = $this->app->make(StripeGateway::class);
    }

    // ------------------------------------------------- the collection change

    public function test_the_repair_obligation_exists_and_is_never_auto_charged(): void
    {
        $order = $this->deliveredOrder();

        $payment = $this->repairPayment($order);

        $this->assertNotNull($payment);
        $this->assertSame(71400, $payment->amount_cents);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentIntent'));
        $this->assertSame(0, OrderPaymentIntent::count());
        Queue::assertNotPushed(ChargeRepairAmount::class);
    }

    public function test_the_off_session_charging_infrastructure_is_preserved(): void
    {
        $this->assertTrue(class_exists(ChargeRepairAmount::class));
        $this->assertTrue(method_exists(StripeGateway::class, 'createPaymentIntent'));
        $this->assertTrue(method_exists(StripeGateway::class, 'confirmPaymentIntent'));
        $this->assertTrue(method_exists(OrderPaymentMethod::class, 'isChargeableOffSession'));
    }

    public function test_b2b_orders_get_no_invoice_no_link_and_no_email(): void
    {
        $company = $this->makeCompany('Flotten GmbH');
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company), 'delivered');

        $this->assertNull(app(RepairBillingWorkflow::class)->issueFor($order, true));
        $this->assertSame(0, LexwareInvoice::count());
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentLink'));
        Mail::assertNotQueued(RepairInvoiceAvailableMail::class);
    }

    // ----------------------------------------------------- the payment link

    public function test_the_link_carries_the_authoritative_amount_and_currency(): void
    {
        $this->issue($this->deliveredOrder());

        $call = $this->stripe->lastCallTo('createPaymentLink')['arguments'];

        $this->assertSame(71400, $call['amountCents']);
        $this->assertSame('eur', $call['currency']);
    }

    public function test_the_link_metadata_correlates_payment_order_vehicle_and_invoice(): void
    {
        $order = $this->deliveredOrder();

        $this->issue($order);

        $metadata = $this->stripe->lastCallTo('createPaymentLink')['arguments']['metadata'];
        $payment = $this->repairPayment($order);

        $this->assertSame(PaymentPurpose::Repair->value, $metadata['purpose']);
        $this->assertSame($payment->id, $metadata['payment_id']);
        $this->assertSame($order->id, $metadata['order_id']);
        $this->assertSame($order->auftragsnummer, $metadata['auftragsnummer']);
        $this->assertSame($order->vehicle_id, $metadata['vehicle_id']);
        $this->assertSame('invoice-1', $metadata['lexware_invoice_id']);
        $this->assertSame('RE-1', $metadata['voucher_number']);
        $this->assertArrayNotHasKey('email', $metadata);
    }

    public function test_the_link_id_and_url_are_persisted_on_the_payment(): void
    {
        $order = $this->deliveredOrder();

        $this->issue($order);

        $payment = $this->repairPayment($order);

        $this->assertSame('plink_1', $payment->stripe_payment_link_id);
        $this->assertSame('https://pay.stripe.test/plink_1', $payment->stripe_payment_link_url);
        $this->assertNotNull($payment->payment_link_created_at);
    }

    public function test_repeated_runs_produce_one_logical_payment_link(): void
    {
        $order = $this->deliveredOrder();

        $this->issue($order);
        $this->issue($order->fresh());
        $this->issue($order->fresh());

        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentLink'));
        $this->assertSame('plink_1', $this->repairPayment($order)->stripe_payment_link_id);
    }

    public function test_the_idempotency_key_is_derived_from_the_payment_obligation(): void
    {
        $order = $this->deliveredOrder();
        $payment = $this->repairPayment($order);

        $this->issue($order);

        $this->assertSame(
            'repair-payment-link:'.$payment->id,
            $this->stripe->lastCallTo('createPaymentLink')['arguments']['idempotencyKey'],
        );
    }

    public function test_a_zero_amount_repair_gets_no_payment_link_and_no_billing_email(): void
    {
        $order = $this->deliveredOrder(amountNet: '0.00');

        $this->assertSame(PaymentStatus::NotRequired, $this->repairPayment($order)->status);

        $this->issue($order);

        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentLink'));
        $this->assertNull($this->repairPayment($order)->stripe_payment_link_id);
        $this->assertNull(LexwareInvoice::sole()->billing_email_sent_at);
        Mail::assertNotQueued(RepairInvoiceAvailableMail::class);
    }

    public function test_a_link_failure_leaves_the_invoice_untouched(): void
    {
        $order = $this->deliveredOrder();
        $this->stripe->nextFailure = StripeGatewayException::apiError('link rejected');

        try {
            app(RepairBillingWorkflow::class)->issueFor($order, false);
            $this->fail('the workflow swallowed a Stripe failure');
        } catch (StripeGatewayException) {
        }

        $this->assertSame(1, LexwareInvoice::count());
        $this->assertSame(1, $this->lexware->callCount('createFinalizedInvoice'));
        $this->assertNull($this->repairPayment($order)->stripe_payment_link_id);
        Mail::assertNotQueued(RepairInvoiceAvailableMail::class);

        $this->issue($order->fresh());

        $this->assertSame(1, $this->lexware->callCount('createFinalizedInvoice'));
        $this->assertSame('plink_1', $this->repairPayment($order)->stripe_payment_link_id);
    }

    public function test_a_documented_invoice_with_a_missing_pdf_restores_then_bills_normally(): void
    {
        $order = $this->deliveredOrder();

        $document = VehicleReportDocument::create([
            'auftragsnummer' => $order->auftragsnummer,
            'vehicle_id' => $order->vehicle_id,
            'document_type' => DocumentType::Rechnung->value,
            'document_title' => 'Rechnung RE0002',
            'path' => "vehicle-reports/{$order->auftragsnummer}/Rechnung-RE0002.pdf",
            'published' => true,
        ]);

        LexwareInvoice::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'purpose' => LexwareInvoice::PURPOSE_REPAIR,
            'status' => LexwareInvoiceStatus::Documented,
            'lexware_invoice_id' => 'invoice-1',
            'voucher_number' => 'RE0002',
            'voucher_status' => 'open',
            'document_id' => $document->id,
            'submitted_at' => now(),
            'invoiced_at' => now(),
            'documented_at' => now(),
        ]);

        $this->assertFalse(Storage::disk('documents')->exists($document->path));

        $restored = $this->issue($order->fresh());

        $this->assertSame('RE0002', $restored->voucher_number);
        $this->assertSame($document->id, $restored->document_id);
        $this->assertSame('invoice-1', $restored->lexware_invoice_id);
        Storage::disk('documents')->assertExists($document->path);
        $this->assertSame(0, $this->lexware->callCount('createFinalizedInvoice'));
        $this->assertSame(1, LexwareInvoice::count());
        $this->assertSame(1, VehicleReportDocument::where('document_type', DocumentType::Rechnung->value)->count());

        $this->assertSame('plink_1', $this->repairPayment($order)->stripe_payment_link_id);
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentLink'));
        Mail::assertQueued(RepairInvoiceAvailableMail::class, 1);

        $this->issue($order->fresh());

        $this->assertSame(1, $this->lexware->callCount('downloadInvoiceFile'));
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentLink'));
        Mail::assertQueued(RepairInvoiceAvailableMail::class, 1);
    }

    public function test_an_incomplete_lexware_invoice_produces_no_link_and_no_email(): void
    {
        $this->lexware->voucherStatus = 'draft';
        $order = $this->deliveredOrder(issue: false);

        try {
            app(RepairBillingWorkflow::class)->issueFor($order, false);
        } catch (LexwareGatewayException) {
        }

        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentLink'));
        Mail::assertNotQueued(RepairInvoiceAvailableMail::class);
    }

    // ------------------------------------------------------ the billing email

    public function test_one_email_is_sent_carrying_the_invoice_and_the_payment_link(): void
    {
        $order = $this->deliveredOrder();

        $this->issue($order);

        Mail::assertQueued(RepairInvoiceAvailableMail::class, 1);
        Mail::assertQueued(RepairInvoiceAvailableMail::class, function (RepairInvoiceAvailableMail $mail) use ($order) {
            $this->assertSame('https://pay.stripe.test/plink_1', $mail->data->actionUrl);
            $this->assertSame('RE-1', $mail->data->invoiceNumber);
            $this->assertNotNull($mail->data->documentUrl);
            $this->assertSame('Rechnung jetzt bezahlen', $mail->ctaLabel());
            $this->assertStringContainsString('Zahlungseingang', implode(' ', $mail->paragraphs()));

            return $mail->data->orderNumber === $order->auftragsnummer;
        });
    }

    public function test_the_email_is_sent_once_however_often_the_workflow_runs(): void
    {
        $order = $this->deliveredOrder();

        $this->issue($order);
        $this->issue($order->fresh());
        $this->issue($order->fresh());

        Mail::assertQueued(RepairInvoiceAvailableMail::class, 1);
        $this->assertNotNull(LexwareInvoice::sole()->billing_email_sent_at);
    }

    public function test_an_email_retry_creates_no_second_payment_link(): void
    {
        $order = $this->deliveredOrder();

        $this->issue($order);

        LexwareInvoice::sole()->update(['billing_email_sent_at' => null]);

        $this->issue($order->fresh());

        Mail::assertQueued(RepairInvoiceAvailableMail::class, 2);
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentLink'));
        $this->assertSame(1, $this->lexware->callCount('createFinalizedInvoice'));
    }

    public function test_the_generated_rechnung_sends_no_separate_document_notification(): void
    {
        $order = $this->deliveredOrder();

        $this->issue($order);

        $document = VehicleReportDocument::where('document_type', DocumentType::Rechnung->value)->sole();

        $this->assertTrue($document->published);
        $this->assertSame(0, DB::table('notifications')->where('data', 'like', '%document.published%')->count());
        Mail::assertQueued(RepairInvoiceAvailableMail::class, 1);
    }

    // -------------------------------------------------------- the pickup gate

    public function test_an_unpaid_order_waits_on_payment_and_never_offers_pickup(): void
    {
        $order = $this->deliveredOrder();

        $this->issue($order);

        $tasks = $this->tasks($order->fresh());

        $this->assertSame('await_repair_payment', $tasks['next']['key']);
        $this->assertNotSame('confirm_pickup', $tasks['next']['key']);
        $this->assertNotContains('confirm_pickup', array_column($tasks['history'], 'key'));
        $this->assertContains('provide_invoice', array_column($tasks['history'], 'key'));
        $this->assertSame(PaymentStatus::Pending, $this->repairPayment($order)->status);
    }

    // ------------------------------------------------------- the guard rails

    public function test_a_failed_reinspection_bills_nothing(): void
    {
        $order = $this->inRepair();
        $this->advance($order, 'reinspection');
        $this->publishDocument($order, 'nachgutachten');
        $this->advance($order->fresh(), 'reworkshop');

        app(RepairBillingWorkflow::class)->issueFor($order->fresh(), false);

        $this->assertSame(0, LexwareInvoice::count());
        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentLink'));
        Mail::assertNotQueued(RepairInvoiceAvailableMail::class);
    }

    public function test_a_manual_offer_without_a_stamped_vat_rate_fails_loudly(): void
    {
        $order = $this->deliveredOrder(issue: false);
        DB::table('b2b_offer_presentations')->update(['vat_rate' => null]);

        $this->expectException(LexwareGatewayException::class);
        $this->expectExceptionMessage('no stamped VAT rate');

        try {
            app(RepairBillingWorkflow::class)->issueFor($order, false);
        } finally {
            $this->assertSame(0, $this->stripe->countCallsTo('createPaymentLink'));
            Mail::assertNotQueued(RepairInvoiceAvailableMail::class);
        }
    }

    // ----------------------------------------------------------------- helpers

    private function issue(LeasybackOrder $order): LexwareInvoice
    {
        $record = app(RepairBillingWorkflow::class)->issueFor($order, false);

        $this->assertNotNull($record);

        return $record;
    }

    private function repairPayment(LeasybackOrder $order): ?OrderPayment
    {
        return OrderPayment::where('order_id', $order->id)
            ->where('purpose', PaymentPurpose::Repair->value)
            ->first();
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

    private function deliveredOrder(string $amountNet = '600.00', bool $issue = false): LeasybackOrder
    {
        $order = $this->inRepair($amountNet);
        $this->advance($order, 'reinspection');
        $this->publishDocument($order, 'nachgutachten');
        $this->advance($order->fresh(), 'delivered');

        $order = $order->fresh();

        if ($issue) {
            $this->issue($order);
        }

        return $order;
    }

    private function inRepair(string $amountNet = '600.00'): LeasybackOrder
    {
        $order = $this->withPositions($this->b2cOrder());
        $this->accept($order, $this->publishedOffer($order, $amountNet));
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

    private function submitted(LeasybackOrder $order, string $amountNet): WorkshopQuotation
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
                    'amount_net' => $amountNet,
                ])
                ->all(),
        ]);

        return $quotation->fresh();
    }

    private function publishedOffer(LeasybackOrder $order, string $amountNet): LeasybackOffer
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.b2b-offer.store', $order->id), [
                'workshop_quotation_id' => $this->submitted($order, $amountNet)->id,
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
        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->sole();

        $this->actingAs(User::findOrFail($vehicle->b2c_user_id))
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
}
