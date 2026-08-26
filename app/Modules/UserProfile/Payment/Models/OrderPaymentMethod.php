<?php

namespace App\Modules\UserProfile\Payment\Models;

use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Database\Factories\OrderPaymentMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The payment method stored as security for one order, and the evidence that
 * the customer authorized charging it while they are not present.
 *
 * Holds no card data — Stripe holds the card. What is here is an opaque
 * reference plus the four fields needed to render "Visa ···· 4242".
 *
 * @see OrderPayment for the charges
 *      made against it.
 */
class OrderPaymentMethod extends Model
{
    use HasFactory;

    /**
     * Resolved explicitly: the model lives in a module namespace, so Laravel's
     * App\Models → Database\Factories convention cannot find it.
     */
    protected static function newFactory(): OrderPaymentMethodFactory
    {
        return OrderPaymentMethodFactory::new();
    }

    protected $table = 'order_payment_methods';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_AWAITING_METHOD = 'awaiting_method';

    public const STATUS_SAVED = 'saved';

    /**
     * `verified_at` and `offsession_authorized_at` are deliberately absent:
     * they are stamped by the service that performed the server-side
     * verification, never by a caller handing over an array.
     */
    protected $fillable = [
        'order_id',
        'auftragsnummer',
        'stripe_customer_id',
        'setup_intent_id',
        'payment_method_id',
        'pm_brand',
        'pm_last4',
        'pm_exp_month',
        'pm_exp_year',
        'status',
        'authorization_version',
        'authorization_text_hash',
        'authorized_ip',
        'authorized_user_agent',
        'fee_acknowledgement_version',
        'last_error',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'pm_exp_month' => 'integer',
            'pm_exp_year' => 'integer',
            'verified_at' => 'datetime',
            'offsession_authorized_at' => 'datetime',
            'fee_acknowledged_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'id');
    }

    /**
     * Whether this mandate may be used to charge the customer off-session.
     *
     * All four conditions, not just the status. A card that was saved but
     * never server-verified proves only that a browser said so, and a verified
     * card with no recorded authorization is a card we have no permission to
     * use — either one alone would let an unauthorized charge through, so the
     * question is asked in one place and answered on all of them at once.
     */
    public function isChargeableOffSession(): bool
    {
        return $this->status === self::STATUS_SAVED
            && $this->verified_at !== null
            && $this->offsession_authorized_at !== null
            && ! empty($this->payment_method_id);
    }

    /**
     * Whether the customer has finished the payment-method step at all.
     *
     * This is what gates offer acceptance. Distinct from
     * isChargeableOffSession() only in intent — they currently agree, and are
     * kept apart so a future rule that lets someone accept an offer with a
     * method we cannot auto-charge does not silently also authorize charging
     * it.
     */
    public function isUsable(): bool
    {
        return $this->isChargeableOffSession();
    }
}
