<?php

namespace App\Modules\PartnerApi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded Idempotency-Key, with the response it produced.
 *
 * Written only by PartnerIdempotencyService — the middleware never touches
 * these rows directly.
 */
class PartnerIdempotencyKey extends Model
{
    use HasUuids;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'partner_idempotency_keys';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'idempotency_key',
        'endpoint',
        'request_hash',
        'status',
        'response_status',
        'response_body',
        'locked_at',
        'completed_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_status' => 'integer',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(PartnerIntegrationClient::class, 'partner_integration_client_id', 'id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * True while another request holds this key and has not yet finished.
     *
     * The lock is time-bounded so a request that died mid-flight — a worker
     * kill, a fatal error — does not wedge the key permanently.
     */
    public function isLocked(): bool
    {
        if ($this->isCompleted()) {
            return false;
        }

        $lockSeconds = (int) config('partner_api.idempotency.lock_seconds');

        return $this->locked_at !== null
            && $this->locked_at->addSeconds($lockSeconds)->isFuture();
    }
}
