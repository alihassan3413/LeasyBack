<?php

namespace Tests\Feature\Order;

use App\Enums\UserType;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Models\WorkshopQuotationItem;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * Workshop quotations in both channels.
 *
 * The quotation domain was already order-scoped and the public token route was
 * already channel-blind — a workshop is never told who owns the car and has no
 * way to ask. Only the Admin half was guarded on the vehicle being B2B, so a
 * B2C order could hold priced repair positions and still not be shown to a
 * single workshop.
 *
 * Along with the B2C capability this pins the security properties the shared
 * service has to keep: one token reaches one quotation, sibling prices are
 * invisible, and a submitted quotation is final.
 */
class WorkshopQuotationChannelTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The public routes are throttled (30/min, 10/min) and the array cache
        // driver keeps its counters for the whole process, so several tests
        // exercising the same route would start 429ing each other. Throttling
        // is unchanged by this work; what is under test is what the token
        // reaches, not how often it may be tried.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ------------------------------------------------------------- invitation

    public function test_admin_can_invite_a_workshop_for_a_b2c_order(): void
    {
        $order = $this->b2cOrderWithPositions();

        $response = $this->invite($order, ['workshop_label' => 'Karosserie Meier', 'invited_email' => 'meier@example.test']);

        $response->assertRedirect()->assertSessionHas('workshop_link');

        $quotation = WorkshopQuotation::where('order_id', $order->id)->sole();
        $this->assertSame('Karosserie Meier', $quotation->workshop_label);
        $this->assertSame('meier@example.test', $quotation->invited_email);
        $this->assertSame('invited', $quotation->status());
        $this->assertSame($order->auftragsnummer, $quotation->auftragsnummer);
    }

    public function test_the_plaintext_token_is_returned_once_and_only_its_hash_is_stored(): void
    {
        $order = $this->b2cOrderWithPositions();

        $token = $this->tokenFrom($this->invite($order));

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $token);

        $stored = DB::table((new WorkshopQuotation)->getTable())->value('token_hash');
        $this->assertSame(hash('sha256', $token), $stored);
        $this->assertStringNotContainsString($token, (string) json_encode(DB::table((new WorkshopQuotation)->getTable())->get()));
    }

    public function test_an_invitation_expires_on_the_requested_horizon(): void
    {
        $order = $this->b2cOrderWithPositions();

        $this->invite($order, ['ttl_days' => 3]);

        $quotation = WorkshopQuotation::where('order_id', $order->id)->sole();
        $this->assertTrue($quotation->expires_at->betweenIncluded(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
    }

    public function test_a_b2c_invitation_can_be_revoked(): void
    {
        $order = $this->b2cOrderWithPositions();
        $token = $this->tokenFrom($this->invite($order));
        $quotation = WorkshopQuotation::where('order_id', $order->id)->sole();

        $this->actingAs($this->makeAdmin())
            ->delete(route('admin.orders.workshop-quotations.revoke', $quotation->id))
            ->assertRedirect();

        $this->assertSame('revoked', $quotation->fresh()->status());
        $this->get(route('workshop.quotations.show', $token))->assertNotFound();
    }

    public function test_a_customer_cannot_invite_a_workshop(): void
    {
        $order = $this->b2cOrderWithPositions();

        $this->actingAs(User::factory()->create(['user_type' => UserType::Privatkunde]))
            ->post(route('admin.orders.workshop-quotations.store', $order->id), ['workshop_label' => 'Fremd'])
            ->assertForbidden();

        $this->assertSame(0, WorkshopQuotation::count());
    }

    // ------------------------------------------------------- multiple workshops

    public function test_one_b2c_order_supports_several_independent_invitations(): void
    {
        $order = $this->b2cOrderWithPositions();

        $tokens = collect(['Werkstatt A', 'Werkstatt B', 'Werkstatt C'])
            ->mapWithKeys(fn (string $label) => [$label => $this->tokenFrom($this->invite($order, ['workshop_label' => $label]))]);

        $this->assertCount(3, $tokens->unique());
        $this->assertSame(3, WorkshopQuotation::where('order_id', $order->id)->count());

        // Each token opens its own invitation and nobody else's.
        foreach ($tokens as $label => $token) {
            $this->assertSame($label, $this->publicPayload($token)['workshop_label']);
        }
    }

    public function test_a_workshop_never_sees_a_sibling_workshops_prices(): void
    {
        $order = $this->b2cOrderWithPositions();
        $first = $this->tokenFrom($this->invite($order, ['workshop_label' => 'Werkstatt A']));
        $second = $this->tokenFrom($this->invite($order, ['workshop_label' => 'Werkstatt B']));

        $this->submitVia($first, ['amount_net' => '777.77'])->assertRedirect();

        $payload = $this->publicPayload($second);
        $encoded = (string) json_encode($payload);

        $this->assertStringNotContainsString('777.77', $encoded);
        $this->assertStringNotContainsString('Werkstatt A', $encoded);
        $this->assertArrayNotHasKey('quotations', $payload);
        // …and its own form is still open, unaffected by the sibling's send.
        $this->assertSame('Werkstatt B', $payload['workshop_label']);
    }

    public function test_each_workshop_prices_the_same_shared_positions(): void
    {
        $order = $this->b2cOrderWithPositions(3);
        $first = $this->tokenFrom($this->invite($order));
        $second = $this->tokenFrom($this->invite($order));

        $this->assertSame(
            array_column($this->publicPayload($first)['positions'], 'id'),
            array_column($this->publicPayload($second)['positions'], 'id'),
        );
    }

    // ------------------------------------------------------------ token access

    public function test_a_b2c_token_opens_the_public_quotation_form(): void
    {
        $order = $this->b2cOrderWithPositions(2);
        $token = $this->tokenFrom($this->invite($order, ['workshop_label' => 'Lack & Karosserie']));

        $payload = $this->publicPayload($token);

        $this->assertSame('Lack & Karosserie', $payload['workshop_label']);
        $this->assertCount(2, $payload['positions']);
        $this->assertSame($order->vehicle->license_plate, $payload['vehicle']['license_plate']);
    }

    /**
     * The workshop needs the car and the damage. It gets nothing else — no
     * customer, no order status, no internal amounts beyond the requested one.
     */
    public function test_the_token_reveals_nothing_about_the_customer_or_the_order(): void
    {
        $customer = User::factory()->create([
            'user_type' => UserType::Privatkunde,
            'name' => 'Heike Sonderbar',
            'email' => 'heike.sonderbar@example.test',
        ]);

        $order = $this->b2cOrderWithPositions(1, $customer);
        $token = $this->tokenFrom($this->invite($order));

        $encoded = (string) json_encode($this->publicPayload($token));

        foreach (['Heike Sonderbar', 'heike.sonderbar@example.test', $order->auftragsnummer, $order->id, 'inspected'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $encoded, "public payload leaked [{$secret}]");
        }
    }

    public function test_appraisal_amounts_are_withheld_when_the_invitation_says_so(): void
    {
        $order = $this->b2cOrderWithPositions();

        $shown = $this->publicPayload($this->tokenFrom($this->invite($order, ['show_appraisal_amounts' => true])));
        $hidden = $this->publicPayload($this->tokenFrom($this->invite($order, ['show_appraisal_amounts' => false])));

        $this->assertNotNull($shown['positions'][0]['requested_amount_net']);
        $this->assertNull($hidden['positions'][0]['requested_amount_net']);
    }

    public function test_an_expired_token_is_refused(): void
    {
        $order = $this->b2cOrderWithPositions();
        $token = $this->tokenFrom($this->invite($order));

        WorkshopQuotation::where('order_id', $order->id)->update(['expires_at' => now()->subMinute()]);

        $this->get(route('workshop.quotations.show', $token))->assertNotFound();
        $this->submitVia($token)->assertNotFound();
        $this->assertSame(0, WorkshopQuotationItem::count());
    }

    public function test_an_unknown_token_is_indistinguishable_from_a_revoked_or_expired_one(): void
    {
        $order = $this->b2cOrderWithPositions();
        $live = $this->tokenFrom($this->invite($order));

        $unknown = Str::random(64);
        WorkshopQuotation::where('order_id', $order->id)->update(['revoked_at' => now()]);

        // Same status, same body: nothing tells a caller which token exists.
        $revoked = $this->get(route('workshop.quotations.show', $live));
        $missing = $this->get(route('workshop.quotations.show', $unknown));

        $revoked->assertNotFound();
        $missing->assertNotFound();
        $this->assertSame($revoked->getStatusCode(), $missing->getStatusCode());
    }

    public function test_a_malformed_token_never_reaches_the_controller(): void
    {
        $this->get('/werkstatt/angebot/short')->assertNotFound();
        $this->get('/werkstatt/angebot/'.str_repeat('!', 64))->assertNotFound();
    }

    // -------------------------------------------------------------- submission

    public function test_a_workshop_submits_a_price_for_every_position(): void
    {
        $order = $this->b2cOrderWithPositions(2);
        $token = $this->tokenFrom($this->invite($order));
        $positions = AppraisalPosition::where('order_id', $order->id)->orderBy('sort_order')->get();

        $this->post(route('workshop.quotations.submit', $token), [
            'company_name' => 'Meier Karosserie GmbH',
            'contact_person' => 'Jens Meier',
            'contact_email' => 'jens@meier.test',
            'contact_phone' => '+49 30 123456',
            'earliest_repair_start' => now()->addWeek()->toDateString(),
            'processing_days' => 4,
            'items' => [
                ['appraisal_position_id' => $positions[0]->id, 'amount_net' => '300.00', 'repair_method' => 'Instandsetzung'],
                ['appraisal_position_id' => $positions[1]->id, 'not_repairable' => true],
            ],
        ])->assertRedirect(route('workshop.quotations.thanks'));

        $quotation = WorkshopQuotation::where('order_id', $order->id)->sole();
        $this->assertSame('submitted', $quotation->status());
        $this->assertSame('Meier Karosserie GmbH', $quotation->company_name);
        $this->assertSame(4, $quotation->processing_days);
        // The total counts priced items only; a not-repairable one carries no
        // amount and must not be silently read as zero-cost work.
        $this->assertSame('300.00', (string) $quotation->total_net);

        $items = WorkshopQuotationItem::where('quotation_id', $quotation->id)->get()->keyBy('appraisal_position_id');
        $this->assertSame('300.00', (string) $items[$positions[0]->id]->amount_net);
        $this->assertSame('Instandsetzung', $items[$positions[0]->id]->repair_method);
        $this->assertTrue((bool) $items[$positions[1]->id]->not_repairable);
        $this->assertNull($items[$positions[1]->id]->amount_net);
    }

    public function test_a_workshop_can_decline_the_requested_amount(): void
    {
        $order = $this->b2cOrderWithPositions();
        $token = $this->tokenFrom($this->invite($order));

        $this->submitVia($token, [], [
            'cannot_repair_for_amount' => true,
            'cannot_repair_note' => 'Ersatzteil nicht lieferbar.',
        ])->assertRedirect();

        $quotation = WorkshopQuotation::where('order_id', $order->id)->sole();
        $this->assertTrue($quotation->cannot_repair_for_amount);
        $this->assertSame('Ersatzteil nicht lieferbar.', $quotation->cannot_repair_note);
    }

    public function test_a_second_submission_cannot_overwrite_the_first(): void
    {
        $order = $this->b2cOrderWithPositions();
        $token = $this->tokenFrom($this->invite($order));

        $this->submitVia($token, ['amount_net' => '100.00'], ['company_name' => 'Erste GmbH'])->assertRedirect();
        $this->submitVia($token, ['amount_net' => '1.00'], ['company_name' => 'Zweite GmbH'])->assertNotFound();

        $quotation = WorkshopQuotation::where('order_id', $order->id)->sole();
        $this->assertSame('Erste GmbH', $quotation->company_name);
        $this->assertSame('100.00', (string) $quotation->total_net);
        $this->assertSame(1, WorkshopQuotationItem::where('quotation_id', $quotation->id)->count());
    }

    /**
     * The route's own guard reads the row fresh, so a sequential replay never
     * reaches the service. A racing request does: it holds a snapshot taken
     * before the winner committed, on which `isOpenForSubmission()` is still
     * true. Handing the service exactly that stale instance is what proves the
     * locked re-read inside the transaction — not the fast path in front of it
     * — is what refuses the write.
     */
    public function test_a_stale_racing_submission_is_refused_by_the_locked_re_read(): void
    {
        $order = $this->b2cOrderWithPositions();
        $this->invite($order);

        $service = app(WorkshopQuotationService::class);
        $stale = WorkshopQuotation::where('order_id', $order->id)->sole();
        $position = AppraisalPosition::where('order_id', $order->id)->value('id');

        $payload = fn (string $company, string $amount) => [
            'company_name' => $company,
            'contact_person' => 'Kontakt',
            'contact_email' => 'kontakt@example.test',
            'items' => [['appraisal_position_id' => $position, 'amount_net' => $amount]],
        ];

        $service->submit(WorkshopQuotation::whereKey($stale->id)->sole(), $payload('Gewinner GmbH', '500.00'));

        $this->assertTrue($stale->isOpenForSubmission(), 'the racing request must still believe the form is open');

        try {
            $service->submit($stale, $payload('Verlierer GmbH', '5.00'));
            $this->fail('the second submission should have been refused');
        } catch (HttpResponseException $e) {
            $this->assertSame(410, $e->getResponse()->getStatusCode());
        }

        $quotation = WorkshopQuotation::whereKey($stale->id)->sole();
        $this->assertSame('Gewinner GmbH', $quotation->company_name);
        $this->assertSame('500.00', (string) $quotation->total_net);
        $this->assertSame(1, WorkshopQuotationItem::where('quotation_id', $quotation->id)->count());
    }

    // -------------------------------------------------------- position isolation

    public function test_a_workshop_cannot_price_another_orders_position(): void
    {
        $order = $this->b2cOrderWithPositions();
        $token = $this->tokenFrom($this->invite($order));
        $foreign = AppraisalPosition::where('order_id', $this->b2cOrderWithPositions()->id)->value('id');

        $this->submitVia($token, ['appraisal_position_id' => $foreign])
            ->assertSessionHasErrors('items.0.appraisal_position_id');

        $this->assertSame(0, WorkshopQuotationItem::count());
        $this->assertNull(WorkshopQuotation::where('order_id', $order->id)->value('submitted_at'));
    }

    public function test_a_workshop_cannot_price_another_vehicles_position(): void
    {
        $order = $this->b2cOrderWithPositions();
        $token = $this->tokenFrom($this->invite($order));

        // A different car entirely, in the other channel — the allow-list is
        // derived from this quotation's order, so neither fact helps.
        $foreign = AppraisalPosition::where('order_id', $this->b2bOrderWithPositions()->id)->value('id');

        $this->submitVia($token, ['appraisal_position_id' => $foreign])
            ->assertSessionHasErrors('items.0.appraisal_position_id');

        $this->assertSame(0, WorkshopQuotationItem::count());
    }

    public function test_a_tampered_position_id_is_rejected_rather_than_ignored(): void
    {
        $order = $this->b2cOrderWithPositions();
        $token = $this->tokenFrom($this->invite($order));

        foreach ([(string) Str::uuid(), 'not-a-uuid', ''] as $tampered) {
            $this->submitVia($token, ['appraisal_position_id' => $tampered])
                ->assertSessionHasErrors('items.0.appraisal_position_id');
        }

        $this->assertSame(0, WorkshopQuotationItem::count());
        $this->assertNull(WorkshopQuotation::where('order_id', $order->id)->value('submitted_at'));
    }

    // ------------------------------------------------------------ admin payload

    public function test_the_admin_b2c_payload_carries_quotations_instead_of_null(): void
    {
        $order = $this->b2cOrderWithPositions();
        $token = $this->tokenFrom($this->invite($order, ['workshop_label' => 'Werkstatt Nord']));
        $this->submitVia($token, ['amount_net' => '420.00'], ['company_name' => 'Nord GmbH']);

        $payload = $this->orderPayload($order);
        $quotation = $payload['workshop_quotations'][0];

        $this->assertSame('B2C', $payload['vehicle_belongs']);
        $this->assertSame('Werkstatt Nord', $quotation['workshop_label']);
        $this->assertSame('submitted', $quotation['status']);
        $this->assertSame('Nord GmbH', $quotation['company_name']);
        $this->assertSame('420.00', $quotation['total_net']);
    }

    public function test_admin_compares_several_b2c_quotations_position_by_position(): void
    {
        $order = $this->b2cOrderWithPositions();
        $cheap = $this->tokenFrom($this->invite($order, ['workshop_label' => 'Günstig']));
        $dear = $this->tokenFrom($this->invite($order, ['workshop_label' => 'Teuer']));

        $this->submitVia($cheap, ['amount_net' => '250.00']);
        $this->submitVia($dear, ['amount_net' => '800.00']);

        $quotations = collect($this->orderPayload($order)['workshop_quotations'])->keyBy('workshop_label');

        $this->assertCount(2, $quotations);
        // The saving against the appraisal is what Admin is choosing on, and it
        // is computed per position, not just per total.
        $this->assertSame('250.00', $quotations['Günstig']['comparison'][0]['workshop_amount_net']);
        $this->assertSame('250.00', $quotations['Günstig']['comparison'][0]['difference_net']);
        $this->assertSame('-300.00', $quotations['Teuer']['comparison'][0]['difference_net']);
    }

    public function test_an_order_without_invitations_sends_an_empty_list_not_null(): void
    {
        $this->assertSame([], $this->orderPayload($this->b2cOrderWithPositions())['workshop_quotations']);
    }

    public function test_the_admin_payload_never_carries_a_token_hash(): void
    {
        $order = $this->b2cOrderWithPositions();
        $this->invite($order);

        $hash = DB::table((new WorkshopQuotation)->getTable())->value('token_hash');

        $this->assertStringNotContainsString($hash, (string) json_encode($this->orderPayload($order)));
    }

    /**
     * Sharing the quotation flow must not drag the rest of the B2B page across:
     * billing, notes and collection stay B2B-only in this task, and so does
     * turning a quotation into a customer offer.
     */
    public function test_unblocking_quotations_does_not_expose_other_b2b_sections(): void
    {
        $order = $this->b2cOrderWithPositions();
        $token = $this->tokenFrom($this->invite($order));
        $this->submitVia($token);

        $payload = $this->orderPayload($order);

        $this->assertNull($payload['billing']);
        $this->assertNull($payload['notes']);
        $this->assertNull($payload['collection']);

        // Tasks are no longer on this list: the resolver answers for both
        // channels now. OrderTaskResolverTest owns that boundary.
        $this->assertNotNull($payload['tasks']);
    }

    /**
     * This asserted a 404 when quotations were shared but offers were not. That
     * boundary is gone — a B2C quotation now becomes a customer offer like any
     * other — so what is pinned here is only that a submitted quotation reaches
     * the offer route at all. QuotationBackedOfferTest owns the rest.
     */
    public function test_a_b2c_quotation_can_be_turned_into_a_customer_offer(): void
    {
        $order = $this->b2cOrderWithPositions();
        $token = $this->tokenFrom($this->invite($order));
        $this->submitVia($token);
        $quotation = WorkshopQuotation::where('order_id', $order->id)->sole();

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.orders.b2b-offer.store', $order->id), ['workshop_quotation_id' => $quotation->id])
            ->assertRedirect();

        $this->assertSame(1, DB::table('leasyback_offers')->count());
    }

    // ------------------------------------------------------------------- B2B

    public function test_b2b_quotations_still_work_exactly_as_before(): void
    {
        $order = $this->b2bOrderWithPositions();
        $token = $this->tokenFrom($this->invite($order, ['workshop_label' => 'Flottenpartner']));

        $this->submitVia($token, ['amount_net' => '640.00'], ['company_name' => 'Flotten GmbH'])->assertRedirect();

        $payload = $this->orderPayload($order);

        $this->assertSame('B2B', $payload['vehicle_belongs']);
        $this->assertSame('submitted', $payload['workshop_quotations'][0]['status']);
        $this->assertSame('640.00', $payload['workshop_quotations'][0]['total_net']);
        $this->assertNotNull($payload['billing'], 'B2B sections must be untouched');
    }

    public function test_a_b2b_quotation_can_still_become_a_customer_offer(): void
    {
        $order = $this->b2bOrderWithPositions();
        $this->submitVia($this->tokenFrom($this->invite($order)));
        $quotation = WorkshopQuotation::where('order_id', $order->id)->sole();

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.orders.b2b-offer.store', $order->id), ['workshop_quotation_id' => $quotation->id])
            ->assertRedirect();

        $this->assertSame(1, DB::table('leasyback_offers')->count());
    }

    // -------------------------------------------------------------- structure

    public function test_both_channels_share_one_quotation_table_and_one_service(): void
    {
        $this->submitVia($this->tokenFrom($this->invite($this->b2cOrderWithPositions())));
        $this->submitVia($this->tokenFrom($this->invite($this->b2bOrderWithPositions())));

        $this->assertSame(2, DB::table((new WorkshopQuotation)->getTable())->count());
        $this->assertSame(2, DB::table((new WorkshopQuotationItem)->getTable())->count());

        foreach ([
            'App\Models\B2cWorkshopQuotation',
            'App\Modules\UserProfile\Order\Models\B2cWorkshopQuotation',
            'App\Modules\UserProfile\Order\Services\B2cWorkshopQuotationService',
        ] as $forbidden) {
            $this->assertFalse(class_exists($forbidden), "{$forbidden} must not exist");
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invite(LeasybackOrder $order, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->makeAdmin())
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.workshop-quotations.store', $order->id), [
                'workshop_label' => 'Werkstatt',
                ...$overrides,
            ]);
    }

    private function tokenFrom(TestResponse $response): string
    {
        $link = $response->baseResponse->getSession()->get('workshop_link');

        $this->assertIsString($link, 'the invitation did not flash a link');

        return Str::afterLast($link, '/');
    }

    /**
     * @param  array<string, mixed>  $itemOverrides
     * @param  array<string, mixed>  $overrides
     */
    private function submitVia(string $token, array $itemOverrides = [], array $overrides = []): TestResponse
    {
        $quotation = WorkshopQuotation::where('token_hash', hash('sha256', $token))->first();
        $position = $quotation === null
            ? null
            : AppraisalPosition::where('order_id', $quotation->order_id)->orderBy('sort_order')->value('id');

        return $this->post(route('workshop.quotations.submit', $token), [
            'company_name' => 'Werkstatt GmbH',
            'contact_person' => 'Kontakt Person',
            'contact_email' => 'kontakt@werkstatt.test',
            'items' => [[
                'appraisal_position_id' => $position,
                'amount_net' => '400.00',
                ...$itemOverrides,
            ]],
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicPayload(string $token): array
    {
        $response = $this->get(route('workshop.quotations.show', $token));
        $response->assertOk();

        return $response->viewData('page')['props']['quotation'];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(LeasybackOrder $order): array
    {
        return $this->actingAs($this->makeAdmin())
            ->get(route('admin.orders.show', $order->id))
            ->viewData('page')['props']['order'];
    }

    private function b2cOrderWithPositions(int $count = 1, ?User $owner = null): LeasybackOrder
    {
        $vehicle = Vehicle::factory()->create([
            'vehicle_belongs' => 'B2C',
            'b2b_id' => null,
            'b2c_user_id' => $owner?->id ?? User::factory()->create(['user_type' => UserType::Privatkunde])->id,
        ]);

        $order = LeasybackOrder::factory()->create([
            'vehicle_id' => $vehicle->vehicle_id,
            'order_status' => 'inspected',
        ]);

        return $this->withPositions($order, $count);
    }

    private function b2bOrderWithPositions(int $count = 1): LeasybackOrder
    {
        $order = $this->makeB2bOrder(
            $this->makeB2bVehicle($this->makeCompany(fake()->unique()->company())),
            'inspected',
        );

        return $this->withPositions($order, $count);
    }

    private function withPositions(LeasybackOrder $order, int $count): LeasybackOrder
    {
        for ($i = 0; $i < $count; $i++) {
            AppraisalPosition::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'sort_order' => $i,
                'component' => "Bauteil {$i}",
                'damage_description' => 'Beschädigung',
                'original_amount_net' => '500.00',
                'source' => AppraisalPosition::SOURCE_MANUAL,
            ]);
        }

        return $order;
    }
}
