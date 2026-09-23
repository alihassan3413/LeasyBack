<?php

namespace App\Modules\UserProfile\Payment\Support;

use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Services\PaymentMethodService;

/**
 * The payload both booking entry points flash after creating an order, so the
 * frontend knows which order to attach a card to and whether to ask at all.
 *
 * `requires_payment_method` is decided here rather than in the browser: it is
 * false for B2B, for an Admin booking on a customer's behalf, and when a usable
 * mandate already exists.
 */
class OrderCreatedFlash
{
    public function __construct(private readonly PaymentMethodService $paymentMethods) {}

    /**
     * @return array{order_id: string, auftragsnummer: string, requires_payment_method: bool}|null
     */
    public function for(?LeasybackOrder $order, User $actor): ?array
    {
        if ($order === null) {
            return null;
        }

        return [
            'order_id' => $order->id,
            'auftragsnummer' => (string) $order->auftragsnummer,
            'requires_payment_method' => $this->paymentMethods->requiresSetup($order, $actor),
        ];
    }
}
