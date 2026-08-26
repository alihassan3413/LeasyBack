<?php

namespace App\Modules\UserProfile\Payment\Models;

use App\Modules\UserProfile\Payment\Enums\PaymentInitiator;
use Database\Factories\OrderPaymentIntentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One Stripe PaymentIntent belonging to one logical charge. Append-only.
 *
 * `status` mirrors Stripe's own vocabulary unmapped, so a status this codebase
 * has never seen is still recorded faithfully rather than being flattened into
 * the nearest local one.
 *
 * The distinction that matters here is between a *re-confirmation* and a *new
 * intent*. Stripe's retry model is to confirm the same intent again — a
 * decline leaves it at `requires_payment_method` precisely so that is possible,
 * and a 3DS challenge must be completed on that exact intent — so a retry
 * normally bumps `confirmation_count` and creates no row. A new row appears
 * only when the previous intent is genuinely unusable.
 */
class OrderPaymentIntent extends Model
{
    use HasFactory;

    /**
     * Resolved explicitly: the model lives in a module namespace, so Laravel's
     * App\Models → Database\Factories convention cannot find it.
     */
    protected static function newFactory(): OrderPaymentIntentFactory
    {
        return OrderPaymentIntentFactory::new();
    }

    protected $table = 'order_payment_intents';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Stripe intent statuses this application acts on. Kept as constants
     * rather than an enum because the set is Stripe's to define, not ours —
     * an unrecognised value must still round-trip into the column.
     */
    public const STRIPE_REQUIRES_PAYMENT_METHOD = 'requires_payment_method';

    public const STRIPE_REQUIRES_CONFIRMATION = 'requires_confirmation';

    public const STRIPE_REQUIRES_ACTION = 'requires_action';

    public const STRIPE_PROCESSING = 'processing';

    public const STRIPE_SUCCEEDED = 'succeeded';

    public const STRIPE_CANCELED = 'canceled';

    protected $fillable = [
        'payment_id',
        'sequence',
        'payment_intent_id',
        'payment_method_id',
        'status',
        'confirmation_count',
        'last_initiator',
        'failure_code',
        'last_error',
        'created_at_stripe',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'confirmation_count' => 'integer',
            'last_initiator' => PaymentInitiator::class,
            'created_at_stripe' => 'datetime',
            'settled_at' => 'datetime',
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class, 'payment_id', 'id');
    }

    /**
     * Whether this intent can be confirmed again as-is.
     *
     * All three states are reusable and each for its own reason:
     * `requires_action` *must* be reused, because the customer's 3DS challenge
     * belongs to this intent and nothing else; `requires_confirmation` was
     * created but never confirmed; and `requires_payment_method` is what a
     * decline leaves behind — Stripe's own way of saying "try again on this
     * one", optionally with a different card.
     */
    public function isReusable(): bool
    {
        return in_array($this->status, [
            self::STRIPE_REQUIRES_ACTION,
            self::STRIPE_REQUIRES_CONFIRMATION,
            self::STRIPE_REQUIRES_PAYMENT_METHOD,
        ], true);
    }

    /**
     * Whether a retry has to abandon this intent and open a new one. Only a
     * cancelled intent qualifies — a declined one does not.
     */
    public function isSuperseded(): bool
    {
        return $this->status === self::STRIPE_CANCELED;
    }

    public function hasSucceeded(): bool
    {
        return $this->status === self::STRIPE_SUCCEEDED;
    }

    /**
     * The Stripe idempotency key for the next confirmation of this intent.
     *
     * Both the sequence and the confirmation count are in the key on purpose.
     * Keyed on the payment alone, Stripe would replay the first intent forever
     * and every retry would silently become a no-op that still reports
     * success.
     */
    public function idempotencyKeyForNextConfirmation(): string
    {
        return sprintf(
            '%s:%s:%d:%d',
            $this->payment_id,
            $this->payment?->purpose->value ?? 'unknown',
            $this->sequence,
            $this->confirmation_count + 1,
        );
    }
}
