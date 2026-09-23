<?php

namespace Tests\Feature\B2b;

use App\Mail\Orders\AppointmentConfirmedMail;
use App\Mail\Orders\B2bCollectionRescheduledMail;
use App\Mail\Orders\B2bCollectionScheduledMail;
use App\Mail\Orders\RepairQuotationAvailableMail;
use App\Models\B2B;
use App\Models\User;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Admin\Services\AdminTaskQueryService;
use App\Modules\UserProfile\B2B\Services\B2bServiceFeeService;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderLogistics;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Services\RepairOfferService;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Payment\Contracts\LexwareGateway;
use App\Modules\UserProfile\Payment\Exceptions\LexwareGatewayException;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\Support\FakeLexwareGateway;
use Tests\TestCase;

/**
 * Regression coverage for the B2B order flow QA pass: every test here pins a
 * defect that was reproduced end to end through the real routes.
 */
class B2bOrderFlowRegressionTest extends TestCase
{
    use BuildsB2bCompanies, RefreshDatabase;

    private B2B $company;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Storage::fake('documents');

        $this->company = $this->makeCompany('Flotte GmbH');
        $this->owner = $this->makeOwner($this->company);
        $this->admin = $this->makeAdmin();
    }

    // ------------------------------------------------------------ offers

    public function test_an_offer_cannot_be_accepted_once_the_order_is_cancelled(): void
    {
        $order = $this->inspectedOrder();
        $offer = $this->publishedOffer($order, ['800']);

        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'cancelled'])->assertSessionHasNoErrors();

        // Cancelling withdraws the offer, so there is nothing left to accept.
        $this->assertSame('cancelled', $offer->fresh()->offer_status);

        $this->actingAs($this->owner)->from('/dashboard')
            ->post(route('offers.select', $offer->offer_id))
            ->assertSessionHasErrors('offer');

        $this->assertSame('cancelled', $order->fresh()->order_status);
        $this->assertSame('cancelled', $offer->fresh()->offer_status);
    }

    public function test_acceptance_is_refused_on_a_closed_order_even_if_the_offer_is_still_published(): void
    {
        $order = $this->inspectedOrder();
        $offer = $this->publishedOffer($order, ['800']);

        // A legacy row: cancelled before cancellation withdrew offers.
        $order->forceFill(['order_status' => 'cancelled'])->save();

        $this->actingAs($this->owner)->from('/dashboard')
            ->post(route('offers.select', $offer->offer_id))
            ->assertSessionHasErrors('offer');

        $this->actingAs($this->owner)->from('/dashboard')
            ->post(route('offers.reject', $offer->offer_id))
            ->assertSessionHasErrors('offer');

        $this->assertSame('published', $offer->fresh()->offer_status);
    }

    public function test_cancelling_an_order_revokes_open_workshop_links(): void
    {
        $order = $this->inspectedOrder();
        $invite = app(WorkshopQuotationService::class)->invite($order, $this->admin, ['workshop_label' => 'Werkstatt']);

        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'cancelled']);

        $this->assertTrue($invite['quotation']->fresh()->isRevoked());
        $this->app['auth']->forgetGuards();
        $this->get(route('workshop.quotations.show', $invite['token']))->assertNotFound();
    }

    public function test_the_saving_only_counts_positions_the_workshop_priced(): void
    {
        $order = $this->inspectedOrder(['1000', '500']);
        $offer = $this->publishedOffer($order, ['800', null]);

        $presentation = B2bOfferPresentation::where('offer_id', $offer->offer_id)->sole();

        $this->assertSame('1500.00', (string) $presentation->appraisal_total_net);
        $this->assertSame('800.00', (string) $presentation->repair_total_net);
        // 1000 − 800 on the priced position; the unpriced 500 saves nothing.
        $this->assertSame('200.00', (string) $presentation->saving_net);
    }

    public function test_an_accepted_offer_cannot_be_cancelled(): void
    {
        $order = $this->inspectedOrder();
        $offer = $this->publishedOffer($order, ['800']);
        $this->accept($offer);

        $this->adminPatch(route('admin.orders.offers.cancel', $offer->offer_id))->assertSessionHasErrors('offer');

        $this->assertSame('selected', $offer->fresh()->offer_status);
    }

    public function test_no_further_offer_can_be_created_after_acceptance(): void
    {
        $order = $this->inspectedOrder();
        $quotation = $this->submittedQuotation($order, ['700']);
        $offer = $this->publishedOffer($order, ['800']);
        $this->accept($offer);

        $this->adminPost(route('admin.orders.b2b-offer.store', $order->id), ['workshop_quotation_id' => $quotation->id])
            ->assertSessionHasErrors('offer');
    }

    public function test_a_manual_offer_cannot_be_added_to_a_closed_or_decided_order(): void
    {
        $amounts = [
            'repair_cost_net' => '500', 'repair_cost_gross' => '0', 'depreciation_value_net' => '0', 'depreciation_value_gross' => '0',
            'workshop_repair_quote_net' => '0', 'workshop_repair_quote_gross' => '0', 'missing_parts_cost_net' => '0', 'missing_parts_cost_gross' => '0',
        ];

        $cancelled = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'cancelled');
        $this->adminPost(route('admin.orders.offers.store', $cancelled->id), $amounts)->assertSessionHasErrors('offer');

        $decided = $this->inspectedOrder();
        $this->accept($this->publishedOffer($decided, ['800']));
        $this->adminPost(route('admin.orders.offers.store', $decided->id), $amounts)->assertSessionHasErrors('offer');

        $this->assertSame(0, LeasybackOffer::where('order_id', $cancelled->id)->count());
        $this->assertSame(1, LeasybackOffer::where('order_id', $decided->id)->count());
    }

    public function test_a_cannot_repair_quotation_does_not_become_a_zero_euro_draft(): void
    {
        $order = $this->inspectedOrder();
        $invite = app(WorkshopQuotationService::class)->invite($order, $this->admin, ['workshop_label' => 'W']);
        app(WorkshopQuotationService::class)->submit($invite['quotation'], [
            'company_name' => 'W', 'contact_person' => 'W', 'contact_email' => 'w@example.test',
            'cannot_repair_for_amount' => true, 'items' => [],
        ]);

        $this->adminPost(route('admin.orders.b2b-offer.store', $order->id), ['workshop_quotation_id' => $invite['quotation']->id])
            ->assertSessionHasErrors('offer');

        $this->assertSame(0, LeasybackOffer::where('order_id', $order->id)->count());
    }

    public function test_a_submitted_quotation_cannot_be_revoked(): void
    {
        $order = $this->inspectedOrder();
        $quotation = $this->submittedQuotation($order, ['800']);

        $this->actingAs($this->admin)->from(route('admin.orders.show', $order->id))
            ->delete(route('admin.orders.workshop-quotations.revoke', $quotation->id))
            ->assertSessionHasErrors('quotation');

        $this->assertFalse($quotation->fresh()->isRevoked());
    }

    public function test_a_workshop_repeating_a_position_gets_a_validation_error_not_a_500(): void
    {
        $order = $this->inspectedOrder();
        $invite = app(WorkshopQuotationService::class)->invite($order, $this->admin, ['workshop_label' => 'W']);
        $positionId = AppraisalPosition::where('order_id', $order->id)->value('id');

        $this->app['auth']->forgetGuards();
        $this->post(route('workshop.quotations.submit', $invite['token']), [
            'company_name' => 'W', 'contact_person' => 'W', 'contact_email' => 'w@example.test',
            'items' => [
                ['appraisal_position_id' => $positionId, 'amount_net' => '100'],
                ['appraisal_position_id' => $positionId, 'amount_net' => '1'],
            ],
        ])->assertSessionHasErrors('items.0.appraisal_position_id');

        $this->assertTrue($invite['quotation']->fresh()->isOpenForSubmission());
    }

    public function test_workshops_cannot_be_invited_before_positions_exist(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'inspected');

        $this->adminPost(route('admin.orders.workshop-quotations.store', $order->id), ['workshop_label' => 'W'])
            ->assertSessionHasErrors('workshop_label');

        $this->assertSame(0, WorkshopQuotation::where('order_id', $order->id)->count());
    }

    public function test_b2b_offer_communication_never_shows_a_gross_price(): void
    {
        $order = $this->inspectedOrder();
        $offer = $this->publishedOffer($order, ['800']);

        Mail::assertQueued(RepairQuotationAvailableMail::class, function (RepairQuotationAvailableMail $mail) {
            $details = $mail->data->details();

            $this->assertArrayNotHasKey('Gesamtbetrag (brutto)', $details);
            $this->assertSame('800,00 €', $details['Gesamtbetrag (netto)']);

            return true;
        });

        $this->accept($offer);

        Notification::assertSentTo($this->admin, SystemNotification::class, function (SystemNotification $notification) {
            $body = $notification->toArray($this->admin)['body'];

            return str_contains($body, '800,00 € netto') && ! str_contains($body, 'brutto');
        });

        $this->assertNull(app(AdminQueryService::class)->orderDetail($order->id)['workshop_commission']['offer_total_gross']);
    }

    // --------------------------------------------------------- positions

    public function test_positions_priced_by_a_workshop_cannot_be_deleted(): void
    {
        $order = $this->inspectedOrder(['1000', '500']);
        $this->submittedQuotation($order, ['800', '400']);
        $keep = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->first();

        $this->adminPut(route('admin.orders.appraisal-positions', $order->id), ['positions' => [[
            'id' => $keep->id, 'component' => $keep->component, 'original_amount_net' => '1000',
        ]]])->assertSessionHasErrors('positions');

        $this->assertSame(2, AppraisalPosition::where('order_id', $order->id)->count());
        $this->assertSame(2, DB::table('b2b_workshop_quotation_items')->count());
    }

    public function test_positions_are_locked_once_an_offer_is_accepted(): void
    {
        $order = $this->inspectedOrder();
        $this->accept($this->publishedOffer($order, ['800']));

        $this->adminPut(route('admin.orders.appraisal-positions', $order->id), ['positions' => []])
            ->assertSessionHasErrors('positions');
    }

    // ------------------------------------------------------------ status

    public function test_the_status_endpoint_cannot_skip_customer_approval_or_the_appointment(): void
    {
        $order = $this->inspectedOrder();

        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'workshop_commissioned'])
            ->assertSessionHasErrors('status');
        $this->assertSame('inspected', $order->fresh()->order_status);

        $offer = $this->publishedOffer($order, ['800']);
        $this->accept($offer);
        $this->adminPost(route('admin.orders.commission-workshop', $order->id))->assertSessionHasNoErrors();

        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'workshop'])
            ->assertSessionHasErrors('status');
        $this->assertSame('workshop_commissioned', $order->fresh()->order_status);
    }

    public function test_b2b_statuses_require_the_fact_they_claim(): void
    {
        $action = app(TransitionOrderStatus::class);
        $vehicle = $this->makeB2bVehicle($this->company);

        foreach ([
            'confirmed' => 'vehicle_collected',
            'vehicle_collected' => 'inspected',
            'vehicle_returned' => 'invoice_processed',
        ] as $from => $to) {
            $order = $this->makeB2bOrder($vehicle, $from);

            try {
                $action($order, $to, 'admin', 'tester');
                $this->fail("{$from} → {$to} must be refused without its prerequisite");
            } catch (ValidationException) {
                $this->assertSame($from, $order->fresh()->order_status);
            }

            $order->forceFill(['order_status' => 'cancelled'])->save();
        }
    }

    public function test_the_admin_status_menu_only_offers_accepted_transitions(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'confirmed');

        $this->assertSame(['cancelled'], app(AdminQueryService::class)->orderDetail($order->id)['available_transitions']);

        $this->meetB2bPrerequisite($order, 'vehicle_collected');

        $this->assertSame(['vehicle_collected', 'cancelled'], app(AdminQueryService::class)->orderDetail($order->id)['available_transitions']);
    }

    public function test_a_b2b_request_can_be_declined(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'order_requested');

        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'discarded'])->assertSessionHasNoErrors();

        $this->assertSame('discarded', $order->fresh()->order_status);
    }

    // -------------------------------------------------------- collection

    public function test_confirming_the_collection_date_schedules_the_collection(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'order_requested');

        $this->adminPatch(route('admin.orders.collection', $order->id), [
            'confirmed_collection_date' => now()->addDays(3)->toDateString(),
            'internal_note' => 'Schlüssel beim Pförtner',
        ])->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $order->fresh()->order_status);
        Mail::assertQueued(B2bCollectionScheduledMail::class);
        Mail::assertNotQueued(AppointmentConfirmedMail::class);
    }

    public function test_a_partial_collection_save_keeps_the_date_and_note(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'order_placed');
        $date = now()->addDays(3)->toDateString();

        $this->adminPatch(route('admin.orders.collection', $order->id), ['confirmed_collection_date' => $date, 'internal_note' => 'Notiz']);
        $this->adminPatch(route('admin.orders.collection', $order->id), [
            'collection_address' => ['street' => 'Neue Str.', 'zip_code' => '80331', 'city' => 'München'],
        ])->assertSessionHasNoErrors();

        $logistics = OrderLogistics::where('auftragsnummer', $order->auftragsnummer)->sole();
        $this->assertSame($date, $logistics->confirmed_collection_date->toDateString());
        $this->assertSame('Notiz', $logistics->internal_note);
    }

    public function test_the_collection_date_cannot_move_into_the_past_or_after_collection(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'order_placed');

        $this->adminPatch(route('admin.orders.collection', $order->id), ['confirmed_collection_date' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('confirmed_collection_date');

        $collected = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'vehicle_collected');

        $this->adminPatch(route('admin.orders.collection', $collected->id), ['confirmed_collection_date' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('confirmed_collection_date');
    }

    public function test_moving_a_confirmed_collection_date_tells_the_customer(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'order_placed');

        $this->adminPatch(route('admin.orders.collection', $order->id), ['confirmed_collection_date' => now()->addDays(3)->toDateString()]);
        $this->adminPatch(route('admin.orders.collection', $order->id), ['confirmed_collection_date' => now()->addDays(5)->toDateString()]);

        Mail::assertQueued(B2bCollectionRescheduledMail::class, 1);
    }

    // ----------------------------------------------------- commissioning

    public function test_the_commission_task_uses_the_commission_endpoint_and_works(): void
    {
        $order = $this->inspectedOrder();
        $this->accept($this->publishedOffer($order, ['800']));

        $task = app(AdminQueryService::class)->orderDetail($order->id)['tasks']['next'];

        $this->assertSame('commission_workshop', $task['key']);
        $this->assertSame('beauftragung', $task['section']);
        $this->assertSame(route('admin.orders.commission-workshop', $order->id), $task['action']['url']);

        $this->adminPost($task['action']['url'], $task['action']['payload'])->assertSessionHasNoErrors();
        $this->assertSame('workshop_commissioned', $order->fresh()->order_status);
    }

    public function test_the_repair_appointment_cannot_be_saved_before_commissioning(): void
    {
        $order = $this->inspectedOrder();
        $this->accept($this->publishedOffer($order, ['800']));

        $this->adminPatch(route('admin.orders.repair-appointment', $order->id), ['confirmed_repair_start_date' => now()->addWeek()->toDateString()])
            ->assertSessionHasErrors('confirmed_repair_start_date');

        $this->adminPost(route('admin.orders.commission-workshop', $order->id));

        $this->assertSame('enter_repair_appointment', app(AdminQueryService::class)->orderDetail($order->id)['tasks']['next']['key']);
    }

    public function test_the_commission_mail_cannot_be_resent_after_cancellation(): void
    {
        $order = $this->inspectedOrder();
        $this->accept($this->publishedOffer($order, ['800']));
        $this->adminPost(route('admin.orders.commission-workshop', $order->id));
        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'cancelled']);

        $this->adminPost(route('admin.orders.commission-workshop.resend', $order->id))->assertSessionHasErrors('commission');
    }

    // -------------------------------------------------------------- tasks

    public function test_the_offer_phase_always_has_a_next_task(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'inspected');
        $this->assertSame('capture_repair_positions', $this->nextTaskKey($order));

        $this->addPositions($order, ['1000']);
        $this->assertSame('request_workshop_quotations', $this->nextTaskKey($order));

        $quotation = $this->submittedQuotation($order, ['800']);
        $this->assertSame('create_customer_offer', $this->nextTaskKey($order));

        $this->adminPost(route('admin.orders.b2b-offer.store', $order->id), [
            'workshop_quotation_id' => $quotation->id,
            'valid_until' => now()->toDateString(),
        ]);
        $task = app(AdminQueryService::class)->orderDetail($order->id)['tasks']['next'];
        $this->assertSame('prepare_customer_offer', $task['key']);
        $this->assertStringContainsString('/publish', $task['action']['url']);

        $this->adminPatch($task['action']['url'])->assertSessionHasNoErrors();
        $this->assertSame('await_customer_approval', $this->nextTaskKey($order));

        $this->travel(2)->days();
        $this->assertSame('renew_expired_offer', $this->nextTaskKey($order));
    }

    public function test_b2b_tasks_can_be_listed_on_their_own(): void
    {
        $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'order_placed');

        $tasks = app(AdminTaskQueryService::class)->openTasks(25, 'B2B');

        $this->assertSame(1, $tasks['channel_counts']['B2B']);
        $this->assertSame(['B2B'], array_values(array_unique(array_column($tasks['data'], 'vehicle_belongs'))));
    }

    // ------------------------------------------------------------ billing

    public function test_billing_cannot_be_processed_before_the_return_or_without_an_invoice(): void
    {
        $early = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'order_placed');
        $this->adminPatch(route('admin.orders.billing', $early->id), ['invoice_reference' => 'RE-1', 'mark_processed' => true])
            ->assertSessionHasErrors('invoice_reference');

        $returned = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'vehicle_returned');
        $this->adminPatch(route('admin.orders.billing', $returned->id), ['mark_processed' => true])
            ->assertSessionHasErrors('invoice_reference');

        $this->assertDatabaseCount('b2b_order_billing', 0);
    }

    public function test_billing_is_audited_and_locked_after_completion(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'vehicle_returned');

        $this->adminPatch(route('admin.orders.billing', $order->id), ['invoice_reference' => 'RE-7', 'mark_processed' => true])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('leasyback_order_audit_log', ['order_id' => $order->id, 'action' => 'BILLING_PROCESSED']);

        $this->adminPatch(route('admin.orders.billing', $order->id), ['invoice_reference' => ''])
            ->assertSessionHasErrors('invoice_reference');

        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'invoice_processed'])->assertSessionHasNoErrors();
        $this->adminPatch(route('admin.orders.status', $order->id), ['status' => 'completed'])->assertSessionHasNoErrors();

        $this->adminPatch(route('admin.orders.billing', $order->id), ['invoice_reference' => 'CHANGED'])
            ->assertSessionHasErrors('invoice_reference');
        $this->assertDatabaseHas('b2b_order_billing', ['order_id' => $order->id, 'invoice_reference' => 'RE-7']);
    }

    public function test_the_lexware_draft_carries_the_positions_and_the_service_fee(): void
    {
        $lexware = new FakeLexwareGateway;
        $this->app->instance(LexwareGateway::class, $lexware);
        app(B2bServiceFeeService::class)->update($this->company, '310.00', now()->toDateString());

        $order = $this->inspectedOrder(['1000']);
        $this->accept($this->publishedOffer($order, ['800']));
        $order->forceFill(['order_status' => 'vehicle_returned'])->save();

        $this->adminPost(route('admin.orders.billing.lexware-draft', $order->id), [
            'additional_positions' => [['name' => 'Transport', 'amount_net' => '120.50']],
        ])->assertSessionHasNoErrors();

        $payload = collect($lexware->calls)->firstWhere('method', 'createInvoice')['payload']
            ?? collect($lexware->calls)->last()['payload'];
        $lines = collect($payload['lineItems'])->pluck('unitPrice.netAmount', 'name');

        $this->assertEquals(800, $lines['Stoßfänger 1']);
        $this->assertEquals(310, $lines['Servicepauschale']);
        $this->assertEquals(120.5, $lines['Transport']);

        // Creating the draft downloads nothing: a draft voucher has no invoice
        // number and no renderable PDF, so asking for one could only fail —
        // which is what used to leave the document forever "being generated".
        $this->assertNotContains('downloadInvoiceFile', $lexware->methods());
        $this->assertSame(0, VehicleReportDocument::where('auftragsnummer', $order->auftragsnummer)->count());
        $this->assertDatabaseHas('lexware_invoices', [
            'order_id' => $order->id,
            'purpose' => 'b2b_billing',
            'document_id' => null,
            'voucher_number' => null,
        ]);

        // The draft is the invoice behind "processed".
        $this->adminPatch(route('admin.orders.billing', $order->id), ['mark_processed' => true])->assertSessionHasNoErrors();

        $this->adminPost(route('admin.orders.billing.lexware-draft', $order->id))->assertSessionHasErrors('lexware');
    }

    public function test_finalizing_the_draft_is_what_produces_the_invoice_document(): void
    {
        $lexware = new FakeLexwareGateway;
        $this->app->instance(LexwareGateway::class, $lexware);

        $order = $this->inspectedOrder(['1000']);
        $this->accept($this->publishedOffer($order, ['800']));
        $order->forceFill(['order_status' => 'vehicle_returned'])->save();

        $this->adminPost(route('admin.orders.billing.lexware-draft', $order->id))->assertSessionHasNoErrors();

        // While it is still a draft Lexware has no document to give, and the
        // admin is told to finalize it there rather than shown a raw API error.
        $this->adminPost(route('admin.orders.billing.lexware-finalize', $order->id))->assertSessionHasErrors('lexware');
        $this->assertSame(0, VehicleReportDocument::where('auftragsnummer', $order->auftragsnummer)->count());

        // Accounting finalizes it inside Lexware — there is no API call for it.
        $lexware->finalizeInLexware(array_key_first($lexware->invoices));

        $this->adminPost(route('admin.orders.billing.lexware-finalize', $order->id))->assertSessionHasNoErrors();

        // The voucher is checked first, and only a finalized one is downloaded.
        $this->assertSame(
            ['requireFinalizedInvoice', 'requireFinalizedInvoice', 'downloadInvoiceFile'],
            array_values(array_filter(
                $lexware->methods(),
                fn (string $m) => in_array($m, ['requireFinalizedInvoice', 'downloadInvoiceFile'], true),
            )),
        );

        // The PDF is filed with the order's other documents but stays invisible
        // to the company until somebody publishes it (§13).
        $document = VehicleReportDocument::where('auftragsnummer', $order->auftragsnummer)
            ->where('document_type', 'rechnung')
            ->firstOrFail();
        $this->assertFalse($document->published);
        Storage::disk('documents')->assertExists($document->path);

        $invoice = DB::table('lexware_invoices')->where('order_id', $order->id)->first();
        $this->assertSame($document->id, $invoice->document_id);
        $this->assertNotNull($invoice->voucher_number);
        $this->assertDatabaseHas('leasyback_order_audit_log', [
            'order_id' => $order->id,
            'action' => 'LEXWARE_INVOICE_FINALIZED',
        ]);

        // One invoice per order: fetching it twice is refused, not duplicated.
        $this->adminPost(route('admin.orders.billing.lexware-finalize', $order->id))->assertSessionHasErrors('lexware');
    }

    public function test_publishing_the_invoice_closes_the_billing_in_one_step(): void
    {
        $lexware = new FakeLexwareGateway;
        $this->app->instance(LexwareGateway::class, $lexware);

        $order = $this->inspectedOrder(['1000']);
        $this->accept($this->publishedOffer($order, ['800']));
        $order->forceFill(['order_status' => 'vehicle_returned'])->save();

        $this->adminPost(route('admin.orders.billing.lexware-draft', $order->id))->assertSessionHasNoErrors();
        $lexware->finalizeInLexware(array_key_first($lexware->invoices));
        $this->adminPost(route('admin.orders.billing.lexware-finalize', $order->id))->assertSessionHasNoErrors();

        $document = VehicleReportDocument::where('auftragsnummer', $order->auftragsnummer)
            ->where('document_type', 'rechnung')
            ->firstOrFail();

        $this->adminPatch(route('admin.orders.billing', $order->id), [
            'invoice_document_id' => $document->id,
            'mark_processed' => true,
            'publish_invoice_document' => true,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($document->fresh()->published);
        $this->assertDatabaseHas('b2b_order_billing', [
            'order_id' => $order->id,
            'invoice_document_id' => $document->id,
            'billing_status' => 'processed',
        ]);
    }

    public function test_finalizing_without_a_draft_is_refused(): void
    {
        $lexware = new FakeLexwareGateway;
        $this->app->instance(LexwareGateway::class, $lexware);

        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'vehicle_returned');

        $this->adminPost(route('admin.orders.billing.lexware-finalize', $order->id))->assertSessionHasErrors('lexware');
        $this->assertSame([], $lexware->methods());
    }

    public function test_a_failed_finalize_is_reported_and_leaves_no_half_written_invoice(): void
    {
        $lexware = new FakeLexwareGateway;
        $this->app->instance(LexwareGateway::class, $lexware);

        $order = $this->inspectedOrder(['1000']);
        $this->accept($this->publishedOffer($order, ['800']));
        $order->forceFill(['order_status' => 'vehicle_returned'])->save();

        $this->adminPost(route('admin.orders.billing.lexware-draft', $order->id))->assertSessionHasNoErrors();
        $lexware->finalizeInLexware(array_key_first($lexware->invoices));

        $lexware->failDownload = LexwareGatewayException::apiError('file not ready', 404);
        $this->adminPost(route('admin.orders.billing.lexware-finalize', $order->id))->assertSessionHasErrors('lexware');

        // Nothing filed, nothing claimed — and the admin can simply try again.
        $this->assertSame(0, VehicleReportDocument::where('auftragsnummer', $order->auftragsnummer)->count());
        $this->assertDatabaseHas('lexware_invoices', ['order_id' => $order->id, 'document_id' => null]);

        $this->adminPost(route('admin.orders.billing.lexware-finalize', $order->id))->assertSessionHasNoErrors();
        $this->assertSame(1, VehicleReportDocument::where('auftragsnummer', $order->auftragsnummer)->count());
    }

    public function test_the_lexware_draft_reports_a_disabled_integration(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'vehicle_returned');
        LeasybackOffer::factory()->selected()->create(['order_id' => $order->id, 'auftragsnummer' => $order->auftragsnummer]);

        $this->adminPost(route('admin.orders.billing.lexware-draft', $order->id))->assertSessionHasErrors('lexware');
    }

    public function test_creating_the_draft_touches_no_document_and_cannot_be_repeated(): void
    {
        $lexware = new FakeLexwareGateway;
        $this->app->instance(LexwareGateway::class, $lexware);

        $order = $this->inspectedOrder(['1000']);
        $this->accept($this->publishedOffer($order, ['800']));
        $order->forceFill(['order_status' => 'vehicle_returned'])->save();

        $this->adminPost(route('admin.orders.billing.lexware-draft', $order->id))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('lexware_invoices', [
            'order_id' => $order->id,
            'purpose' => 'b2b_billing',
            'document_id' => null,
        ]);
        $this->assertSame(0, VehicleReportDocument::where('auftragsnummer', $order->auftragsnummer)->count());

        // Already created — retrying is refused, not retried.
        $this->adminPost(route('admin.orders.billing.lexware-draft', $order->id))->assertSessionHasErrors('lexware');
    }

    public function test_the_service_fee_that_applies_is_the_one_in_effect_on_the_date(): void
    {
        $fees = app(B2bServiceFeeService::class);
        $fees->update($this->company, '350.00', now()->addMonth()->toDateString());

        $this->assertSame('295.00', $fees->amountOn($this->company->b2b_id, now()));
        $this->assertSame('350.00', $fees->amountOn($this->company->b2b_id, now()->addMonths(2)));

        $this->actingAs($this->admin)->from('/admin')
            ->patch(route('admin.customers.service-fee', $this->company->b2b_id), [
                'service_fee_amount' => '299.999',
                'service_fee_effective_from' => now()->toDateString(),
            ])->assertSessionHasErrors('service_fee_amount');

        $this->assertSame(now()->toDateString(), $this->makeCompany('Neu GmbH')->fresh()->service_fee_effective_from->toDateString());
    }

    // --------------------------------------------------------- documents

    public function test_documents_must_belong_to_an_order_of_the_vehicle_and_be_pdf_or_image(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'vehicle_collected');
        $other = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'vehicle_collected');

        $this->adminPost(route('admin.vehicles.reports.upload', $order->vehicle_id), [
            'auftragsnummer' => $other->auftragsnummer, 'document_type' => 'gutachten',
            'file' => UploadedFile::fake()->create('g.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('auftragsnummer');

        $this->adminPost(route('admin.vehicles.reports.upload', $order->vehicle_id), [
            'auftragsnummer' => $order->auftragsnummer, 'document_type' => 'gutachten',
            'file' => UploadedFile::fake()->create('g.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, VehicleReportDocument::count());
    }

    public function test_deleting_a_note_is_audited(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'confirmed');
        $this->adminPost(route('admin.orders.notes.store', $order->id), ['body' => 'Hinweis', 'visibility' => 'customer']);
        $noteId = DB::table('b2b_order_notes')->where('order_id', $order->id)->value('id');

        $this->actingAs($this->admin)->from(route('admin.orders.show', $order->id))
            ->delete(route('admin.orders.notes.destroy', [$order->id, $noteId]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leasyback_order_audit_log', ['order_id' => $order->id, 'action' => 'NOTE_DELETED']);
    }

    // ------------------------------------------------------ cancellation

    public function test_a_company_user_can_cancel_before_collection_but_not_after(): void
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'confirmed');
        $readOnly = $this->makeMember($this->company, ['vehicles.view']);

        $this->actingAs($readOnly)->postJson(route('orders.cancel', $order->id))->assertNotFound();

        $this->actingAs($this->owner)->postJson(route('orders.cancel', $order->id))
            ->assertOk()
            ->assertJsonPath('order_status', 'cancelled')
            ->assertJsonPath('fee', null);

        $collected = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'vehicle_collected');
        $this->actingAs($this->owner)->postJson(route('orders.cancel', $collected->id))->assertNotFound();
    }

    public function test_offer_reminders_are_b2b_only(): void
    {
        $b2cVehicle = Vehicle::factory()->create(['vehicle_belongs' => 'B2C', 'b2b_id' => null]);
        $b2cOrder = LeasybackOrder::factory()->create(['vehicle_id' => $b2cVehicle->vehicle_id, 'order_status' => 'inspected']);
        $offer = LeasybackOffer::factory()->published()->create(['order_id' => $b2cOrder->id, 'auftragsnummer' => $b2cOrder->auftragsnummer]);
        B2bOfferPresentation::create([
            'offer_id' => $offer->offer_id, 'order_id' => $b2cOrder->id, 'lines' => [],
            'appraisal_total_net' => 0, 'repair_total_net' => 100, 'saving_net' => 0,
            'presented_at' => now()->subDays(2),
        ]);

        $this->assertSame([], app(RepairOfferService::class)->offersDueForReminder());
    }

    // ----------------------------------------------------------- helpers

    /**
     * @param  list<string>  $amounts
     */
    private function inspectedOrder(array $amounts = ['1000']): LeasybackOrder
    {
        $order = $this->makeB2bOrder($this->makeB2bVehicle($this->company), 'inspected');
        $this->addPositions($order, $amounts);

        return $order;
    }

    /**
     * @param  list<string>  $amounts
     */
    private function addPositions(LeasybackOrder $order, array $amounts): void
    {
        foreach ($amounts as $index => $amount) {
            AppraisalPosition::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'sort_order' => $index,
                'component' => 'Stoßfänger '.($index + 1),
                'original_amount_net' => $amount,
            ]);
        }
    }

    /**
     * @param  list<string|null>  $amounts  One per position, null leaves it unpriced.
     */
    private function submittedQuotation(LeasybackOrder $order, array $amounts): WorkshopQuotation
    {
        $invite = app(WorkshopQuotationService::class)->invite($order, $this->admin, ['workshop_label' => 'Werkstatt '.uniqid()]);
        $positions = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->get();

        app(WorkshopQuotationService::class)->submit($invite['quotation'], [
            'company_name' => 'Werkstatt GmbH',
            'contact_person' => 'Kontakt',
            'contact_email' => 'werkstatt@example.test',
            'items' => $positions->values()->map(fn (AppraisalPosition $position, int $index) => [
                'appraisal_position_id' => $position->id,
                'amount_net' => $amounts[$index] ?? null,
            ])->filter(fn (array $item) => $item['amount_net'] !== null)->values()->all(),
        ]);

        return $invite['quotation']->fresh();
    }

    /**
     * @param  list<string|null>  $amounts
     */
    private function publishedOffer(LeasybackOrder $order, array $amounts): LeasybackOffer
    {
        $quotation = $this->submittedQuotation($order, $amounts);

        $this->adminPost(route('admin.orders.b2b-offer.store', $order->id), ['workshop_quotation_id' => $quotation->id])
            ->assertSessionHasNoErrors();
        $offer = LeasybackOffer::where('order_id', $order->id)->orderByDesc('offer_sequence')->firstOrFail();

        $this->adminPatch(route('admin.orders.offers.publish', $offer->offer_id))->assertSessionHasNoErrors();

        return $offer->fresh();
    }

    private function accept(LeasybackOffer $offer): void
    {
        $this->actingAs($this->owner)->from('/dashboard')
            ->post(route('offers.select', $offer->offer_id))
            ->assertSessionHasNoErrors();
    }

    private function nextTaskKey(LeasybackOrder $order): ?string
    {
        return app(AdminQueryService::class)->orderDetail($order->id)['tasks']['next']['key'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function adminPost(string $url, array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->from('/admin/dashboard')->post($url, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function adminPatch(string $url, array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->from('/admin/dashboard')->patch($url, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function adminPut(string $url, array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->from('/admin/dashboard')->put($url, $data);
    }
}
