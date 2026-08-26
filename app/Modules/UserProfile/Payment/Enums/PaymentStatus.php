<?php

namespace App\Modules\UserProfile\Payment\Enums;

/**
 * The state of one logical charge (`order_payments.status`).
 *
 * Two of these are reached with no Stripe object in existence — `NotRequired`
 * for a repair that costs nothing, and `RequiresManualCollection` for a fee
 * owed by a customer who never stored a usable card. That is the reason the
 * state machine is keyed on the payment rather than on a PaymentIntent: an
 * intent-keyed writer cannot represent either case, and both still have to
 * notify the customer and satisfy (or deliberately not satisfy) the gate.
 */
enum PaymentStatus: string
{
    /** Created, nothing attempted yet. */
    case Pending = 'pending';

    /** Confirmed at Stripe and settling. Not yet money. */
    case Processing = 'processing';

    /** 3DS or equivalent needed. The customer must finish it themselves. */
    case RequiresAction = 'requires_action';

    /** The card was declined. Retryable on the same intent, never automatically. */
    case Failed = 'failed';

    /**
     * Owed, but no usable mandate exists to charge it against — so nothing was
     * ever sent to Stripe. Not a failure: the obligation stands and is chased
     * by hand. Only a cancellation fee reaches this in practice.
     */
    case RequiresManualCollection = 'requires_manual_collection';

    /** Settled. Money moved. */
    case Paid = 'paid';

    /** Nothing to pay — a repair that came to 0,00 €. */
    case NotRequired = 'not_required';

    /** Abandoned before settling, e.g. the order was cancelled mid-charge. */
    case Cancelled = 'cancelled';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * States that will not change again on their own.
     *
     * `RequiresManualCollection` is deliberately absent: it is waiting on a
     * person, not on Stripe, but it can still become `Paid` when they act.
     *
     * @return array<string>
     */
    public static function terminalValues(): array
    {
        return [
            self::Paid->value,
            self::NotRequired->value,
            self::Cancelled->value,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->value, self::terminalValues(), true);
    }

    /**
     * Whether this state settles a repair obligation for the purposes of the
     * `delivered → completed` gate.
     *
     * `NotRequired` counts. A car whose repair cost nothing must not be held
     * hostage to a payment that was never owed — the gate asks "is anything
     * outstanding", not "did we take money".
     */
    public function satisfiesReleaseGate(): bool
    {
        return $this === self::Paid || $this === self::NotRequired;
    }

    /**
     * Whether the customer still has something to do about this.
     *
     * Drives the pay button in the timeline and the dashboard banner.
     */
    public function needsCustomerAction(): bool
    {
        return $this === self::RequiresAction
            || $this === self::Failed
            || $this === self::RequiresManualCollection;
    }

    /**
     * Customer-facing German label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Zahlung ausstehend',
            self::Processing => 'Zahlung wird verarbeitet',
            self::RequiresAction => 'Bestätigung erforderlich',
            self::Failed => 'Zahlung fehlgeschlagen',
            self::RequiresManualCollection => 'Zahlung offen',
            self::Paid => 'Bezahlt',
            self::NotRequired => 'Keine Zahlung erforderlich',
            self::Cancelled => 'Zahlung storniert',
        };
    }
}
