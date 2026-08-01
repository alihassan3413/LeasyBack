<?php

namespace App\Modules\UserProfile\Order\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Broader order lifecycle events that aren't a pure order_status change
 * (creation, approval with its external-call context, offer-related order
 * touchpoints) — per docs/B2C_ADMIN_STATUS_MATRIX.md §6's audit-trail
 * consolidation recommendation. `leasyback_order_status_updates` (written
 * by TransitionOrderStatus) remains the single source of truth for every
 * order_status transition; this table is deliberately for everything else.
 */
class OrderAuditLog extends Model
{
    protected $table = 'leasyback_order_audit_log';

    protected $primaryKey = 'log_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'log_id',
        'order_id',
        'vehicle_id',
        'changed_by_user_id',
        'action',
        'old_values',
        'new_values',
        'changed_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'changed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->log_id)) {
                $model->log_id = (string) Str::uuid();
            }
            if (empty($model->changed_at)) {
                $model->changed_at = now();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LeasybackOrder::class, 'order_id', 'id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id', 'id');
    }
}
