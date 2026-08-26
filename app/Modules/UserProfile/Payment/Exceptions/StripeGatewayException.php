<?php

namespace App\Modules\UserProfile\Payment\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Anything that went wrong talking to Stripe, translated out of the SDK's own
 * exception hierarchy so no caller has to catch a `\Stripe\Exception\*` type.
 *
 * `$stripeCode` carries Stripe's machine-readable reason where there is one
 * (`card_declined`, `authentication_required`, `expired_card`), because that is
 * what decides whether a failure is worth retrying and what the customer is
 * told. `$isCardError` separates "this card did not work" — an ordinary,
 * expected outcome that belongs in the payment record — from "we could not
 * reach Stripe", which is an incident.
 */
class StripeGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $stripeCode = null,
        public readonly bool $isCardError = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function cardError(string $message, ?string $code, ?Throwable $previous = null): self
    {
        return new self($message, $code, true, $previous);
    }

    public static function apiError(string $message, ?Throwable $previous = null): self
    {
        return new self($message, null, false, $previous);
    }

    /**
     * Whether the customer can plausibly fix this themselves by paying again.
     * Drives whether they get a payment link or the failure becomes an Admin
     * problem.
     */
    public function isCustomerActionable(): bool
    {
        return $this->isCardError;
    }
}
