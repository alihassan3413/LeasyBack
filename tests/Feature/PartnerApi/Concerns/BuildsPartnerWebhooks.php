<?php

namespace Tests\Feature\PartnerApi\Concerns;

use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use App\Modules\PartnerApi\Models\PartnerIntegrationClient;
use App\Modules\PartnerApi\Models\PartnerWebhookSubscription;
use App\Modules\PartnerApi\Services\PartnerWebhookSigner;

/**
 * Scaffolding for the webhook tests.
 *
 * Subscriptions are written directly rather than through the endpoint, because
 * most of these tests are about what happens *after* one exists and going
 * through HTTP would make every one of them also a test of the create route.
 * The secret is set explicitly for the same reason a test never hand-writes a
 * token hash: the signature assertions need to know it, and generating one here
 * keeps that knowledge in the test instead of reaching into the model.
 */
trait BuildsPartnerWebhooks
{
    /**
     * @param  list<string>|null  $eventTypes
     */
    protected function makeSubscription(
        PartnerIntegrationClient $client,
        ?array $eventTypes = null,
        string $url = 'https://partner.example.com/hooks/leasyback',
        bool $active = true,
        ?string $secret = null,
    ): PartnerWebhookSubscription {
        $subscription = new PartnerWebhookSubscription([
            'url' => $url,
            'event_types' => $eventTypes ?? PartnerWebhookEvent::values(),
            'is_active' => $active,
        ]);

        $subscription->partner_integration_client_id = $client->id;
        $subscription->secret = $secret ?? app(PartnerWebhookSigner::class)->generateSecret();
        $subscription->save();

        return $subscription->fresh();
    }

    /**
     * The webhook config as production has it, minus the parts that would make
     * a test unable to run: deliveries go to a fake HTTP client, so the SSRF
     * guard has to be allowed to see `example.com` resolve — which it does, as
     * a public address.
     */
    protected function allowLocalWebhookTargets(): void
    {
        config([
            'partner_api.webhooks.allow_insecure' => true,
            'partner_api.webhooks.allow_private_networks' => true,
        ]);
    }
}
