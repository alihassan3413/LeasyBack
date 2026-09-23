<?php

namespace App\Modules\PartnerApi\Models;

use App\Modules\PartnerApi\Enums\PartnerWebhookDeliveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One event's journey to one subscription: the answer to "did you get it".
 *
 * Exactly one row per (event, subscription) pair, enforced by a unique index —
 * a retry, and equally a manual replay, updates this row rather than creating a
 * second. The individual HTTP calls live in `attempts`.
 */
class PartnerWebhookDelivery extends Model
{
    use HasUuids;

    protected $table = 'partner_webhook_deliveries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'partner_webhook_event_id',
        'partner_webhook_subscription_id',
        'status',
        'attempts',
        'next_attempt_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PartnerWebhookDeliveryStatus::class,
            'attempts' => 'integer',
            'last_status_code' => 'integer',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'replayed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(PartnerWebhookEventRecord::class, 'partner_webhook_event_id', 'id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PartnerWebhookSubscription::class, 'partner_webhook_subscription_id', 'id');
    }

    /**
     * Deliberately *not* named `attempts()`. That is already an integer column
     * on this table — the count — and a relation of the same name would be
     * shadowed by the attribute, silently handing callers an int where they
     * asked for a collection.
     */
    public function attemptLog(): HasMany
    {
        return $this->hasMany(PartnerWebhookDeliveryAttempt::class, 'partner_webhook_delivery_id', 'id')
            ->orderBy('attempt');
    }
}
