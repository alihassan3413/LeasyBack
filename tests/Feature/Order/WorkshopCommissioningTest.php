<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Mail\Workshop\WorkshopCommissionedMail;
use App\Models\B2B;
use App\Models\LeasybackOffer;
use App\Models\OrderAuditLog;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderLogistics;
use App\Modules\UserProfile\Order\Models\OrderStatusUpdate;
use App\Modules\UserProfile\Order\Services\WorkshopCommissionService;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Commissioning the workshop that won the order, and the repair appointment
 * that starts the work.
 *
 * The gap this closes: the system knew exactly which workshop had quoted the
 * price a customer accepted, and did nothing with it. An admin changed a status
 * by hand and picked up the phone. Now the workshop is resolved from the
 * accepted offer, instructed once, and emailed a repair order — and the
 * acceptance itself still moves nothing, because choosing an offer and being
 * told to start work are different events.
 */
class WorkshopCommissioningTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    // ------------------------------------------------------------ the happy path

    public function test_admin_commissions_the_workshop_that_won_the_offer(): void
    {
        $order = $this->acceptedB2cOrder('Karosserie Meier GmbH');

        $this->commission($order)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('workshop_commissioned', $order->fresh()->order_status);

        $audit = OrderAuditLog::where('action', WorkshopCommissionService::AUDIT_ACTION)->sole();
        $this->assertSame('Karosserie Meier GmbH', $audit->new_values['workshop']['company_name']);
        $this->assertSame(LeasybackOffer::sole()->offer_id, $audit->new_values['offer_id']);
        $this->assertNotNull($audit->new_values['workshop_quotation_id']);

        Mail::assertSent(WorkshopCommissionedMail::class, 1);
    }

    public function test_customer_acceptance_alone_commissions_nothing(): void
    {
        $order = $this->acceptedB2cOrder();

        // Acceptance already happened in the fixture. Nothing may follow from it.
        $this->assertSame('inspected', $order->fresh()->order_status);
        $this->assertSame(0, OrderAuditLog::where('action', WorkshopCommissionService::AUDIT_ACTION)->count());
        Mail::assertNothingSent();
    }

    public function test_the_winning_workshop_is_resolved_and_not_chosen(): void
    {
        $order = $this->b2cOrderWithPositions();
        $loser = $this->offerFrom($order, 'Verlierer GmbH', '900.00');
        $winner = $this->offerFrom($order, 'Gewinner GmbH', '600.00');

        $this->accept($order, $winner);
        $this->commission($order)->assertRedirect();

        $target = app(WorkshopCommissionService::class)->resolve($order->fresh());
        $this->assertSame('Gewinner GmbH', $target['workshop']['company_name']);
        $this->assertSame($winner->offer_id, $target['offer']->offer_id);

        // The losing workshop is closed out and never hears from us.
        $this->assertSame('closed', $loser->fresh()->offer_status);
        Mail::assertSent(WorkshopCommissionedMail::class, fn (WorkshopCommissionedMail $mail) => $mail->workshopName === 'Gewinner GmbH');
        Mail::assertNotSent(WorkshopCommissionedMail::class, fn (WorkshopCommissionedMail $mail) => $mail->workshopName === 'Verlierer GmbH');
    }

    // ------------------------------------------------------------------ refusals

    public function test_commissioning_is_refused_without_an_accepted_offer(): void
    {
        $order = $this->b2cOrderWithPositions();
        $this->offerFrom($order, 'Noch nicht gewählt GmbH', '600.00');

        $this->commission($order)->assertSessionHasErrors('commission');

        $this->assertSame('inspected', $order->fresh()->order_status);
        Mail::assertNothingSent();
    }

    public function test_a_manual_offer_gives_no_workshop_to_commission(): void
    {
        $order = $this->b2cOrderWithPositions();
        $this->accept($order, $this->manualOffer($order));

        $this->commission($order)->assertSessionHasErrors('commission');

        $state = app(WorkshopCommissionService::class)->state($order->fresh());
        $this->assertFalse($state['can_commission']);
        $this->assertSame(WorkshopCommissionService::BLOCKED_MANUAL, $state['blocked_reason']);
        $this->assertNull($state['workshop']);

        $this->assertSame('inspected', $order->fresh()->order_status);
        Mail::assertNothingSent();
    }

    public function test_a_workshop_without_an_email_address_is_reported_rather_than_guessed(): void
    {
        $order = $this->acceptedB2cOrder();
        B2bOfferPresentation::query()->update([
            'workshop' => json_encode(['label' => 'Ohne Adresse', 'company_name' => 'Ohne Adresse GmbH']),
        ]);

        $this->commission($order)->assertSessionHasErrors('commission');

        $this->assertSame(
            WorkshopCommissionService::BLOCKED_NO_CONTACT,
            app(WorkshopCommissionService::class)->state($order->fresh())['blocked_reason'],
        );
        Mail::assertNothingSent();
    }

    public function test_an_order_in_the_wrong_state_cannot_be_commissioned(): void
    {
        $order = $this->acceptedB2cOrder();
        $order->forceFill(['order_status' => 'reinspection'])->save();

        $this->commission($order)->assertSessionHasErrors('commission');

        $this->assertSame('reinspection', $order->fresh()->order_status);
        Mail::assertNothingSent();
    }

    public function test_a_customer_cannot_commission(): void
    {
        $order = $this->acceptedB2cOrder();

        $this->actingAs(User::factory()->create(['user_type' => UserType::Privatkunde]))
            ->post(route('admin.orders.commission-workshop', $order->id))
            ->assertForbidden();

        $this->assertSame('inspected', $order->fresh()->order_status);
        Mail::assertNothingSent();
    }

    /**
     * The raw status endpoint would move the order without resolving a
     * workshop, recording who was instructed or telling them — producing an
     * order that claims a workshop was commissioned when none was.
     */
    public function test_the_generic_status_route_cannot_stand_in_for_commissioning(): void
    {
        $order = $this->acceptedB2cOrder();

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.status', $order->id), ['status' => 'workshop_commissioned'])
            ->assertSessionHasErrors('status');

        $this->assertSame('inspected', $order->fresh()->order_status);
        Mail::assertNothingSent();
    }

    /**
     * …but it stays available where there is genuinely nothing to resolve, which
     * is what keeps the manual fallback finishable.
     */
    public function test_the_generic_status_route_still_works_for_a_manual_offer(): void
    {
        $order = $this->b2cOrderWithPositions();
        $this->accept($order, $this->manualOffer($order));

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.status', $order->id), ['status' => 'workshop_commissioned'])
            ->assertSessionHasNoErrors();

        $this->assertSame('workshop_commissioned', $order->fresh()->order_status);
        Mail::assertNothingSent();
    }

    public function test_the_inspection_provider_cannot_commission_a_workshop(): void
    {
        config(['services.tuvsud.api_key' => 'k']);
        $order = $this->acceptedB2cOrder();

        $this->withHeader('X-API-Key', 'k')
            ->getJson("/order/tuvsud/status?auftragsnummer={$order->auftragsnummer}&status=workshop_commissioned")
            ->assertStatus(403);

        $this->assertSame('inspected', $order->fresh()->order_status);
        Mail::assertNothingSent();
    }

    // --------------------------------------------------------------- idempotency

    public function test_a_repeated_commission_request_changes_nothing_and_sends_nothing(): void
    {
        $order = $this->acceptedB2cOrder('Einmal GmbH');

        $this->commission($order)->assertRedirect();
        $commissionedAt = OrderAuditLog::where('action', WorkshopCommissionService::AUDIT_ACTION)->sole()->changed_at;

        for ($retry = 0; $retry < 3; $retry++) {
            $this->commission($order->fresh())->assertRedirect()->assertSessionHasNoErrors();
        }

        $audit = OrderAuditLog::where('action', WorkshopCommissionService::AUDIT_ACTION)->sole();
        $this->assertEquals($commissionedAt, $audit->changed_at, 'a replay must not restamp the commissioning');

        Mail::assertSent(WorkshopCommissionedMail::class, 1);
        $this->assertSame(1, OrderStatusUpdate::where('auftragsnummer', $order->auftragsnummer)
            ->where('new_status', 'workshop_commissioned')->count());
    }

    public function test_a_replay_arriving_after_the_order_moved_on_is_still_a_replay(): void
    {
        $order = $this->acceptedB2cOrder();
        $this->commission($order);
        $this->saveAppointment($order->fresh());

        $this->assertSame('workshop', $order->fresh()->order_status);

        // A retry landing now must not read "not commissioned" off the status.
        $this->commission($order->fresh())->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('workshop', $order->fresh()->order_status);
        $this->assertSame(1, OrderAuditLog::where('action', WorkshopCommissionService::AUDIT_ACTION)->count());
        Mail::assertSent(WorkshopCommissionedMail::class, 1);
    }

    // -------------------------------------------------------------- the email

    public function test_the_email_goes_to_the_winning_workshops_own_address(): void
    {
        $order = $this->acceptedB2cOrder('Ziel GmbH');

        $this->commission($order);

        $mail = $this->sentCommission();

        $this->assertTrue($mail->hasTo('kontakt@ziel-gmbh.test'));
        $this->assertSame($order->auftragsnummer, $mail->orderReference);
        $this->assertNotNull(B2bOfferPresentation::sole()->workshop_notified_at);
    }

    /**
     * A workshop is a supplier. It gets the car and the work, and nothing that
     * belongs to the customer or to a competitor.
     */
    public function test_the_email_carries_no_customer_or_competitor_data(): void
    {
        $customer = User::factory()->create([
            'user_type' => UserType::Privatkunde,
            'name' => 'Heike Sonderbar',
            'email' => 'heike.sonderbar@example.test',
        ]);

        $order = $this->b2cOrderWithPositions($customer);
        $this->offerFrom($order, 'Konkurrenz GmbH', '4321.00');
        $this->accept($order, $this->offerFrom($order, 'Gewinner GmbH', '600.00'));

        $this->commission($order);

        $mail = $this->sentCommission();
        $rendered = $mail->render();

        $this->assertSame(
            ['component', 'repair_method', 'amount_net', 'not_repairable'],
            array_keys($mail->positions[0]),
            'a position carries the work and its price, and nothing else',
        );

        foreach (['Heike Sonderbar', 'heike.sonderbar@example.test', '4.321,00', 'Konkurrenz GmbH'] as $secret) {
            $this->assertStringNotContainsString($secret, $rendered, "workshop email leaked [{$secret}]");
        }

        // The appraisal amount the repair was measured against is the
        // customer's business, not the repairer's.
        $this->assertStringNotContainsString('1.000,00', $rendered);
        $this->assertStringContainsString('600,00', $rendered);
        $this->assertStringContainsString('Bauteil 0', $rendered);
    }

    public function test_a_failed_send_leaves_the_commissioning_standing_and_can_be_resent(): void
    {
        $order = $this->acceptedB2cOrder();

        // Every send fails, not just this one — the customer's own status mail
        // rides on the same transition. That is the point: neither of them can
        // undo a commissioning that is already committed.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->commission($order)->assertRedirect()->assertSessionHasNoErrors();

        // Committed, audited, and honest about not having reached anyone.
        $this->assertSame('workshop_commissioned', $order->fresh()->order_status);
        $this->assertSame(1, OrderAuditLog::where('action', WorkshopCommissionService::AUDIT_ACTION)->count());
        $this->assertNull(B2bOfferPresentation::sole()->workshop_notified_at);
        $this->assertNull(app(WorkshopCommissionService::class)->state($order->fresh())['notified_at']);
    }

    public function test_an_admin_can_resend_the_commissioning_email(): void
    {
        $order = $this->acceptedB2cOrder();
        $this->commission($order);

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.commission-workshop.resend', $order->id))
            ->assertRedirect();

        Mail::assertSent(WorkshopCommissionedMail::class, 2);
        $this->assertSame(1, OrderAuditLog::where('action', WorkshopCommissionService::AUDIT_ACTION)->count());
        $this->assertSame(1, OrderAuditLog::where('action', WorkshopCommissionService::AUDIT_ACTION_RENOTIFIED)->count());
    }

    public function test_resending_before_commissioning_is_refused(): void
    {
        $order = $this->acceptedB2cOrder();

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.commission-workshop.resend', $order->id))
            ->assertSessionHasErrors('commission');

        Mail::assertNothingSent();
    }

    // ------------------------------------------------------- repair appointment

    public function test_the_repair_appointment_starts_the_repair_phase_for_b2c(): void
    {
        $order = $this->acceptedB2cOrder();
        $this->commission($order);

        $this->saveAppointment($order->fresh(), '2026-09-01', 5)->assertRedirect()->assertSessionHasNoErrors();

        $logistics = OrderLogistics::where('auftragsnummer', $order->auftragsnummer)->sole();
        $this->assertSame('2026-09-01', $logistics->confirmed_repair_start_date->toDateString());
        $this->assertSame(5, $logistics->estimated_processing_days);
        $this->assertSame('workshop', $order->fresh()->order_status);
        $this->assertSame(1, OrderAuditLog::where('action', 'REPAIR_APPOINTMENT_SET')->count());
    }

    public function test_resubmitting_the_same_appointment_is_safe(): void
    {
        $order = $this->acceptedB2cOrder();
        $this->commission($order);
        $this->saveAppointment($order->fresh(), '2026-09-01', 5);

        $this->saveAppointment($order->fresh(), '2026-09-01', 5)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('workshop', $order->fresh()->order_status);
        // One transition, and one audit row — the unchanged resave writes nothing.
        $this->assertSame(1, OrderStatusUpdate::where('auftragsnummer', $order->auftragsnummer)
            ->where('new_status', 'workshop')->count());
        $this->assertSame(1, OrderAuditLog::where('action', 'REPAIR_APPOINTMENT_SET')->count());
    }

    public function test_rescheduling_after_repair_started_updates_the_date_without_moving_the_order(): void
    {
        $order = $this->acceptedB2cOrder();
        $this->commission($order);
        $this->saveAppointment($order->fresh(), '2026-09-01');

        $this->saveAppointment($order->fresh(), '2026-09-08')->assertRedirect();

        $this->assertSame('workshop', $order->fresh()->order_status);
        $this->assertSame(
            '2026-09-08',
            OrderLogistics::where('auftragsnummer', $order->auftragsnummer)->sole()->confirmed_repair_start_date->toDateString(),
        );
        $this->assertSame(2, OrderAuditLog::where('action', 'REPAIR_APPOINTMENT_SET')->count());
    }

    public function test_a_malformed_appointment_date_is_refused(): void
    {
        $order = $this->acceptedB2cOrder();
        $this->commission($order);

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.repair-appointment', $order->id), ['confirmed_repair_start_date' => '01.09.2026'])
            ->assertSessionHasErrors('confirmed_repair_start_date');

        $this->assertSame('workshop_commissioned', $order->fresh()->order_status);
    }

    // ------------------------------------------------------------------ payloads

    public function test_the_admin_payload_carries_the_winning_workshop_and_the_appointment(): void
    {
        $order = $this->acceptedB2cOrder('Sichtbar GmbH');
        $this->commission($order);
        $this->saveAppointment($order->fresh(), '2026-09-01', 4);

        $payload = $this->adminOrder($order);

        $this->assertTrue($payload['workshop_commission']['is_commissioned']);
        $this->assertFalse($payload['workshop_commission']['can_commission']);
        $this->assertSame('Sichtbar GmbH', $payload['workshop_commission']['workshop']['company_name']);
        $this->assertNotNull($payload['workshop_commission']['notified_at']);
        $this->assertSame('2026-09-01', $payload['collection']['confirmed_repair_start_date']);
        $this->assertSame(4, $payload['collection']['estimated_processing_days']);
    }

    public function test_the_admin_payload_offers_the_action_before_it_is_taken(): void
    {
        $payload = $this->adminOrder($this->acceptedB2cOrder());

        $this->assertTrue($payload['workshop_commission']['can_commission']);
        $this->assertFalse($payload['workshop_commission']['is_commissioned']);
        $this->assertNull($payload['workshop_commission']['blocked_reason']);
    }

    /**
     * The journey wording itself lives in customerOrderFlow.ts and there is no
     * JS test runner here, so what is pinned is the input it reads: the payload
     * has to carry the commissioned status and the agreed dates, or the
     * "Werkstatt beauftragt" stage has nothing to render from. The B2C customer
     * payload carried no repair dates at all before this task.
     */
    public function test_the_customer_payload_carries_the_commissioning_and_the_appointment(): void
    {
        $order = $this->acceptedB2cOrder();
        $this->commission($order);

        $payloadOrder = $this->customerOrder($order);
        $this->assertSame('workshop_commissioned', $payloadOrder['order_status']);

        $this->saveAppointment($order->fresh(), '2026-09-01', 4);

        $payloadOrder = $this->customerOrder($order);
        $this->assertSame('workshop', $payloadOrder['order_status']);
        $this->assertSame('2026-09-01', $payloadOrder['collection']['confirmed_repair_start_date']);
        $this->assertSame(4, $payloadOrder['collection']['estimated_processing_days']);
    }

    /**
     * The dates are business facts the customer is entitled to; the pickup
     * address and internal note on the same row are not theirs and never were.
     */
    public function test_the_customer_collection_block_carries_no_internal_note(): void
    {
        $order = $this->acceptedB2cOrder();
        $this->commission($order);
        $this->saveAppointment($order->fresh(), '2026-09-01');

        $this->assertArrayNotHasKey('internal_note', $this->customerOrder($order)['collection']);
    }

    // ---------------------------------------------------------------------- B2B

    public function test_the_b2b_path_is_unchanged(): void
    {
        $order = $this->acceptedB2bOrder('Flottenpartner GmbH');

        $this->commission($order)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('workshop_commissioned', $order->fresh()->order_status);

        $this->saveAppointment($order->fresh(), '2026-09-01', 6);
        $this->assertSame('workshop', $order->fresh()->order_status);

        Mail::assertSent(WorkshopCommissionedMail::class, 1);
    }

    public function test_a_b2b_order_still_cannot_hold_a_b2c_only_status(): void
    {
        $order = $this->acceptedB2bOrder();
        $this->commission($order);
        $this->saveAppointment($order->fresh(), '2026-09-01');

        $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.status', $order->id), ['status' => 'delivered'])
            ->assertSessionHasErrors('status');
    }

    // ----------------------------------------------------------------- invariant

    public function test_the_single_selected_offer_invariant_survives_commissioning(): void
    {
        $order = $this->b2cOrderWithPositions();
        $first = $this->offerFrom($order, 'A GmbH', '900.00');
        $second = $this->offerFrom($order, 'B GmbH', '600.00');

        $this->accept($order, $second);
        $this->commission($order);

        $this->actingAs($this->ownerOf($order))
            ->from('/dashboard')
            ->post(route('offers.select', $first->offer_id))
            ->assertSessionHasErrors('offer');

        $this->assertSame(1, LeasybackOffer::where('offer_status', 'selected')->count());
        $this->assertSame($second->offer_id, LeasybackOffer::where('offer_status', 'selected')->sole()->offer_id);
        Mail::assertSent(WorkshopCommissionedMail::class, 1);
    }

    // ------------------------------------------------------------------ helpers

    private function sentCommission(): WorkshopCommissionedMail
    {
        $mail = Mail::sent(WorkshopCommissionedMail::class)->first();

        $this->assertInstanceOf(WorkshopCommissionedMail::class, $mail);

        return $mail;
    }

    private function commission(LeasybackOrder $order): TestResponse
    {
        return $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.commission-workshop', $order->id));
    }

    private function saveAppointment(LeasybackOrder $order, string $date = '2026-09-01', ?int $days = null): TestResponse
    {
        return $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.repair-appointment', $order->id), array_filter([
                'confirmed_repair_start_date' => $date,
                'estimated_processing_days' => $days,
            ], fn ($value) => $value !== null));
    }

    private function accept(LeasybackOrder $order, LeasybackOffer $offer): void
    {
        $this->actingAs($this->ownerOf($order))
            ->from('/dashboard')
            ->post(route('offers.select', $offer->offer_id))
            ->assertSessionHasNoErrors();
    }

    /**
     * A published, quotation-backed offer from one named workshop.
     */
    private function offerFrom(LeasybackOrder $order, string $company, string $amountNet): LeasybackOffer
    {
        $admin = $this->makeAdmin();
        $quotation = app(WorkshopQuotationService::class)
            ->invite($order, $admin, ['workshop_label' => $company])['quotation'];

        app(WorkshopQuotationService::class)->submit($quotation, [
            'company_name' => $company,
            'contact_person' => 'Kontakt Person',
            'contact_email' => $this->contactFor($company),
            'contact_phone' => '+49 30 123456',
            'earliest_repair_start' => '2026-09-15',
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

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.b2b-offer.store', $order->id), ['workshop_quotation_id' => $quotation->id])
            ->assertSessionHasNoErrors();

        $offer = LeasybackOffer::where('order_id', $order->id)->orderByDesc('offer_sequence')->first();

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.offers.publish', $offer->offer_id))
            ->assertSessionHasNoErrors();

        return $offer->fresh();
    }

    private function manualOffer(LeasybackOrder $order): LeasybackOffer
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.offers.store', $order->id), [
                'repair_cost_net' => '500.00', 'repair_cost_gross' => '595.00',
                'depreciation_value_net' => '0', 'depreciation_value_gross' => '0',
                'workshop_repair_quote_net' => '0', 'workshop_repair_quote_gross' => '0',
                'missing_parts_cost_net' => '0', 'missing_parts_cost_gross' => '0',
            ])->assertSessionHasNoErrors();

        $offer = LeasybackOffer::where('order_id', $order->id)->orderByDesc('offer_sequence')->first();

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order->id))
            ->patch(route('admin.orders.offers.publish', $offer->offer_id))
            ->assertSessionHasNoErrors();

        return $offer->fresh();
    }

    private function contactFor(string $company): string
    {
        return str($company)->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->prepend('kontakt@')->append('.test')->value();
    }

    private function acceptedB2cOrder(string $company = 'Werkstatt GmbH'): LeasybackOrder
    {
        $order = $this->b2cOrderWithPositions();
        $this->accept($order, $this->offerFrom($order, $company, '600.00'));

        return $order->fresh();
    }

    private function acceptedB2bOrder(string $company = 'Werkstatt GmbH'): LeasybackOrder
    {
        $order = $this->b2bOrderWithPositions();
        $this->accept($order, $this->offerFrom($order, $company, '600.00'));

        return $order->fresh();
    }

    private function b2cOrderWithPositions(?User $owner = null): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => $owner?->id ?? User::factory()->create(['user_type' => UserType::Privatkunde])->id,
            'make' => 'Volkswagen',
            'model' => 'Passat',
        ]);

        return $this->withPositions(LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'inspected',
        ]));
    }

    private function b2bOrderWithPositions(): LeasybackOrder
    {
        $company = $this->makeCompany(fake()->unique()->company());
        $vehicle = $this->makeB2bVehicle($company);
        $this->companiesByVehicle[$vehicle->vehicle_id] = $company;

        return $this->withPositions($this->makeB2bOrder($vehicle, 'inspected'));
    }

    /** @var array<string, B2B> */
    private array $companiesByVehicle = [];

    private function withPositions(LeasybackOrder $order): LeasybackOrder
    {
        AppraisalPosition::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'sort_order' => 0,
            'component' => 'Bauteil 0',
            'damage_description' => 'Beschädigung',
            'original_amount_net' => '1000.00',
            'repair_method' => 'Instandsetzung',
            'source' => AppraisalPosition::SOURCE_MANUAL,
        ]);

        return $order;
    }

    private function ownerOf(LeasybackOrder $order): User
    {
        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->sole();

        return $vehicle->b2c_user_id !== null
            ? User::findOrFail($vehicle->b2c_user_id)
            : $this->makeOwner($this->companiesByVehicle[$vehicle->vehicle_id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminOrder(LeasybackOrder $order): array
    {
        return json_decode((string) json_encode(
            $this->actingAs($this->makeAdmin())
                ->get(route('admin.orders.show', $order->id))
                ->viewData('page')['props']['order'],
        ), true);
    }

    /**
     * This order as its own customer sees it on the dashboard.
     *
     * @return array<string, mixed>
     */
    private function customerOrder(LeasybackOrder $order): array
    {
        $page = $this->actingAs($this->ownerOf($order))->get('/dashboard')->viewData('page');

        foreach ($page['props']['vehicles'] ?? [] as $vehicle) {
            foreach ($vehicle['orders'] ?? [] as $payloadOrder) {
                if (($payloadOrder['id'] ?? null) === $order->id) {
                    return json_decode((string) json_encode($payloadOrder), true);
                }
            }
        }

        $this->fail('order not found in the customer payload');
    }
}
