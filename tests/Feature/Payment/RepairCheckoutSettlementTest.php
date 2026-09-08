<?php

namespace Tests\Feature\Payment;

use App\Enums\UserType;
use App\Mail\Orders\RepairPaymentReceivedMail;
use App\Models\Address;
use App\Models\Contact;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Services\DetachedOrderTaskResolver;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Payment\Contracts\LexwareGateway;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Jobs\IssueRepairInvoice;
use App\Modules\UserProfile\Payment\Models\LexwareInvoice;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use App\Modules\UserProfile\Payment\Services\RepairBillingWorkflow;
use App\Modules\UserProfile\Profile\Models\LeasybackUserProfile;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\Support\FakeLexwareGateway;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * Payment Link settlement: the signed webhook that proves the money arrived,
 * the pickup authorisation it releases, and the unpaid-payment clock.
 */
class RepairCheckoutSettlementTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private const BILLED_AT = '2026-09-01 09:00:00';

    private FakeLexwareGateway $lexware;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake([IssueRepairInvoice::class]);
        Storage::fake('documents');
        config([
            'broadcasting.default' => 'null',
            'services.lexware.mode' => 'test',
            'services.stripe.webhook_secret' => 'whsec_test',
        ]);

        $this->lexware = new FakeLexwareGateway;
        $this->app->instance(LexwareGateway::class, $this->lexware);
        $this->stripe = $this->app->make(StripeGateway::class);

        $this->travelTo($this->berlin(self::BILLED_AT));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------- the webhook

    public function test_a_paid_checkout_session_settles_the_repair_charge(): void
    {
        $order = $this->billedOrder();

        $this->sendCheckout($order)->assertOk();

        $payment = $this->repairPayment($order);

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
        Mail::assertQueued(RepairPaymentReceivedMail::class, 1);
    }

    public function test_an_invalid_signature_is_refused_by_the_existing_middleware(): void
    {
        $order = $this->billedOrder();
        $this->stripe->webhookSignatureValid = false;

        $this->postJson(route('webhooks.stripe'), $this->checkoutEvent($order), ['Stripe-Signature' => 'bad'])
            ->assertStatus(401);

        $this->assertSame(PaymentStatus::Pending, $this->repairPayment($order)->status);
        Mail::assertNotQueued(RepairPaymentReceivedMail::class);
    }

    public function test_an_unpaid_checkout_session_never_settles(): void
    {
        $order = $this->billedOrder();

        $this->sendCheckout($order, ['payment_status' => 'unpaid'])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $this->repairPayment($order)->status);
        Mail::assertNotQueued(RepairPaymentReceivedMail::class);
    }

    public function test_an_async_success_settles_the_repair_charge(): void
    {
        $order = $this->billedOrder();

        $this->sendCheckout($order, ['payment_status' => 'unpaid'], 'checkout.session.async_payment_succeeded')->assertOk();

        $this->assertSame(PaymentStatus::Paid, $this->repairPayment($order)->status);
    }

    public function test_a_wrong_amount_is_refused(): void
    {
        $order = $this->billedOrder();

        $this->sendCheckout($order, ['amount_total' => 100])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $this->repairPayment($order)->status);
    }

    public function test_a_wrong_currency_is_refused(): void
    {
        $order = $this->billedOrder();

        $this->sendCheckout($order, ['currency' => 'usd'])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $this->repairPayment($order)->status);
    }

    public function test_a_payment_that_belongs_to_another_order_is_refused(): void
    {
        $order = $this->billedOrder();
        $other = $this->billedOrder();

        $event = $this->checkoutEvent($order);
        $event['data']['object']['metadata']['order_id'] = $other->id;

        $this->send($event)->assertOk();

        $this->assertSame(PaymentStatus::Pending, $this->repairPayment($order)->status);
        $this->assertSame(PaymentStatus::Pending, $this->repairPayment($other)->status);
    }

    public function test_a_session_naming_another_vehicle_is_refused(): void
    {
        $order = $this->billedOrder();

        $event = $this->checkoutEvent($order);
        $event['data']['object']['metadata']['vehicle_id'] = 'not-this-vehicle';

        $this->send($event)->assertOk();

        $this->assertSame(PaymentStatus::Pending, $this->repairPayment($order)->status);
    }

    public function test_a_session_naming_another_lexware_invoice_is_refused(): void
    {
        $order = $this->billedOrder();

        $event = $this->checkoutEvent($order);
        $event['data']['object']['metadata']['lexware_invoice_id'] = 'invoice-not-mine';

        $this->send($event)->assertOk();

        $this->assertSame(PaymentStatus::Pending, $this->repairPayment($order)->status);
    }

    public function test_a_session_for_another_purpose_is_ignored(): void
    {
        $order = $this->billedOrder();

        $event = $this->checkoutEvent($order);
        $event['data']['object']['metadata']['purpose'] = 'cancellation_fee';

        $this->send($event)->assertOk();

        $this->assertSame(PaymentStatus::Pending, $this->repairPayment($order)->status);
    }

    public function test_a_replayed_session_settles_once_and_mails_once(): void
    {
        $order = $this->billedOrder();

        $this->sendCheckout($order)->assertOk();
        $paidAt = $this->repairPayment($order)->paid_at;

        $this->travelTo($this->berlin('2026-09-02 09:00:00'));
        $this->sendCheckout($order)->assertOk();
        $this->sendCheckout($order, [], 'checkout.session.async_payment_succeeded')->assertOk();

        $payment = $this->repairPayment($order);

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertEquals($paidAt, $payment->paid_at);
        Mail::assertQueued(RepairPaymentReceivedMail::class, 1);
    }

    public function test_a_payment_intent_event_for_a_link_payment_cannot_double_settle(): void
    {
        $order = $this->billedOrder();

        $this->sendCheckout($order)->assertOk();

        $this->send([
            'id' => 'evt_pi',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_from_link',
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'amount' => 71400,
                'currency' => 'eur',
                'metadata' => ['purpose' => 'repair'],
            ]],
        ])->assertOk();

        Mail::assertQueued(RepairPaymentReceivedMail::class, 1);
        $this->assertSame(PaymentStatus::Paid, $this->repairPayment($order)->status);
    }

    public function test_the_pickup_email_failure_leaves_the_payment_paid(): void
    {
        $order = $this->billedOrder();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->sendCheckout($order)->assertOk();

        $this->assertSame(PaymentStatus::Paid, $this->repairPayment($order)->status);
        $this->assertSame(1, LexwareInvoice::count());
        $this->assertSame('plink_1', $this->repairPayment($order)->stripe_payment_link_id);
    }

    // ------------------------------------------------------- the payment timer

    public function test_the_payment_wait_runs_green_yellow_red_from_the_payment_request(): void
    {
        $order = $this->billedOrder();

        $this->assertSame('await_repair_payment', $this->tasks($order)['next']['key']);
        $this->assertSame('green', $this->tasks($order)['priority']);

        $this->travelTo($this->berlin('2026-09-02 08:59:59'));
        $this->assertSame('green', $this->tasks($order)['priority']);

        $this->travelTo($this->berlin('2026-09-02 09:00:00'));
        $this->assertSame('yellow', $this->tasks($order)['priority']);

        $this->travelTo($this->berlin('2026-09-03 08:59:59'));
        $this->assertSame('yellow', $this->tasks($order)['priority']);

        $this->travelTo($this->berlin('2026-09-03 09:00:00'));
        $this->assertSame('red', $this->tasks($order)['priority']);
    }

    // ------------------------------------------------- the detached follow-up

    public function test_no_payment_follow_up_before_48_hours(): void
    {
        $order = $this->billedOrder();

        $this->travelTo($this->berlin('2026-09-03 08:59:59'));

        $this->assertSame([], $this->tasks($order)['detached']);
    }

    public function test_the_payment_follow_up_appears_red_at_exactly_48_hours(): void
    {
        $order = $this->billedOrder();

        $this->travelTo($this->berlin('2026-09-03 09:00:00'));

        $detached = $this->tasks($order)['detached'];

        $this->assertCount(1, $detached);
        $this->assertSame(DetachedOrderTaskResolver::CALL_CUSTOMER_ABOUT_PENDING_PAYMENT, $detached[0]['key']);
        $this->assertSame('immediate_red', $detached[0]['priority']);
    }

    public function test_the_payment_follow_up_coexists_with_the_guided_wait(): void
    {
        $order = $this->billedOrder();

        $this->travelTo($this->berlin('2026-09-10 09:00:00'));

        $tasks = $this->tasks($order);

        $this->assertSame('await_repair_payment', $tasks['next']['key']);
        $this->assertSame('red', $tasks['priority']);
        $this->assertCount(1, $tasks['detached']);
        $this->assertSame('immediate_red', $tasks['detached'][0]['priority']);
    }

    public function test_payment_removes_the_follow_up_and_opens_the_pickup(): void
    {
        $order = $this->billedOrder();

        $this->travelTo($this->berlin('2026-09-10 09:00:00'));
        $this->assertCount(1, $this->tasks($order)['detached']);

        $this->sendCheckout($order)->assertOk();

        $tasks = $this->tasks($order->fresh());

        $this->assertSame([], $tasks['detached']);
        $this->assertSame('confirm_pickup', $tasks['next']['key']);
        $this->assertContains('await_repair_payment', array_column($tasks['history'], 'key'));
    }

    public function test_confirm_pickup_is_still_timed_from_the_settled_payment(): void
    {
        $order = $this->billedOrder();

        $this->sendCheckout($order)->assertOk();

        $this->assertSame('green', $this->tasks($order->fresh())['priority']);

        $this->travelTo($this->berlin('2026-09-02 09:00:00'));
        $this->assertSame('yellow', $this->tasks($order->fresh())['priority']);

        $this->travelTo($this->berlin('2026-09-03 09:00:00'));
        $this->assertSame('red', $this->tasks($order->fresh())['priority']);
    }

    // ------------------------------------------------------------ the guards

    public function test_a_zero_amount_repair_has_no_timer_and_no_follow_up(): void
    {
        $order = $this->billedOrder(amountNet: '0.00');

        $this->travelTo($this->berlin('2026-09-10 09:00:00'));

        $tasks = $this->tasks($order->fresh());

        $this->assertSame(PaymentStatus::NotRequired, $this->repairPayment($order)->status);
        $this->assertSame([], $tasks['detached']);
        $this->assertNotSame('await_repair_payment', $tasks['next']['key']);
    }

    public function test_a_failed_reinspection_settles_nothing(): void
    {
        $order = $this->inRepair();
        $this->advance($order, 'reinspection');
        $this->publishDocument($order, 'nachgutachten');
        $this->advance($order->fresh(), 'reworkshop');

        $this->travelTo($this->berlin('2026-09-10 09:00:00'));

        $this->assertNull($this->repairPayment($order));
        $this->assertSame([], $this->tasks($order->fresh())['detached']);
        Mail::assertNotQueued(RepairPaymentReceivedMail::class);
    }

    public function test_b2b_orders_have_no_payment_follow_up(): void
    {
        $company = $this->makeCompany('Flotten GmbH');
        $order = $this->makeB2bOrder($this->makeB2bVehicle($company), 'reinspection');

        $this->travelTo($this->berlin('2026-09-10 09:00:00'));

        $this->assertSame([], $this->tasks($order)['detached']);
        $this->assertSame('neutral', $this->tasks($order)['priority']);
    }

    // ----------------------------------------------------------------- helpers

    private function berlin(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'Europe/Berlin');
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

    /**
     * @return array<string, mixed>
     */
    private function checkoutEvent(LeasybackOrder $order, array $overrides = [], string $type = 'checkout.session.completed'): array
    {
        $payment = $this->repairPayment($order);
        $invoice = LexwareInvoice::where('order_id', $order->id)->first();

        return [
            'id' => 'evt_checkout_'.$order->id,
            'type' => $type,
            'data' => ['object' => [
                'id' => 'cs_test_'.$order->id,
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'amount_total' => (int) ($payment?->amount_cents ?? 0),
                'currency' => 'eur',
                'metadata' => [
                    'purpose' => 'repair',
                    'payment_id' => (string) $payment?->id,
                    'order_id' => $order->id,
                    'auftragsnummer' => $order->auftragsnummer,
                    'vehicle_id' => $order->vehicle_id,
                    'lexware_invoice_id' => (string) $invoice?->lexware_invoice_id,
                    'voucher_number' => (string) $invoice?->voucher_number,
                ],
                ...$overrides,
            ]],
        ];
    }

    private function sendCheckout(LeasybackOrder $order, array $overrides = [], string $type = 'checkout.session.completed'): TestResponse
    {
        return $this->send($this->checkoutEvent($order, $overrides, $type));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function send(array $event): TestResponse
    {
        $this->stripe->webhookEvent = $event;

        return $this->postJson(route('webhooks.stripe'), $event, ['Stripe-Signature' => 't=1,v1=fake']);
    }

    // ------------------------------------------------------------- the states

    private function billedOrder(string $amountNet = '600.00'): LeasybackOrder
    {
        $order = $this->deliveredOrder($amountNet);

        app(RepairBillingWorkflow::class)->issueFor($order, false);

        return $order->fresh();
    }

    private function deliveredOrder(string $amountNet): LeasybackOrder
    {
        $order = $this->inRepair($amountNet);
        $this->advance($order, 'reinspection');
        $this->publishDocument($order, 'nachgutachten');
        $this->advance($order->fresh(), 'delivered');

        return $order->fresh();
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
