<?php

namespace App\Modules\UserProfile\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The internal billing record of one B2B order (§13, §21). One row per order.
 *
 * Deliberately minimal: there is no accounting or payment integration in this
 * codebase, so this records only whether Leasyback has processed the billing,
 * under which reference, and against which uploaded invoice document. It is
 * what the §21 "must not complete before billing" gate reads.
 *
 * `billing_status` is a plain varchar rather than a DB enum precisely so a
 * future Stripe phase can add its own states (e.g. awaiting_payment, paid)
 * without a column-altering migration. No Stripe columns exist yet — they are
 * not added until they are actually used.
 */
class OrderBilling extends Model
{
    protected $table = 'b2b_order_billing';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    protected $fillable = [
        'order_id',
        'auftragsnummer',
        'billing_status',
        'invoice_reference',
        'invoice_document_id',
        'processed_at',
        'processed_by_user_id',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
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

    /**
     * The single question the completion gate asks. Both the status and the
     * timestamp must agree, so a half-written row can never open the gate.
     */
    public function isProcessed(): bool
    {
        return $this->billing_status === self::STATUS_PROCESSED && $this->processed_at !== null;
    }
}
