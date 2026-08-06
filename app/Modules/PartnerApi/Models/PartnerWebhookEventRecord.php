<?php

namespace App\Modules\PartnerApi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One committed business change, recorded once, for everyone who might want it.
 *
 * Named `…EventRecord` rather than `…Event` so it is never mistaken for a
 * Laravel event: this is a row in the outbox, not something the dispatcher
 * fires. Written inside the business transaction and read back after it commits
 * — see PartnerWebhookEmitter for why that ordering is the whole point.
 *
 * `payload` is frozen at emit time and never recomputed. A retry three hours
 * later sends exactly what the first attempt sent, which is what makes
 * `event_id` a usable deduplication key on the partner's side — and what stops
 * an accepted offer's snapshot drifting between attempts.
 */
class PartnerWebhookEventRecord extends Model
{
    use HasUuids;

    protected $table = 'partner_webhook_events';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'type',
        'api_version',
        'b2b_id',
        'order_id',
        'vehicle_id',
        'payload',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PartnerWebhookDelivery::class, 'partner_webhook_event_id', 'id');
    }

    /**
     * The exact JSON body every delivery of this event sends.
     *
     * Built here rather than in the delivery job so that the signed bytes and
     * the stored payload cannot diverge, and so a partner reading
     * `GET /webhooks/{id}/deliveries` sees the same envelope they received.
     *
     * @return array<string, mixed>
     */
    public function envelope(): array
    {
        return [
            'id' => $this->event_id,
            'type' => $this->type,
            'api_version' => $this->api_version,
            'occurred_at' => $this->occurred_at->utc()->toIso8601ZuluString(),
            'data' => $this->payload ?? [],
        ];
    }
}
