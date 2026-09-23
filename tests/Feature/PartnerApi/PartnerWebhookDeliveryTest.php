<?php

namespace Tests\Feature\PartnerApi;

use App\Modules\PartnerApi\Enums\PartnerWebhookDeliveryStatus;
use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use App\Modules\PartnerApi\Models\PartnerWebhookDelivery;
use App\Modules\PartnerApi\Models\PartnerWebhookDeliveryAttempt;
use App\Modules\PartnerApi\Models\PartnerWebhookEventRecord;
use App\Modules\PartnerApi\Models\PartnerWebhookSubscription;
use App\Modules\PartnerApi\Services\PartnerWebhookDeliverer;
use App\Modules\PartnerApi\Services\PartnerWebhookSigner;
use App\Modules\PartnerApi\Services\PartnerWebhookUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerWebhooks;
use Tests\TestCase;

/**
 * Delivery: what we put on the wire, and what happens when the far end does not
 * like it.
 *
 * The signature tests verify against the *published recipe* — timestamp, dot,
 * raw body, HMAC-SHA256 — computed in the test rather than by calling the
 * signer, because a test that asked the signer to check its own output would
 * pass even if the recipe we document were wrong.
 */
class PartnerWebhookDeliveryTest extends TestCase
{
    use BuildsPartnerClients;
    use BuildsPartnerWebhooks;
    use RefreshDatabase;

    private const TARGET = 'https://partner.example.com/hooks/leasyback';

    protected function setUp(): void
    {
        parent::setUp();

        // Without this the `sync` queue would run each rescheduled retry
        // inside the call that scheduled it, and one deliver() would recurse to
        // exhaustion. These tests drive the attempts by hand precisely so each
        // one can be inspected.
        Queue::fake();

        $this->allowLocalWebhookTargets();
    }

    public function test_a_successful_delivery_is_signed_with_the_documented_recipe(): void
    {
        Http::fake([self::TARGET => Http::response('ok', 200)]);

        $subscription = $this->subscription(secret: 'whsec_known');
        $delivery = $this->delivery($subscription);

        app(PartnerWebhookDeliverer::class)->deliver($delivery);

        Http::assertSent(function ($request) use ($delivery) {
            $timestamp = $request->header(PartnerWebhookSigner::HEADER_TIMESTAMP)[0];
            $signature = $request->header(PartnerWebhookSigner::HEADER_SIGNATURE)[0];
            $body = $request->body();

            $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_known');

            return $signature === $expected
                && $request->header(PartnerWebhookSigner::HEADER_EVENT_ID)[0] === $delivery->event->event_id;
        });

        $fresh = $delivery->fresh();

        $this->assertSame(PartnerWebhookDeliveryStatus::Succeeded, $fresh->status);
        $this->assertSame(200, $fresh->last_status_code);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertNull($fresh->next_attempt_at);
    }

    public function test_a_signature_computed_with_the_wrong_secret_does_not_verify(): void
    {
        Http::fake([self::TARGET => Http::response('ok', 200)]);

        $subscription = $this->subscription(secret: 'whsec_known');
        app(PartnerWebhookDeliverer::class)->deliver($this->delivery($subscription));

        Http::assertSent(function ($request) {
            $timestamp = $request->header(PartnerWebhookSigner::HEADER_TIMESTAMP)[0];
            $wrong = 'v1='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'whsec_other');

            return $request->header(PartnerWebhookSigner::HEADER_SIGNATURE)[0] !== $wrong;
        });
    }

    /**
     * Moving the timestamp invalidates the signature, which is what makes a
     * partner's replay window a real defence rather than a decorative one.
     */
    public function test_the_timestamp_is_covered_by_the_signature(): void
    {
        $signer = app(PartnerWebhookSigner::class);
        $body = '{"id":"evt_1"}';

        $original = $signer->sign('whsec_known', '1000', $body);

        $this->assertFalse($signer->verify(['whsec_known'], $original, '2000', $body));
        $this->assertTrue($signer->verify(['whsec_known'], $original, '1000', $body));
    }

    /**
     * During a rotation grace window both secrets are valid material for the
     * published recipe, so a partner mid-deploy keeps passing either way.
     */
    public function test_both_secrets_verify_during_a_rotation_grace_window(): void
    {
        $signer = app(PartnerWebhookSigner::class);
        $subscription = $this->subscription(secret: 'whsec_old');

        $signedWithOld = $signer->sign('whsec_old', '1000', 'body');

        $subscription->forceFill([
            'previous_secret' => 'whsec_old',
            'previous_secret_expires_at' => now()->addHour(),
            'secret' => 'whsec_new',
        ])->save();

        $fresh = $subscription->fresh();

        $this->assertTrue($signer->verify($fresh->signingSecrets(), $signedWithOld, '1000', 'body'));
        // Once the window closes the old secret is no longer offered, so the
        // same signature stops verifying.
        $this->assertFalse($signer->verify($fresh->signingSecrets(now()->addDay()), $signedWithOld, '1000', 'body'));
    }

    public function test_a_failing_endpoint_is_retried_on_the_documented_backoff(): void
    {
        Http::fake([self::TARGET => Http::response('upstream exploded', 500)]);
        config(['partner_api.webhooks.backoff_seconds' => [30, 120]]);

        $subscription = $this->subscription();
        $delivery = $this->delivery($subscription);
        $deliverer = app(PartnerWebhookDeliverer::class);

        $deliverer->deliver($delivery);
        $first = $delivery->fresh();

        $this->assertSame(PartnerWebhookDeliveryStatus::Failed, $first->status);
        $this->assertSame(1, $first->attempts);
        $this->assertSame(500, $first->last_status_code);
        // Measured from the attempt, not from `now()`: a slow first run would
        // otherwise drift the assertion by however long the request took.
        $this->assertSame(30, (int) $first->last_attempt_at->diffInSeconds($first->next_attempt_at));

        $deliverer->deliver($first);
        $second = $delivery->fresh();
        $this->assertSame(120, (int) $second->last_attempt_at->diffInSeconds($second->next_attempt_at));

        // Off the end of the table: nothing further is scheduled, and the state
        // says so rather than leaving the partner guessing whether we are still
        // trying.
        $deliverer->deliver($delivery->fresh());
        $exhausted = $delivery->fresh();

        $this->assertSame(PartnerWebhookDeliveryStatus::Exhausted, $exhausted->status);
        $this->assertNull($exhausted->next_attempt_at);
        $this->assertSame(3, $exhausted->attempts);
    }

    /**
     * The event id is generated once, at emit. Every retry has to carry it or
     * it is useless as a deduplication key on the partner's side.
     */
    public function test_every_attempt_carries_the_same_event_id(): void
    {
        Http::fake([self::TARGET => Http::response('nope', 503)]);
        config(['partner_api.webhooks.backoff_seconds' => [30, 60]]);

        $delivery = $this->delivery($this->subscription());
        $eventId = $delivery->event->event_id;
        $deliverer = app(PartnerWebhookDeliverer::class);

        $deliverer->deliver($delivery);
        $deliverer->deliver($delivery->fresh());

        $seen = [];

        Http::assertSent(function ($request) use (&$seen) {
            $seen[] = $request->header(PartnerWebhookSigner::HEADER_EVENT_ID)[0];

            return true;
        });

        $this->assertSame([$eventId, $eventId], $seen);
        $this->assertSame(2, PartnerWebhookDeliveryAttempt::count());
    }

    public function test_a_success_stops_the_retries(): void
    {
        Http::fakeSequence()
            ->push('down', 502)
            ->push('ok', 200);

        config(['partner_api.webhooks.backoff_seconds' => [30, 60, 90]]);

        $delivery = $this->delivery($this->subscription());
        $deliverer = app(PartnerWebhookDeliverer::class);

        $deliverer->deliver($delivery);
        $this->assertSame(PartnerWebhookDeliveryStatus::Failed, $delivery->fresh()->status);

        $deliverer->deliver($delivery->fresh());
        $succeeded = $delivery->fresh();

        $this->assertSame(PartnerWebhookDeliveryStatus::Succeeded, $succeeded->status);
        $this->assertNull($succeeded->next_attempt_at);
        $this->assertSame(2, $succeeded->attempts);
    }

    public function test_a_success_clears_the_auto_disable_counter(): void
    {
        Http::fake([self::TARGET => Http::response('ok', 200)]);

        $subscription = $this->subscription();
        $subscription->forceFill(['consecutive_failures' => 9])->save();

        app(PartnerWebhookDeliverer::class)->deliver($this->delivery($subscription->fresh()));

        $this->assertSame(0, $subscription->fresh()->consecutive_failures);
        $this->assertNotNull($subscription->fresh()->last_success_at);
    }

    public function test_repeated_failure_suspends_the_subscription(): void
    {
        Http::fake([self::TARGET => Http::response('dead', 500)]);
        config([
            'partner_api.webhooks.backoff_seconds' => [],
            'partner_api.webhooks.auto_disable_after_failures' => 2,
        ]);

        $subscription = $this->subscription();

        app(PartnerWebhookDeliverer::class)->deliver($this->delivery($subscription));
        $this->assertTrue($subscription->fresh()->is_active);

        app(PartnerWebhookDeliverer::class)->deliver($this->delivery($subscription->fresh()));

        $suspended = $subscription->fresh();

        $this->assertFalse($suspended->is_active);
        $this->assertNotNull($suspended->disabled_at);
        $this->assertStringContainsString('Automatically suspended', $suspended->disabled_reason);
    }

    /**
     * A partner who disables a subscription has said stop. A retry already
     * sitting on the queue is the one thing that could still call them, so the
     * check is repeated here and not only at fan-out.
     */
    public function test_a_disabled_subscription_is_never_called(): void
    {
        Http::fake();

        $subscription = $this->subscription(active: false);
        $delivery = $this->delivery($subscription);

        app(PartnerWebhookDeliverer::class)->deliver($delivery);

        Http::assertNothingSent();
        $this->assertSame(PartnerWebhookDeliveryStatus::Exhausted, $delivery->fresh()->status);
    }

    public function test_a_stored_response_excerpt_is_bounded(): void
    {
        Http::fake([self::TARGET => Http::response(str_repeat('E', 5000), 500)]);
        config(['partner_api.webhooks.response_excerpt_bytes' => 64]);

        $delivery = $this->delivery($this->subscription());
        app(PartnerWebhookDeliverer::class)->deliver($delivery);

        $this->assertLessThanOrEqual(70, strlen((string) $delivery->fresh()->last_response_excerpt));
    }

    public function test_the_body_carries_the_documented_envelope(): void
    {
        Http::fake([self::TARGET => Http::response('ok', 200)]);

        $delivery = $this->delivery($this->subscription());
        app(PartnerWebhookDeliverer::class)->deliver($delivery);

        Http::assertSent(function ($request) use ($delivery) {
            $body = json_decode($request->body(), true);

            return $body['id'] === $delivery->event->event_id
                && $body['type'] === PartnerWebhookEvent::OrderStatusChanged->value
                && $body['api_version'] === 'v1'
                && str_ends_with($body['occurred_at'], 'Z')
                && $body['data']['order']['reference'] === 'BXY123260806';
        });
    }

    #[DataProvider('blockedTargets')]
    public function test_an_unsafe_target_is_refused(string $url, string $because): void
    {
        // The escape hatch is off for this one: these are exactly the URLs it
        // exists to permit off-production, and the point here is what happens
        // when it is not set.
        config([
            'partner_api.webhooks.allow_insecure' => false,
            'partner_api.webhooks.allow_private_networks' => false,
        ]);

        $this->expectException(RuntimeException::class);

        app(PartnerWebhookUrlGuard::class)->resolve($url);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockedTargets(): array
    {
        return [
            'plaintext http' => ['http://partner.example.com/hook', 'scheme'],
            'loopback by name' => ['https://localhost/hook', 'hostname'],
            'loopback by address' => ['https://127.0.0.1/hook', 'address'],
            'private range' => ['https://10.1.2.3/hook', 'address'],
            'private range 192' => ['https://192.168.0.5/hook', 'address'],
            'carrier-grade NAT' => ['https://100.100.0.1/hook', 'address'],
            'cloud instance metadata' => ['https://169.254.169.254/latest/meta-data/', 'address'],
            'IPv6 loopback' => ['https://[::1]/hook', 'address'],
            'IPv6 unique local' => ['https://[fd00::1]/hook', 'address'],
            'internal TLD' => ['https://build-server.internal/hook', 'hostname'],
            'bare hostname' => ['https://redis/hook', 'hostname'],
            'unexpected port' => ['https://partner.example.com:9200/hook', 'port'],
            'embedded credentials' => ['https://user:pass@partner.example.com/hook', 'credentials'],
        ];
    }

    public function test_a_target_that_becomes_unsafe_is_refused_at_delivery_and_recorded(): void
    {
        Http::fake();

        $subscription = $this->subscription();
        $delivery = $this->delivery($subscription);

        // The URL passed the guard when it was stored; the guard now refuses
        // it. That is precisely the DNS-rebinding shape re-checking exists for.
        config([
            'partner_api.webhooks.allow_insecure' => false,
            'partner_api.webhooks.allow_private_networks' => false,
        ]);
        $subscription->forceFill(['url' => 'https://127.0.0.1/hook'])->save();

        app(PartnerWebhookDeliverer::class)->deliver($delivery->fresh());

        Http::assertNothingSent();

        $attempt = PartnerWebhookDeliveryAttempt::firstOrFail();

        $this->assertTrue($attempt->blocked);
        $this->assertNull($attempt->status_code);
        $this->assertStringContainsString('blocked range', (string) $attempt->error);
        $this->assertSame(PartnerWebhookDeliveryStatus::Failed, $delivery->fresh()->status);
    }

    /**
     * Redirects are turned off in the client (`allow_redirects => false`), so a
     * 30x comes back as the response rather than being chased. The observable
     * half of that — the half a test can assert without a live socket — is that
     * a redirect is a *failed* delivery recorded with its own status code, not
     * a success at whatever the Location header pointed at.
     */
    public function test_a_redirect_is_a_failed_delivery_rather_than_a_second_request(): void
    {
        Http::fake([
            self::TARGET => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);

        $delivery = $this->delivery($this->subscription());
        app(PartnerWebhookDeliverer::class)->deliver($delivery);

        Http::assertSentCount(1);

        $fresh = $delivery->fresh();

        $this->assertSame(302, $fresh->last_status_code);
        $this->assertTrue($fresh->status->isFailure());
    }

    private function subscription(bool $active = true, ?string $secret = null): PartnerWebhookSubscription
    {
        [$client] = $this->makeAuthenticatedPartner();

        return $this->makeSubscription($client, url: self::TARGET, active: $active, secret: $secret);
    }

    private function delivery(PartnerWebhookSubscription $subscription): PartnerWebhookDelivery
    {
        $event = PartnerWebhookEventRecord::create([
            'event_id' => app(PartnerWebhookSigner::class)->generateEventId(),
            'type' => PartnerWebhookEvent::OrderStatusChanged->value,
            'api_version' => 'v1',
            'b2b_id' => $subscription->client->b2b_id,
            'payload' => [
                'order' => [
                    'id' => '4b6e0a52-9c3d-4f77-8f2a-77a1c0f9b3d2',
                    'reference' => 'BXY123260806',
                    'status' => 'order_placed',
                ],
                'previous_status' => 'order_requested',
            ],
            'occurred_at' => now(),
        ]);

        return PartnerWebhookDelivery::create([
            'partner_webhook_event_id' => $event->id,
            'partner_webhook_subscription_id' => $subscription->id,
            'status' => PartnerWebhookDeliveryStatus::Pending,
            'attempts' => 0,
            'next_attempt_at' => now(),
        ]);
    }
}
