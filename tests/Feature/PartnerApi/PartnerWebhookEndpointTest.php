<?php

namespace Tests\Feature\PartnerApi;

use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\PartnerApi\Enums\PartnerWebhookDeliveryStatus;
use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use App\Modules\PartnerApi\Jobs\DeliverPartnerWebhook;
use App\Modules\PartnerApi\Models\PartnerWebhookDelivery;
use App\Modules\PartnerApi\Models\PartnerWebhookEventRecord;
use App\Modules\PartnerApi\Models\PartnerWebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerClients;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerWebhooks;
use Tests\TestCase;

/**
 * The webhook management surface: create, read, update, rotate, replay, test.
 *
 * Two properties get most of the attention here, because they are the ones a
 * mistake would be silent about: the secret is returned exactly twice in a
 * subscription's life and never again, and another client's subscription is a
 * 404 rather than a 403.
 */
class PartnerWebhookEndpointTest extends TestCase
{
    use BuildsPartnerClients;
    use BuildsPartnerWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // These tests are about the management surface, not the SSRF guard —
        // which has its own file. The escape hatch is on so `example.com`
        // targets do not need a live DNS answer in CI.
        $this->allowLocalWebhookTargets();
    }

    /**
     * @return array<string, string>
     */
    private function withKey(string $token, string $key = 'hook-1'): array
    {
        return [...$this->bearer($token), 'Idempotency-Key' => $key];
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'url' => 'https://partner.example.com/hooks/leasyback',
            'event_types' => ['order.status_changed', 'offer.published'],
            'description' => 'Fleet sync',
            ...$overrides,
        ];
    }

    public function test_a_partner_can_create_a_subscription_and_sees_the_secret_once(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $response = $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.webhooks.store'), $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.webhook.is_active', true)
            ->assertJsonPath('data.webhook.event_types', ['order.status_changed', 'offer.published'])
            ->assertJsonPath('data.webhook.signature.algorithm', 'HMAC-SHA256');

        $secret = $response->json('data.webhook.secret');
        $this->assertIsString($secret);
        $this->assertStringStartsWith('whsec_', $secret);

        // Every subsequent read omits the key entirely rather than nulling it,
        // so a partner testing for its presence gets the right answer.
        $id = $response->json('data.webhook.id');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.webhooks.show', $id))
            ->assertOk()
            ->assertJsonMissingPath('data.webhook.secret');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.webhooks.index'))
            ->assertOk()
            ->assertJsonMissingPath('data.webhooks.0.secret');
    }

    public function test_the_stored_secret_is_encrypted_at_rest(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $secret = $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.webhooks.store'), $this->validPayload())
            ->assertCreated()
            ->json('data.webhook.secret');

        $stored = DB::table('partner_webhook_subscriptions')->value('secret');

        $this->assertNotSame($secret, $stored);
        $this->assertSame($secret, PartnerWebhookSubscription::first()->secret);
    }

    public function test_an_unknown_event_type_is_refused_rather_than_dropped(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->withKey($token))
            ->postJson(route('partner.v1.webhooks.store'), $this->validPayload([
                'event_types' => ['order.status_changed', 'order.teleported'],
            ]))
            ->assertStatus(422);

        $this->assertSame(0, PartnerWebhookSubscription::count());
    }

    public function test_creating_a_subscription_requires_an_idempotency_key(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();

        $this->withHeaders($this->bearer($token))
            ->postJson(route('partner.v1.webhooks.store'), $this->validPayload())
            ->assertStatus(400);
    }

    public function test_reads_require_the_read_ability_and_writes_the_manage_ability(): void
    {
        [$client, $readOnly] = $this->makeAuthenticatedPartner(abilities: [PartnerAbility::ReadWebhooks->value]);
        $subscription = $this->makeSubscription($client);

        $this->withHeaders($this->bearer($readOnly))
            ->getJson(route('partner.v1.webhooks.show', $subscription->id))
            ->assertOk();

        $this->withHeaders($this->withKey($readOnly))
            ->postJson(route('partner.v1.webhooks.store'), $this->validPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'insufficient_scope');

        $manageOnly = $this->issueToken($client, [PartnerAbility::ManageWebhooks->value])->plainTextToken;

        $this->withHeaders($this->bearer($manageOnly))
            ->getJson(route('partner.v1.webhooks.show', $subscription->id))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'insufficient_scope');
    }

    /**
     * The other half of the gate, broken on its own: the token keeps every
     * ability, and the integration account loses its company permission.
     */
    public function test_the_company_permission_is_required_independently_of_the_ability(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $subscription = $this->makeSubscription($client);

        $this->setCompanyPermissions($client, []);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.webhooks.show', $subscription->id))
            ->assertStatus(403);
    }

    public function test_another_clients_subscription_is_not_found(): void
    {
        [, $token] = $this->makeAuthenticatedPartner();
        [$other] = $this->makeAuthenticatedPartner(slug: 'other-partner');
        $foreign = $this->makeSubscription($other);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.webhooks.show', $foreign->id))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'webhook_not_found');

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.webhooks.deliveries.index', $foreign->id))
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->deleteJson(route('partner.v1.webhooks.destroy', $foreign->id))
            ->assertNotFound();

        $this->assertDatabaseHas('partner_webhook_subscriptions', ['id' => $foreign->id]);
    }

    public function test_a_partner_can_disable_and_re_enable_a_subscription(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $subscription = $this->makeSubscription($client);
        $subscription->forceFill(['consecutive_failures' => 7])->save();

        $this->withHeaders($this->withKey($token, 'off'))
            ->patchJson(route('partner.v1.webhooks.update', $subscription->id), ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.webhook.is_active', false)
            ->assertJsonPath('data.webhook.disabled_reason', 'Disabled by the partner.');

        $this->withHeaders($this->withKey($token, 'on'))
            ->patchJson(route('partner.v1.webhooks.update', $subscription->id), ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.webhook.is_active', true)
            ->assertJsonPath('data.webhook.disabled_reason', null)
            // Re-enabling clears the counter, or the next single failure would
            // suspend a freshly fixed endpoint again.
            ->assertJsonPath('data.webhook.consecutive_failures', 0);
    }

    public function test_rotating_the_secret_returns_a_new_one_and_keeps_the_old_valid_briefly(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $subscription = $this->makeSubscription($client, secret: 'whsec_original');

        $response = $this->withHeaders($this->withKey($token, 'rotate'))
            ->postJson(route('partner.v1.webhooks.rotate-secret', $subscription->id))
            ->assertOk();

        $new = $response->json('data.webhook.secret');

        $this->assertNotSame('whsec_original', $new);
        $this->assertNotNull($response->json('data.webhook.previous_secret_expires_at'));

        $fresh = $subscription->fresh();

        $this->assertSame($new, $fresh->secret);
        $this->assertSame(['whsec_original'], array_slice($fresh->signingSecrets(), 1));
    }

    public function test_a_rotated_secret_stops_verifying_once_the_grace_window_closes(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $subscription = $this->makeSubscription($client, secret: 'whsec_original');

        config(['partner_api.webhooks.secret_rotation_grace_minutes' => 30]);

        $this->withHeaders($this->withKey($token, 'rotate'))
            ->postJson(route('partner.v1.webhooks.rotate-secret', $subscription->id))
            ->assertOk();

        $fresh = $subscription->fresh();

        $this->assertCount(2, $fresh->signingSecrets());
        $this->assertCount(1, $fresh->signingSecrets(now()->addHour()));
    }

    public function test_a_test_event_is_queued_for_delivery(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $subscription = $this->makeSubscription($client, ['order.created']);

        $this->withHeaders($this->bearer($token))
            ->postJson(route('partner.v1.webhooks.test', $subscription->id))
            ->assertStatus(202)
            // Sent regardless of the selected event types — a partner has to be
            // able to verify their endpoint before choosing what to receive.
            ->assertJsonPath('data.delivery.event.type', PartnerWebhookEvent::TEST_TYPE);

        Queue::assertPushed(DeliverPartnerWebhook::class, 1);
    }

    public function test_a_failed_delivery_can_be_replayed_and_a_succeeded_one_cannot(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $subscription = $this->makeSubscription($client);

        $failed = $this->makeDelivery($subscription, PartnerWebhookDeliveryStatus::Exhausted);
        $succeeded = $this->makeDelivery($subscription, PartnerWebhookDeliveryStatus::Succeeded);

        $this->withHeaders($this->bearer($token))
            ->postJson(route('partner.v1.webhooks.deliveries.replay', [$subscription->id, $failed->id]))
            ->assertStatus(202)
            ->assertJsonPath('data.delivery.status', 'pending');

        Queue::assertPushed(DeliverPartnerWebhook::class, 1);

        $this->withHeaders($this->bearer($token))
            ->postJson(route('partner.v1.webhooks.deliveries.replay', [$subscription->id, $succeeded->id]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'webhook_delivery_already_succeeded');
    }

    public function test_deliveries_can_be_filtered_to_failures(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $subscription = $this->makeSubscription($client);

        $this->makeDelivery($subscription, PartnerWebhookDeliveryStatus::Succeeded);
        $this->makeDelivery($subscription, PartnerWebhookDeliveryStatus::Failed);
        $this->makeDelivery($subscription, PartnerWebhookDeliveryStatus::Exhausted);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.webhooks.deliveries.index', $subscription->id))
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.webhooks.deliveries.index', $subscription->id).'?status=failed')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);
    }

    public function test_a_delivery_belonging_to_another_subscription_is_not_found(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $mine = $this->makeSubscription($client);
        $theirs = $this->makeSubscription($client, url: 'https://partner.example.com/other');

        $delivery = $this->makeDelivery($theirs, PartnerWebhookDeliveryStatus::Failed);

        $this->withHeaders($this->bearer($token))
            ->getJson(route('partner.v1.webhooks.deliveries.show', [$mine->id, $delivery->id]))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'webhook_delivery_not_found');
    }

    public function test_deleting_a_subscription_removes_it_and_its_history(): void
    {
        [$client, $token] = $this->makeAuthenticatedPartner();
        $subscription = $this->makeSubscription($client);
        $delivery = $this->makeDelivery($subscription, PartnerWebhookDeliveryStatus::Failed);

        $this->withHeaders($this->bearer($token))
            ->deleteJson(route('partner.v1.webhooks.destroy', $subscription->id))
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('partner_webhook_subscriptions', ['id' => $subscription->id]);
        $this->assertDatabaseMissing('partner_webhook_deliveries', ['id' => $delivery->id]);
    }

    private function makeDelivery(
        PartnerWebhookSubscription $subscription,
        PartnerWebhookDeliveryStatus $status,
    ): PartnerWebhookDelivery {
        $event = PartnerWebhookEventRecord::create([
            'event_id' => 'evt_'.bin2hex(random_bytes(16)),
            'type' => PartnerWebhookEvent::OrderStatusChanged->value,
            'api_version' => 'v1',
            'b2b_id' => $subscription->client->b2b_id,
            'payload' => ['order' => ['id' => 'x']],
            'occurred_at' => now(),
        ]);

        return PartnerWebhookDelivery::create([
            'partner_webhook_event_id' => $event->id,
            'partner_webhook_subscription_id' => $subscription->id,
            'status' => $status,
            'attempts' => 1,
        ]);
    }
}
