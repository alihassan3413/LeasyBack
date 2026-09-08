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
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Payment\Contracts\LexwareGateway;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Jobs\IssueRepairInvoice;
use App\Modules\UserProfile\Payment\Models\LexwareInvoice;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Payment\Services\PaymentService;
use App\Modules\UserProfile\Payment\Services\RepairBillingWorkflow;
use App\Modules\UserProfile\Profile\Models\LeasybackUserProfile;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\Support\FakeLexwareGateway;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

class BillingChainExplorationTest extends TestCase
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

        $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00', 'Europe/Berlin'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** A. Lexware finalises but returns no voucher number. */
    public function test_a_missing_voucher_number_does_not_produce_a_degenerate_document(): void
    {
        $order = $this->deliveredOrder();
        $this->lexware->voucherNumber = null;

        app(RepairBillingWorkflow::class)->issueFor($order, false);

        $document = VehicleReportDocument::where('document_type', DocumentType::Rechnung->value)->first();

        $this->assertNotNull($document, 'no rechnung document was stored at all');
        $this->assertStringNotContainsString('Rechnung-.pdf', (string) $document->path);
        $this->assertNotSame('Rechnung ', $document->document_title);
    }

    /** B. Several positions with awkward amounts. */
    public function test_several_positions_with_awkward_amounts_still_bill(): void
    {
        $order = $this->deliveredOrder(['333.33', '66.67', '0.01']);

        app(RepairBillingWorkflow::class)->issueFor($order, false);

        $payment = $this->repairPayment($order);
        $lines = $this->lexware->lastCall('createFinalizedInvoice')['payload']['lineItems'];

        $this->assertCount(3, $lines);
        $this->assertSame(LexwareInvoice::first()->status->value, 'documented');
        $this->assertNotNull($payment->stripe_payment_link_id, 'the amount check refused a legitimate offer');
        $this->assertSame($payment->amount_cents, $this->stripe->lastCallTo('createPaymentLink')['arguments']['amountCents']);
    }

    /** C. A position the workshop cannot repair. */
    public function test_a_not_repairable_position_is_billed_by_neither_side(): void
    {
        $order = $this->deliveredOrder(['600.00'], notRepairableIndex: 1);

        app(RepairBillingWorkflow::class)->issueFor($order, false);

        $lines = $this->lexware->lastCall('createFinalizedInvoice')['payload']['lineItems'];

        $this->assertCount(1, $lines);
        $this->assertNotNull($this->repairPayment($order)->stripe_payment_link_id);
    }

    /**
     * D. A repair that costs nothing still produces a 0,00 EUR Lexware invoice.
     * Pinned as current behaviour, not endorsed: whether accounting wants a
     * zero invoice at all is an open product decision.
     */
    public function test_a_zero_amount_repair_takes_no_money_but_still_invoices(): void
    {
        $order = $this->deliveredOrder(['0.00']);

        app(RepairBillingWorkflow::class)->issueFor($order, false);

        $this->assertSame(0, $this->stripe->countCallsTo('createPaymentLink'));
        $this->assertSame(PaymentStatus::NotRequired, $this->repairPayment($order)->status);
        Mail::assertNotQueued(RepairInvoiceAvailableMail::class);

        $this->assertSame(1, $this->lexware->callCount('createFinalizedInvoice'));
        $this->assertSame(0, $this->lexware->lastCall('createFinalizedInvoice')['payload']['lineItems'][0]['unitPrice']['netAmount']);
    }

    /** D2. A settlement event must never move a repair that was never owed. */
    public function test_a_webhook_cannot_settle_a_repair_that_costs_nothing(): void
    {
        $order = $this->deliveredOrder(['0.00']);
        app(RepairBillingWorkflow::class)->issueFor($order, false);

        $payment = $this->repairPayment($order);
        $invoice = LexwareInvoice::first();

        $this->stripe->webhookEvent = $event = [
            'id' => 'evt_zero',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_zero',
                'payment_status' => 'paid',
                'amount_total' => 0,
                'currency' => 'eur',
                'metadata' => [
                    'purpose' => 'repair',
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'vehicle_id' => $order->vehicle_id,
                    'lexware_invoice_id' => (string) $invoice?->lexware_invoice_id,
                ],
            ]],
        ];

        $this->postJson(route('webhooks.stripe'), $event, ['Stripe-Signature' => 't=1,v1=fake'])->assertOk();

        $this->assertSame(PaymentStatus::NotRequired, $this->repairPayment($order)->status);
        $this->assertNull($this->repairPayment($order)->paid_at);
    }

    /**
     * E. A hand-uploaded invoice does not stop automation, so the customer can
     * end up holding two. Pinned as current behaviour: which one wins is an
     * open product decision.
     */
    public function test_a_manual_rechnung_and_the_generated_one_both_survive(): void
    {
        $order = $this->deliveredOrder();
        $this->publishDocument($order, DocumentType::Rechnung->value);

        app(RepairBillingWorkflow::class)->issueFor($order, false);

        $this->assertSame(
            2,
            VehicleReportDocument::where('auftragsnummer', $order->auftragsnummer)
                ->where('document_type', DocumentType::Rechnung->value)
                ->count(),
        );
    }

    /** F. The order is called off after the invoice exists. */
    public function test_a_cancelled_order_shows_no_follow_up_after_billing(): void
    {
        $order = $this->deliveredOrder();
        app(RepairBillingWorkflow::class)->issueFor($order, false);

        $order->update(['order_status' => 'cancelled']);
        $this->travelTo(CarbonImmutable::parse('2026-09-10 09:00:00', 'Europe/Berlin'));

        $tasks = $this->tasks($order->fresh());

        $this->assertSame([], $tasks['detached']);
        $this->assertSame('neutral', $tasks['priority']);
    }

    /** G. Lexware disabled: no link is ever created, so nothing may go red. */
    public function test_without_a_payment_request_the_payment_wait_never_goes_red(): void
    {
        $order = $this->deliveredOrder();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'Europe/Berlin'));

        $tasks = $this->tasks($order->fresh());

        $this->assertSame('await_repair_payment', $tasks['next']['key']);
        $this->assertSame('neutral', $tasks['priority']);
        $this->assertSame([], $tasks['detached']);
    }

    /** H. Stripe sends the currency in upper case. */
    public function test_an_uppercase_currency_still_settles(): void
    {
        $order = $this->deliveredOrder();
        app(RepairBillingWorkflow::class)->issueFor($order, false);

        $payment = $this->repairPayment($order);
        $invoice = LexwareInvoice::first();

        $this->stripe->webhookEvent = $event = [
            'id' => 'evt_case',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_case',
                'payment_status' => 'paid',
                'amount_total' => $payment->amount_cents,
                'currency' => 'EUR',
                'metadata' => [
                    'purpose' => 'repair',
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'auftragsnummer' => $order->auftragsnummer,
                    'vehicle_id' => $order->vehicle_id,
                    'lexware_invoice_id' => $invoice->lexware_invoice_id,
                    'voucher_number' => $invoice->voucher_number,
                ],
            ]],
        ];

        $this->postJson(route('webhooks.stripe'), $event, ['Stripe-Signature' => 't=1,v1=fake'])->assertOk();

        $this->assertSame(PaymentStatus::Paid, $this->repairPayment($order)->status);
    }

    /** I. Two workers reach the billing workflow at once. */
    public function test_a_second_worker_cannot_double_bill(): void
    {
        $order = $this->deliveredOrder();

        app(RepairBillingWorkflow::class)->issueFor($order, false);

        DB::table('lexware_invoices')->update(['status' => 'pending', 'lexware_invoice_id' => null]);

        try {
            app(RepairBillingWorkflow::class)->issueFor($order->fresh(), false);
        } catch (\Throwable) {
        }

        $this->assertSame(1, $this->lexware->callCount('createFinalizedInvoice'), 'a second legal invoice was issued');
    }

    /** J. The customer pays; the pickup task must be timed from the payment. */
    public function test_confirm_pickup_is_green_immediately_after_payment(): void
    {
        $order = $this->deliveredOrder();
        app(RepairBillingWorkflow::class)->issueFor($order, false);

        app(PaymentService::class)
            ->transition($this->repairPayment($order), PaymentStatus::Paid);

        $tasks = $this->tasks($order->fresh());

        $this->assertSame('confirm_pickup', $tasks['next']['key']);
        $this->assertSame('green', $tasks['priority']);
    }

    // ----------------------------------------------------------------- helpers

    private function repairPayment(LeasybackOrder $order): ?OrderPayment
    {
        return OrderPayment::where('order_id', $order->id)->where('purpose', PaymentPurpose::Repair->value)->first();
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

    /**
     * @param  list<string>  $amounts
     */
    private function deliveredOrder(array $amounts = ['600.00'], ?int $notRepairableIndex = null): LeasybackOrder
    {
        $order = $this->b2cOrder();
        $this->withPositions($order, max(count($amounts), ($notRepairableIndex ?? 0) + 1));
        $this->accept($order, $this->publishedOffer($order, $amounts, $notRepairableIndex));
        $this->commission($order);
        $this->saveAppointment($order->fresh());
        $order = $order->fresh();

        $this->advance($order, 'reinspection');
        $this->publishDocument($order, 'nachgutachten');
        $this->advance($order->fresh(), 'delivered');

        return $order->fresh();
    }

    private function b2cOrder(): LeasybackOrder
    {
        $address = Address::factory()->create([
            'street' => 'Flottenstraße', 'number' => '12', 'zip_code' => '10115', 'city' => 'Berlin', 'country' => 'Deutschland',
        ]);
        $contact = Contact::factory()->create([
            'address_id' => $address->address_id, 'salutation' => 'Herr', 'first_name' => 'Max', 'last_name' => 'Mustermann',
        ]);
        $user = User::factory()->create(['user_type' => UserType::Privatkunde]);
        LeasybackUserProfile::create(['user_id' => $user->id, 'email' => $user->email, 'contact_id' => $contact->contact_id]);

        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C', 'b2b_id' => null, 'b2c_user_id' => $user->id, 'make' => 'Volkswagen', 'model' => 'Passat',
        ]);

        $order = LeasybackOrder::factory()->create(['vehicle_id' => $vehicle->vehicle_id, 'order_status' => 'inspected']);
        OrderPaymentMethod::factory()->saved()->create(['order_id' => $order->id]);

        return $order;
    }

    private function withPositions(LeasybackOrder $order, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            AppraisalPosition::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'sort_order' => $i,
                'component' => 'Bauteil '.($i + 1),
                'damage_description' => 'Kratzer',
                'original_amount_net' => '1000.00',
                'repair_method' => 'Instandsetzung',
                'source' => AppraisalPosition::SOURCE_MANUAL,
            ]);
        }
    }

    /**
     * @param  list<string>  $amounts
     */
    private function publishedOffer(LeasybackOrder $order, array $amounts, ?int $notRepairableIndex): LeasybackOffer
    {
        $admin = $this->makeAdmin();
        $quotation = app(WorkshopQuotationService::class)
            ->invite($order, $admin, ['workshop_label' => 'Karosserie Meier GmbH'])['quotation'];

        $items = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->get()
            ->values()
            ->map(fn (AppraisalPosition $position, int $index) => [
                'appraisal_position_id' => $position->id,
                'amount_net' => $amounts[$index] ?? '0.00',
                'not_repairable' => $index === $notRepairableIndex,
            ])
            ->all();

        app(WorkshopQuotationService::class)->submit($quotation, [
            'company_name' => 'Karosserie Meier GmbH',
            'contact_person' => 'Kontakt Person',
            'contact_email' => 'kontakt@werkstatt.test',
            'contact_phone' => '+49 30 123456',
            'earliest_repair_start' => '2026-10-15',
            'processing_days' => 3,
            'items' => $items,
        ]);

        $this->actingAs($admin)->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.b2b-offer.store', $order->id), ['workshop_quotation_id' => $quotation->id])
            ->assertSessionHasNoErrors();

        $offer = LeasybackOffer::where('order_id', $order->id)->orderByDesc('offer_sequence')->firstOrFail();

        $this->actingAs($admin)->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.offers.publish', $offer->offer_id))
            ->assertSessionHasNoErrors();

        return $offer->fresh();
    }

    private function accept(LeasybackOrder $order, LeasybackOffer $offer): void
    {
        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->sole();
        $this->actingAs(User::findOrFail($vehicle->b2c_user_id))->from('/dashboard')
            ->post(route('offers.select', $offer->offer_id))->assertSessionHasNoErrors();
    }

    private function commission(LeasybackOrder $order): void
    {
        $this->actingAs($this->makeAdmin())->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.commission-workshop', $order->id))->assertSessionHasNoErrors();
    }

    private function saveAppointment(LeasybackOrder $order): void
    {
        $this->actingAs($this->makeAdmin())->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.repair-appointment', $order->id), ['confirmed_repair_start_date' => '2026-09-01'])
            ->assertSessionHasNoErrors();
    }

    private function advance(LeasybackOrder $order, string $status): void
    {
        $this->actingAs($this->makeAdmin())->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.status', $order->id), ['status' => $status])->assertSessionHasNoErrors();
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
