<?php

namespace App\Modules\UserProfile\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The quotation-backed face of one `leasyback_offers` row (§10), in either
 * channel. The offer record itself is reused unchanged so publishing,
 * selection, the timeline stages and the audit trail keep working; this holds
 * what being built from a workshop quotation adds.
 *
 * Its presence is what distinguishes a real offer from the manual fallback: an
 * offer an admin typed by hand has no row here, so "is this offer backed by a
 * workshop quote" is a structural fact rather than a flag anyone can set.
 *
 * Everything here is an immutable **snapshot** taken when the offer is
 * published, not a live join — §10 requires Admin to see exactly what was
 * presented, and the positions, the quotation and the configured VAT rate all
 * stay editable afterwards:
 *
 * - `lines` and the three net totals: the priced damage as it stood;
 * - `vat_rate`: the rate that produced the gross the customer accepted. Null in
 *   a channel that never shows gross (OfferPricingPolicy);
 * - `workshop`: who was going to do the work. `workshop_quotation_id` still
 *   points at the source row, but it is a live FK with nullOnDelete and cannot
 *   answer that question on its own.
 *
 * Stored amounts are net. Gross is derived from `vat_rate` at read time, which
 * is stable precisely because the rate is frozen here.
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
        'vat_rate',
        'workshop',
        'workshop_notified_at',
        'valid_until',
        'customer_note',
        'presented_at',
        'last_reminder_sent_at',
        'expired_notified_at',
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
            'vat_rate' => 'decimal:4',
            'workshop' => 'array',
            'workshop_notified_at' => 'datetime',
            'valid_until' => 'date',
            'presented_at' => 'datetime',
            'last_reminder_sent_at' => 'datetime',
            'expired_notified_at' => 'datetime',
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

    /**
     * The repairing business as the customer should see it — its company name,
     * falling back to the label Admin invited it under when it submitted
     * without one. Never the contact person, email or phone: those are
     * operational and belong to the Admin payload.
     */
    public function workshopName(): ?string
    {
        $workshop = $this->workshop ?? [];

        return $workshop['company_name'] ?? $workshop['label'] ?? null;
    }
}
