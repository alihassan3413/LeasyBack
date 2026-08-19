<?php

namespace Tests\Feature\Order;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Mail\Workshop\WorkshopQuotationRequestedMail;
use App\Models\OrderAuditLog;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\B2b\Concerns\BuildsB2bCompanies;
use Tests\TestCase;

/**
 * The two ends of the workshop conversation that were missing.
 *
 * A secure link was generated and then handed to nobody: an admin copied it out
 * of a flash message and mailed it themselves, and if they navigated away first
 * the link was gone for good. At the other end a workshop could submit a full
 * quotation and no one would know until somebody happened to reopen the order.
 *
 * Both halves carry a credential question. The invitation is the only message
 * in the system containing a live token, and the notification must contain none
 * — it is stored, broadcast and pushed.
 */
class WorkshopQuotationCommunicationTest extends TestCase
{
    use BuildsB2bCompanies;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        // The public quotation routes are throttled and the array cache keeps
        // its counters for the whole process; what is under test is the
        // messaging, not the rate limit.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ----------------------------------------------------------- invitation

    public function test_inviting_a_workshop_for_a_b2c_order_sends_one_email(): void
    {
        $order = $this->b2cOrder();

        $this->invite($order, ['invited_email' => 'meier@example.test'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Anfrage an meier@example.test gesendet.');

        Mail::assertSent(WorkshopQuotationRequestedMail::class, 1);
        Mail::assertSent(WorkshopQuotationRequestedMail::class, fn ($mail) => $mail->hasTo('meier@example.test'));
    }

    public function test_inviting_a_workshop_for_a_b2b_order_sends_the_same_email(): void
    {
        $order = $this->b2bOrder();

        $this->invite($order, ['invited_email' => 'flotte@example.test'])->assertRedirect();

        Mail::assertSent(WorkshopQuotationRequestedMail::class, 1);
        Mail::assertSent(WorkshopQuotationRequestedMail::class, fn ($mail) => $mail->hasTo('flotte@example.test'));
    }

    public function test_three_invitations_produce_three_emails_one_each(): void
    {
        $order = $this->b2cOrder();

        foreach (['a@example.test', 'b@example.test', 'c@example.test'] as $address) {
            $this->invite($order, ['invited_email' => $address, 'workshop_label' => $address]);
        }

        // Inviting several workshops to the same order is the whole point of
        // the flow, so nothing here deduplicates — but one request is one send.
        Mail::assertSent(WorkshopQuotationRequestedMail::class, 3);
        $this->assertSame(3, WorkshopQuotation::count());
    }

    public function test_an_invitation_without_an_address_sends_nothing_and_says_so(): void
    {
        $this->invite($this->b2cOrder())
            ->assertRedirect()
            ->assertSessionHas('success', 'Werkstattlink wurde erstellt. Bitte Link manuell senden.')
            ->assertSessionHas('workshop_link');

        Mail::assertNothingSent();
    }

    // --------------------------------------------------------------- token

    public function test_the_emailed_link_is_the_real_one_time_token(): void
    {
        $order = $this->b2cOrder();

        $response = $this->invite($order, ['invited_email' => 'werkstatt@example.test']);
        $flashed = $response->baseResponse->getSession()->get('workshop_link');

        $mail = $this->sentInvitation();
        $this->assertSame($flashed, $mail->quotationUrl);

        // The link in the email opens the workshop's own form.
        $token = Str::afterLast($mail->quotationUrl, '/');
        $this->get(route('workshop.quotations.show', $token))->assertOk();
        $this->assertSame(
            hash('sha256', $token),
            DB::table('b2b_workshop_quotations')->where('order_id', $order->id)->value('token_hash'),
        );
    }

    public function test_the_plaintext_token_is_still_never_persisted(): void
    {
        $order = $this->b2cOrder();
        $this->invite($order, ['invited_email' => 'werkstatt@example.test']);

        $token = Str::afterLast($this->sentInvitation()->quotationUrl, '/');

        $this->assertStringNotContainsString($token, (string) json_encode(DB::table('b2b_workshop_quotations')->get()));
        $this->assertStringNotContainsString($token, (string) json_encode(DB::table('leasyback_order_audit_log')->get()));
        $this->assertStringNotContainsString($token, (string) json_encode(DB::table('notifications')->get()));
    }

    public function test_the_invitation_email_carries_no_customer_or_competitor_data(): void
    {
        $customer = User::factory()->create([
            'user_type' => UserType::Privatkunde,
            'name' => 'Heike Sonderbar',
            'email' => 'heike.sonderbar@example.test',
        ]);

        $order = $this->b2cOrder($customer);
        $this->invite($order, ['invited_email' => 'erste@example.test', 'workshop_label' => 'Erste GmbH']);
        $this->invite($order, ['invited_email' => 'zweite@example.test', 'workshop_label' => 'Zweite GmbH']);

        $rendered = Mail::sent(WorkshopQuotationRequestedMail::class)->last()->render();

        foreach (['Heike Sonderbar', 'heike.sonderbar@example.test', 'Erste GmbH', 'erste@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $rendered, "invitation leaked [{$secret}]");
        }

        $this->assertStringContainsString('Zweite GmbH', $rendered);
        $this->assertStringNotContainsString('inspected', $rendered, 'the order lifecycle is not the workshop\'s business');
    }

    public function test_the_appraisal_total_appears_only_when_the_invitation_allows_it(): void
    {
        $order = $this->b2cOrder();

        $this->invite($order, ['invited_email' => 'offen@example.test', 'show_appraisal_amounts' => true]);
        $this->assertSame('1500.00', $this->sentInvitation()->requestedTotalNet);
        $this->assertStringContainsString('1.500,00', $this->sentInvitation()->render());

        Mail::fake();
        $this->invite($order, ['invited_email' => 'blind@example.test', 'show_appraisal_amounts' => false]);
        $this->assertNull($this->sentInvitation()->requestedTotalNet);
        $this->assertStringNotContainsString('1.500,00', $this->sentInvitation()->render());
    }

    // ------------------------------------------------------- send failures

    public function test_a_failed_send_leaves_the_invitation_usable(): void
    {
        $order = $this->b2cOrder();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $response = $this->invite($order, ['invited_email' => 'werkstatt@example.test']);

        $response->assertRedirect()
            ->assertSessionHas('success', 'Anfrage erstellt, E-Mail konnte nicht gesendet werden. Bitte Link manuell senden.')
            ->assertSessionHas('workshop_link');

        // Committed, audited and still openable — the admin has the link.
        $quotation = WorkshopQuotation::sole();
        $this->assertSame('invited', $quotation->status());
        $this->assertSame(1, OrderAuditLog::where('action', 'WORKSHOP_QUOTATION_INVITED')->count());

        $token = Str::afterLast($response->baseResponse->getSession()->get('workshop_link'), '/');
        $this->get(route('workshop.quotations.show', $token))->assertOk();
    }

    public function test_a_workshop_can_still_submit_after_a_failed_invitation_email(): void
    {
        $order = $this->b2cOrder();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $response = $this->invite($order, ['invited_email' => 'werkstatt@example.test']);
        $token = Str::afterLast($response->baseResponse->getSession()->get('workshop_link'), '/');

        // No attempt is made to restore the mailer: submission sends no mail,
        // it notifies, so the broken transport is simply irrelevant from here.
        $this->submit($token)->assertRedirect(route('workshop.quotations.thanks'));

        $this->assertSame('submitted', WorkshopQuotation::sole()->status());
        $this->assertNotificationCount(1);
    }

    // ------------------------------------------------- submission notification

    public function test_a_submitted_quotation_notifies_admin_once(): void
    {
        $admin = $this->makeAdmin();
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);

        $this->submit($token, ['company_name' => 'Meier Karosserie GmbH'])->assertRedirect();

        Notification::assertSentTo($admin, SystemNotification::class, function ($notification) use ($order) {
            $payload = $notification->toArray($order);

            $this->assertSame(NotificationType::WorkshopQuotationReceived->value, $payload['type']);
            $this->assertStringContainsString('Meier Karosserie GmbH', $payload['body']);
            $this->assertStringContainsString('400,00', $payload['body']);
            $this->assertSame($order->id, $payload['meta']['order_id']);

            return true;
        });
    }

    public function test_the_notification_points_at_the_right_order(): void
    {
        $this->makeAdmin();
        $other = $this->b2cOrder();
        $order = $this->b2cOrder();

        $this->submit($this->tokenFor($order));

        $payload = $this->sentNotificationPayload();

        $this->assertSame(route('admin.orders.show', $order->id, false), $payload['url']);
        $this->assertSame($order->auftragsnummer, $payload['meta']['auftragsnummer']);
        $this->assertNotSame($other->id, $payload['meta']['order_id']);
    }

    public function test_the_notification_carries_no_token_or_hash(): void
    {
        $this->makeAdmin();
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);

        $this->submit($token);

        $encoded = (string) json_encode($this->sentNotificationPayload());
        $hash = DB::table('b2b_workshop_quotations')->value('token_hash');

        $this->assertStringNotContainsString($token, $encoded);
        $this->assertStringNotContainsString($hash, $encoded);
        $this->assertStringNotContainsString('token', $encoded);
    }

    public function test_a_replayed_submission_notifies_nobody_a_second_time(): void
    {
        $this->makeAdmin();
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);

        $this->submit($token)->assertRedirect();
        $this->submit($token)->assertNotFound();
        $this->submit($token)->assertNotFound();

        $this->assertNotificationCount(1);
        $this->assertSame(1, OrderAuditLog::where('action', 'WORKSHOP_QUOTATION_SUBMITTED')->count());
    }

    public function test_an_expired_token_notifies_nobody(): void
    {
        $this->makeAdmin();
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);

        WorkshopQuotation::query()->update(['expires_at' => now()->subMinute()]);

        $this->submit($token)->assertNotFound();

        $this->assertNotificationCount(0);
    }

    public function test_a_revoked_token_notifies_nobody(): void
    {
        $this->makeAdmin();
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);

        WorkshopQuotation::query()->update(['revoked_at' => now()]);

        $this->submit($token)->assertNotFound();

        $this->assertNotificationCount(0);
    }

    public function test_an_unknown_token_notifies_nobody(): void
    {
        $this->makeAdmin();
        $this->b2cOrder();

        $this->submit(Str::random(64))->assertNotFound();

        $this->assertNotificationCount(0);
    }

    public function test_a_validation_failure_notifies_nobody_and_leaves_the_link_open(): void
    {
        $this->makeAdmin();
        $order = $this->b2cOrder();
        $token = $this->tokenFor($order);

        $this->post(route('workshop.quotations.submit', $token), ['company_name' => 'Ohne Kontakt GmbH'])
            ->assertSessionHasErrors('contact_email');

        $this->assertNotificationCount(0);
        $this->assertSame('invited', WorkshopQuotation::sole()->status());
    }

    // --------------------------------------------------------------- audit

    public function test_the_invitation_is_audited_without_the_credential(): void
    {
        $order = $this->b2cOrder();
        $this->invite($order, ['invited_email' => 'werkstatt@example.test', 'workshop_label' => 'Werkstatt Nord']);

        $audit = OrderAuditLog::where('action', 'WORKSHOP_QUOTATION_INVITED')->sole();

        $this->assertSame($order->id, $audit->order_id);
        $this->assertSame('Werkstatt Nord', $audit->new_values['workshop_label']);
        $this->assertSame('werkstatt@example.test', $audit->new_values['invited_email']);
        $this->assertSame(WorkshopQuotation::sole()->id, $audit->new_values['quotation_id']);
        $this->assertNotNull($audit->changed_by_user_id, 'an invitation has a human actor');

        foreach (['token', 'url', 'hash'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower((string) json_encode($audit->new_values)));
        }
    }

    public function test_the_submission_is_audited_as_an_accountless_actor(): void
    {
        $order = $this->b2cOrder();
        $this->submit($this->tokenFor($order), ['company_name' => 'Meier Karosserie GmbH']);

        $audit = OrderAuditLog::where('action', 'WORKSHOP_QUOTATION_SUBMITTED')->sole();

        $this->assertSame($order->id, $audit->order_id);
        $this->assertSame('Meier Karosserie GmbH', $audit->new_values['company_name']);
        $this->assertSame('400.00', $audit->new_values['total_net']);
        $this->assertNotNull($audit->new_values['submitted_at']);
        // A workshop has no account by design, so there is no user to record.
        $this->assertNull($audit->changed_by_user_id);
    }

    // ------------------------------------------------------------- helpers

    private ?User $admin = null;

    /**
     * One admin for the whole test, not one per call. The notification fans out
     * to every active admin, so a helper that quietly created a second one would
     * make "notified once" mean two rows.
     */
    protected function makeAdmin(): User
    {
        return $this->admin ??= User::factory()->create(['user_type' => UserType::Admin]);
    }

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

    private function tokenFor(LeasybackOrder $order): string
    {
        $response = $this->invite($order, ['invited_email' => 'werkstatt@example.test']);

        return Str::afterLast($response->baseResponse->getSession()->get('workshop_link'), '/');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submit(string $token, array $overrides = []): TestResponse
    {
        $quotation = WorkshopQuotation::where('token_hash', hash('sha256', $token))->first();
        $position = $quotation === null
            ? null
            : AppraisalPosition::where('order_id', $quotation->order_id)->value('id');

        return $this->post(route('workshop.quotations.submit', $token), [
            'company_name' => 'Werkstatt GmbH',
            'contact_person' => 'Kontakt Person',
            'contact_email' => 'kontakt@werkstatt.test',
            'items' => [['appraisal_position_id' => $position, 'amount_net' => '400.00']],
            ...$overrides,
        ]);
    }

    private function sentInvitation(): WorkshopQuotationRequestedMail
    {
        $mail = Mail::sent(WorkshopQuotationRequestedMail::class)->last();

        $this->assertInstanceOf(WorkshopQuotationRequestedMail::class, $mail);

        return $mail;
    }

    /**
     * The single payload Admin was sent. `sentNotifications()` returns a nested
     * array keyed by notifiable then notification class, so it is flattened the
     * same way NotificationFake::assertCount() does.
     *
     * @return array<string, mixed>
     */
    private function sentNotificationPayload(): array
    {
        $sent = collect(Notification::sentNotifications())->flatten(3);

        $this->assertNotEmpty($sent, 'no notification was sent');

        return $sent->first()['notification']->toArray(new \stdClass);
    }

    private function assertNotificationCount(int $expected): void
    {
        Notification::assertCount($expected);
    }

    private function b2cOrder(?User $owner = null): LeasybackOrder
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

    private function b2bOrder(): LeasybackOrder
    {
        return $this->withPositions(
            $this->makeB2bOrder($this->makeB2bVehicle($this->makeCompany(fake()->unique()->company())), 'inspected'),
        );
    }

    private function withPositions(LeasybackOrder $order): LeasybackOrder
    {
        foreach (['1000.00', '500.00'] as $index => $amount) {
            AppraisalPosition::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'sort_order' => $index,
                'component' => "Bauteil {$index}",
                'original_amount_net' => $amount,
                'source' => AppraisalPosition::SOURCE_MANUAL,
            ]);
        }

        return $order;
    }
}
