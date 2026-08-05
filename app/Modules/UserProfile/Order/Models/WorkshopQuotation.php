<?php

namespace App\Modules\UserProfile\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One workshop's invitation to quote on a B2B order, and its submission.
 *
 * The public link's secret is never stored: only `token_hash` is kept, exactly
 * as `b2b_invitations` does it. State is derived from the timestamps rather
 * than duplicated into a status column, so a revoked-and-expired row cannot
 * disagree with itself.
 *
 * All amounts are net (§9 forbids gross in the B2B quotation process).
 */
class WorkshopQuotation extends Model
{
    protected $table = 'b2b_workshop_quotations';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'auftragsnummer',
        'token_hash',
        'workshop_label',
        'invited_email',
        'show_appraisal_amounts',
        'company_name',
        'contact_person',
        'contact_email',
        'contact_phone',
        'earliest_repair_start',
        'processing_days',
        'total_net',
        'cannot_repair_for_amount',
        'cannot_repair_note',
        'expires_at',
        'submitted_at',
        'revoked_at',
        'created_by_user_id',
        'revoked_by_user_id',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'show_appraisal_amounts' => 'boolean',
            'cannot_repair_for_amount' => 'boolean',
            'earliest_repair_start' => 'date',
            'processing_days' => 'integer',
            'total_net' => 'decimal:2',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'revoked_at' => 'datetime',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(LeasybackOrder::class, 'order_id', 'id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkshopQuotationItem::class, 'quotation_id', 'id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether the workshop may still open and fill in the form. A submitted
     * quotation is deliberately closed: §9 keeps submissions visible to Admin
     * but does not allow a workshop to revise one after the fact.
     */
    public function isOpenForSubmission(): bool
    {
        return ! $this->isRevoked() && ! $this->isSubmitted() && ! $this->isExpired();
    }

    public function status(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isSubmitted() => 'submitted',
            $this->isExpired() => 'expired',
            default => 'invited',
        };
    }
}
