<?php

namespace Tests\Feature\Payment;

use App\Enums\DocumentType;
use App\Enums\UserType;
use App\Mail\Orders\RepairInvoiceAvailableMail;
use App\Models\Address;
use App\Models\Contact;
use App\Models\User;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Contracts\LexwareGateway;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Enums\LexwareInvoiceStatus;
use App\Modules\UserProfile\Payment\Jobs\IssueRepairInvoice;
use App\Modules\UserProfile\Payment\Models\LexwareInvoice;
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
use Tests\TestCase;

class ManualOfferLexwareInvoiceTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    private FakeLexwareGateway $lexware;

    private StripeGateway $stripe;

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

    public function test_a_new_manual_b2c_offer_has_the_vat_rate_stamped_on_creation(): void
    {
        $order = $this->b2cOrder();

        $offer = $this->createManualOffer($order, '1000.00', '1190.00');

        $this->assertSame('0.1900', $offer->fresh()->vat_rate);
    }

    public function test_a_manual_offer_of_1000_net_invoices_at_19_percent_for_1190_gross_and_stripe_matches(): void
    {
        $order = $this->manualOrder('1000.00', '1190.00');

        $invoice = app(RepairBillingWorkflow::class)->issueFor($order, false);

        $this->assertNotNull($invoice);
        $this->assertSame(LexwareInvoiceStatus::Documented, $invoice->status);

        $line = $this->lexware->lastCall('createFinalizedInvoice')['payload']['lineItems'][0];
        $this->assertSame(1000, $line['unitPrice']['netAmount']);
        $this->assertSame(19, $line['unitPrice']['taxRatePercentage']);

        $document = VehicleReportDocument::findOrFail($invoice->document_id);
        $this->assertSame(DocumentType::Rechnung->value, $document->document_type);
        Storage::disk('documents')->assertExists($document->path);

        $payment = DB::table('order_payments')->where('order_id', $order->id)->where('purpose', 'repair')->first();
        $this->assertSame(119000, (int) $payment->amount_cents);
        $this->assertNotNull($payment->stripe_payment_link_id);

        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentLink'));
        $this->assertSame(119000, $this->stripe->lastCallTo('createPaymentLink')['arguments']['amountCents']);
        Mail::assertQueued(RepairInvoiceAvailableMail::class, 1);
    }

    public function test_a_second_run_creates_no_second_manual_invoice_or_payment_link(): void
    {
        $order = $this->manualOrder('1000.00', '1190.00');

        app(RepairBillingWorkflow::class)->issueFor($order, false);
        app(RepairBillingWorkflow::class)->issueFor($order->fresh(), false);
        app(RepairBillingWorkflow::class)->issueFor($order->fresh(), false);

        $this->assertSame(1, LexwareInvoice::count());
        $this->assertSame(1, $this->lexware->callCount('createFinalizedInvoice'));
        $this->assertSame(1, $this->stripe->countCallsTo('createPaymentLink'));
        Mail::assertQueued(RepairInvoiceAvailableMail::class, 1);
    }

    public function test_a_legacy_manual_offer_without_a_vat_snapshot_still_invoices_at_19_percent(): void
    {
        $order = $this->manualOrder('1000.00', '1190.00');

        DB::table('leasyback_offers')->where('order_id', $order->id)->update(['vat_rate' => null]);

        $invoice = app(RepairBillingWorkflow::class)->issueFor($order, false);

        $this->assertSame(LexwareInvoiceStatus::Documented, $invoice->status);
        $line = $this->lexware->lastCall('createFinalizedInvoice')['payload']['lineItems'][0];
        $this->assertSame(19, $line['unitPrice']['taxRatePercentage']);
        $this->assertSame(1, LexwareInvoice::count());
        $this->assertSame(1, $this->lexware->callCount('createFinalizedInvoice'));
    }

    public function test_a_manual_b2b_offer_gets_no_vat_rate_and_no_invoice(): void
    {
        $company = $this->makeCompany('Flotten GmbH');
        $vehicle = $this->makeB2bVehicle($company);
        $order = $this->makeB2bOrder($vehicle, 'inspected');

        $offer = $this->createManualOffer($order, '1000.00', '1190.00');

        $this->assertNull($offer->fresh()->vat_rate);
        $this->assertNull(app(RepairBillingWorkflow::class)->issueFor($order, true));
        $this->assertSame(0, LexwareInvoice::count());
    }

    // ----------------------------------------------------------------- helpers

    private function manualOrder(string $repairCostNet, string $repairCostGross): LeasybackOrder
    {
        $order = $this->b2cOrder();
        $owner = $this->ownerOf($order);

        $offer = $this->createManualOffer($order, $repairCostNet, $repairCostGross);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson("/admin/offers/publish/{$offer->offer_id}")
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/vehicle/offers/customer/select/{$offer->offer_id}")
            ->assertOk();

        $this->advance($order->fresh(), 'workshop');
        $this->saveAppointment($order->fresh());
        $this->advance($order->fresh(), 'reinspection');
        $this->publishDocument($order->fresh(), 'nachgutachten');
        $this->advance($order->fresh(), 'delivered');

        return $order->fresh();
    }

    private function createManualOffer(LeasybackOrder $order, string $repairCostNet, string $repairCostGross): LeasybackOffer
    {
        $response = $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson("/admin/offers/create/{$order->auftragsnummer}", [
                'repair_cost_net' => $repairCostNet,
                'repair_cost_gross' => $repairCostGross,
                'depreciation_value_net' => 0,
                'depreciation_value_gross' => 0,
                'workshop_repair_quote_net' => 0,
                'workshop_repair_quote_gross' => 0,
                'missing_parts_cost_net' => 0,
                'missing_parts_cost_gross' => 0,
            ])
            ->assertCreated();

        return LeasybackOffer::findOrFail($response->json('offer_id'));
    }

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
