<?php

namespace App\Modules\UserProfile\Payment\Models;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Enums\FeeReason;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use Database\Factories\OrderPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One logical charge against an order — the repair total, or the cancellation
 * fee — and the unit the payment state machine works on.
 *
 * A charge may outlive several Stripe PaymentIntents (a declined card is
 * re-confirmed on the same one; a cancelled one is replaced), so the intents
 * are a separate, append-only table and this row is what has a single answer
 * to "is it paid".
 *
 * `status` is only ever written by PaymentService::transition(), which is also
 * the only emitter of payment mail — see `notified_status`.
 */
class OrderPayment extends Model
{
    use HasFactory;

    /**
     * Resolved explicitly: the model lives in a module namespace, so Laravel's
     * App\Models → Database\Factories convention cannot find it.
     */
    protected static function newFactory(): OrderPaymentFactory
    {
        return OrderPaymentFactory::new();
    }

    protected $table = 'order_payments';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * `status`, `notified_status`, `paid_at` and both counters are absent on
     * purpose: they are the state machine's business, and a caller that could
     * mass-assign them could move a payment to `paid` without a Stripe object
     * ever having succeeded.
     */
    protected $fillable = [
        'order_id',
        'auftragsnummer',
        'purpose',
        'amount_cents',
        'currency',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'status' => PaymentStatus::class,
            'trigger_reason' => FeeReason::class,
            'trigger_context' => 'array',
            'triggered_at' => 'datetime',
            'amount_cents' => 'integer',
            'intent_count' => 'integer',
            'auto_confirmation_count' => 'integer',
            'paid_at' => 'datetime',
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

    public function intents(): HasMany
    {
        return $this->hasMany(OrderPaymentIntent::class, 'payment_id', 'id');
    }

    /**
     * The intent a retry should act on: the most recent one.
     *
     * Derived from `sequence` rather than stored as a `current_intent_id`
     * column, which would need a foreign key back into a table that already
     * points here.
     */
    public function currentIntent(): ?OrderPaymentIntent
    {
        return $this->intents()->orderByDesc('sequence')->first();
    }

    /**
     * Whether another *automatic*, off-session confirmation is permitted.
     *
     * Customer- and Admin-initiated retries do not consult this: the cap
     * exists because unattended retries against a declining card attract
     * card-network penalties, which is not what a person clicking "try again"
     * is doing.
     */
    public function mayAutoConfirmAgain(): bool
    {
        return $this->auto_confirmation_count < (int) config('payments.max_auto_confirmations');
    }

    /**
     * Whether this charge, being for a repair, still blocks the vehicle's
     * release. Answers false for a cancellation fee regardless of its state.
     */
    public function blocksRelease(): bool
    {
        return $this->purpose->blocksVehicleRelease()
            && ! $this->status->satisfiesReleaseGate();
    }

    /**
     * The amount as a decimal string, for display and for German currency
     * formatting. The stored value is minor units because that is what Stripe
     * takes; everything a human reads goes through here.
     */
    public function amountDecimal(): string
    {
        return bcdiv((string) $this->amount_cents, '100', 2);
    }
}
