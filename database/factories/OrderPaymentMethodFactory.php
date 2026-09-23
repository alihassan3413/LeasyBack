<?php

namespace Database\Factories;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderPaymentMethod>
 */
class OrderPaymentMethodFactory extends Factory
{
    protected $model = OrderPaymentMethod::class;

    /**
     * Defaults to the state an order is created in — a row exists so "no
     * mandate" is never an ambiguous null, but nothing has been stored yet.
     */
    public function definition(): array
    {
        return [
            'order_id' => LeasybackOrder::factory(),
            'auftragsnummer' => 'AUF-'.fake()->unique()->numerify('########'),
            'stripe_customer_id' => 'cus_'.fake()->unique()->bothify('??##??##'),
            'status' => OrderPaymentMethod::STATUS_AWAITING_METHOD,
        ];
    }

    /**
     * The default state, named so a test can say which state it means rather
     * than relying on what the definition happens to default to.
     */
    public function awaitingMethod(): static
    {
        return $this->state(fn () => [
            'status' => OrderPaymentMethod::STATUS_AWAITING_METHOD,
            'payment_method_id' => null,
            'verified_at' => null,
            'offsession_authorized_at' => null,
        ]);
    }

    /**
     * A fully usable mandate: server-verified and authorized for off-session
     * charging. This is the only state `isChargeableOffSession()` accepts, so
     * it is what any test that expects a charge to happen needs.
     */
    public function saved(): static
    {
        return $this->state(fn () => [
            'status' => OrderPaymentMethod::STATUS_SAVED,
            'setup_intent_id' => 'seti_'.fake()->unique()->bothify('??##??##'),
            'payment_method_id' => 'pm_'.fake()->unique()->bothify('??##??##'),
            'pm_brand' => 'visa',
            'pm_last4' => '4242',
            'pm_exp_month' => 12,
            'pm_exp_year' => (int) date('Y') + 2,
            'verified_at' => now(),
            'offsession_authorized_at' => now(),
            'authorization_version' => config('payments.authorization_version'),
            'authorization_text_hash' => hash('sha256', 'test-authorization-text'),
            'authorized_ip' => '198.51.100.10',
            'authorized_user_agent' => 'PHPUnit',
        ]);
    }

    /**
     * A card that reached us but was never verified against Stripe — i.e. only
     * the browser ever claimed it succeeded. Must not be chargeable.
     */
    public function unverified(): static
    {
        return $this->saved()->state(fn () => [
            'verified_at' => null,
        ]);
    }

    /**
     * Verified, but with no recorded off-session mandate: a card we hold and
     * have no permission to use unattended.
     */
    public function withoutAuthorization(): static
    {
        return $this->saved()->state(fn () => [
            'offsession_authorized_at' => null,
            'authorization_version' => null,
            'authorization_text_hash' => null,
        ]);
    }

    /**
     * The customer accepted the cancellation-fee disclosure at booking. Set
     * independently of any card, because that is exactly the case it exists
     * for — the fee is owed by someone who never completed the card step.
     */
    public function feeAcknowledged(): static
    {
        return $this->state(fn () => [
            'fee_acknowledged_at' => now(),
            'fee_acknowledgement_version' => config('payments.fee_acknowledgement_version'),
        ]);
    }

    /**
     * What `payment_method.detached` leaves behind: back to needing a card.
     */
    public function detached(): static
    {
        return $this->saved()->state(fn () => [
            'status' => OrderPaymentMethod::STATUS_AWAITING_METHOD,
            'payment_method_id' => null,
            'pm_brand' => null,
            'pm_last4' => null,
            'pm_exp_month' => null,
            'pm_exp_year' => null,
            'verified_at' => null,
            'offsession_authorized_at' => null,
        ]);
    }
}
