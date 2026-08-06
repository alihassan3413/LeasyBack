<?php

namespace App\Modules\PartnerApi\Models;

use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A partner's standing request to be told when something happens.
 *
 * `partner_integration_client_id` is not fillable: it is the authorization
 * boundary, resolved from the token by PartnerContext and never from request
 * input, exactly as `b2b_id` is on the client itself.
 *
 * Both secret columns are `encrypted` casts. A leaked database dump must not
 * hand over the ability to forge signatures for every partner at once; the
 * plaintext is shown to the partner once, at creation and at rotation, and is
 * unrecoverable afterwards.
 */
class PartnerWebhookSubscription extends Model
{
    use HasUuids;

    protected $table = 'partner_webhook_subscriptions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'url',
        'description',
        'event_types',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'secret',
        'previous_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'is_active' => 'boolean',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
            'secret_rotated_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_delivery_at' => 'datetime',
            'last_success_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(PartnerIntegrationClient::class, 'partner_integration_client_id', 'id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PartnerWebhookDelivery::class, 'partner_webhook_subscription_id', 'id');
    }

    public function subscribesTo(PartnerWebhookEvent|string $event): bool
    {
        $value = $event instanceof PartnerWebhookEvent ? $event->value : $event;

        return in_array($value, $this->event_types ?? [], true);
    }

    /**
     * Every secret a signature may currently be computed with.
     *
     * The current one always; the previous one only while its grace window is
     * open. Returned newest-first so a verifier that stops at the first match
     * does the common case in one hash.
     *
     * @return list<string>
     */
    public function signingSecrets(?CarbonInterface $now = null): array
    {
        $secrets = [$this->secret];

        $expiry = $this->previous_secret_expires_at;

        if ($this->previous_secret !== null && $expiry !== null && $expiry->isAfter($now ?? now())) {
            $secrets[] = $this->previous_secret;
        }

        return array_values(array_filter($secrets, fn (?string $secret) => $secret !== null && $secret !== ''));
    }

    /**
     * Deliverable means active *and* the partner has asked for this type. Both
     * halves are checked at fan-out and the active half again at delivery, so a
     * subscription disabled between the two does not get the queued call.
     */
    public function isDeliverable(PartnerWebhookEvent|string $event): bool
    {
        return $this->is_active && $this->subscribesTo($event);
    }
}
