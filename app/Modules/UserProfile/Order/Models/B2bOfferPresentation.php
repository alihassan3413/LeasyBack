<?php

namespace App\Modules\UserProfile\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The B2B face of one `leasyback_offers` row (§10). The offer record itself is
 * reused unchanged so publishing, selection, the timeline stages and the audit
 * trail keep working; this holds only what B2B adds.
 *
 * `lines` is an immutable **snapshot** taken when the offer is published, not a
 * live join: §10 requires Admin to see exactly what was presented, and phase 8
 * positions stay editable afterwards. All amounts are net.
 */
class B2bOfferPresentation extends Model
{
    protected $table = 'b2b_offer_presentations';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'offer_id',
        'order_id',
        'workshop_quotation_id',
        'lines',
        'appraisal_total_net',
        'repair_total_net',
        'saving_net',
        'valid_until',
        'customer_note',
        'presented_at',
        'last_reminder_sent_at',
        'reminder_count',
        'rejected_at',
        'rejected_by_user_id',
        'customer_comment',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'appraisal_total_net' => 'decimal:2',
            'repair_total_net' => 'decimal:2',
            'saving_net' => 'decimal:2',
            'valid_until' => 'date',
            'presented_at' => 'datetime',
            'last_reminder_sent_at' => 'datetime',
            'reminder_count' => 'integer',
            'rejected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(LeasybackOffer::class, 'offer_id', 'offer_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(WorkshopQuotation::class, 'workshop_quotation_id', 'id');
    }

    /**
     * Expired means the validity date is **before today**, not merely in the
     * past: `valid_until` is a date cast anchored at 00:00, so `isPast()`
     * would call an offer valid through today expired from one second after
     * midnight. An offer is good for the whole of its last day.
     */
    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->lt(now()->startOfDay());
    }
}
