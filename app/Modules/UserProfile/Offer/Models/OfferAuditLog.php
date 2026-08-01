<?php

namespace App\Modules\UserProfile\Offer\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One row per offer state-machine transition — action values
 * created/published/selected_by_customer/closed_after_customer_selection/
 * cancelled, matching docs/B2C_ADMIN_STATUS_MATRIX.md §2/§6 exactly (the
 * doc calls this table "already correct," just previously unwired).
 */
class OfferAuditLog extends Model
{
    protected $table = 'leasyback_offer_audit_log';

    protected $primaryKey = 'log_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'log_id',
        'auftragsnummer',
        'offer_id',
        'order_id',
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

    public function offer(): BelongsTo
    {
        return $this->belongsTo(LeasybackOffer::class, 'offer_id', 'offer_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id', 'id');
    }
}
