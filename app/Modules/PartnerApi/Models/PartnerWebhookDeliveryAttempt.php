<?php

namespace App\Modules\PartnerApi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One HTTP call. What we sent it at, what came back, and how long it took.
 *
 * `response_excerpt` is bounded on write (see PartnerWebhookDeliverer) rather
 * than on read: an endpoint returning a megabyte of HTML error page must not be
 * able to fill our table by failing repeatedly.
 */
class PartnerWebhookDeliveryAttempt extends Model
{
    use HasUuids;

    protected $table = 'partner_webhook_delivery_attempts';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'partner_webhook_delivery_id',
        'attempt',
        'status_code',
        'duration_ms',
        'response_excerpt',
        'error',
        'blocked',
        'attempted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'blocked' => 'boolean',
            'attempted_at' => 'datetime',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(PartnerWebhookDelivery::class, 'partner_webhook_delivery_id', 'id');
    }

    public function succeeded(): bool
    {
        return $this->status_code !== null && $this->status_code >= 200 && $this->status_code < 300;
    }
}
