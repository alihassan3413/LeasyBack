<?php

namespace Database\Factories;

use App\Modules\UserProfile\Payment\Enums\PaymentInitiator;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentIntent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderPaymentIntent>
 */
class OrderPaymentIntentFactory extends Factory
{
    protected $model = OrderPaymentIntent::class;

    public function definition(): array
    {
        return [
            'payment_id' => OrderPayment::factory(),
            'sequence' => 1,
            'payment_intent_id' => 'pi_'.fake()->unique()->bothify('??##??##??##'),
            'payment_method_id' => 'pm_'.fake()->unique()->bothify('??##??##'),
            'status' => OrderPaymentIntent::STRIPE_REQUIRES_CONFIRMATION,
            'confirmation_count' => 0,
            'last_initiator' => PaymentInitiator::System,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => OrderPaymentIntent::STRIPE_SUCCEEDED,
            'confirmation_count' => 1,
            'settled_at' => now(),
        ]);
    }

    /**
     * A 3DS challenge is outstanding. This intent must be reused — the
     * challenge belongs to it and to nothing else.
     */
    public function requiresAction(): static
    {
        return $this->state(fn () => [
            'status' => OrderPaymentIntent::STRIPE_REQUIRES_ACTION,
            'confirmation_count' => 1,
        ]);
    }

    /**
     * What an off-session decline actually leaves behind: not `canceled`, but
     * `requires_payment_method` — Stripe's way of saying "confirm this one
     * again, optionally with a different card". Still reusable.
     */
    public function declined(string $failureCode = 'card_declined'): static
    {
        return $this->state(fn () => [
            'status' => OrderPaymentIntent::STRIPE_REQUIRES_PAYMENT_METHOD,
            'confirmation_count' => 1,
            'failure_code' => $failureCode,
            'last_error' => 'Your card was declined.',
        ]);
    }

    /**
     * The only state that genuinely forces a *new* intent on the next retry.
     */
    public function canceled(): static
    {
        return $this->state(fn () => [
            'status' => OrderPaymentIntent::STRIPE_CANCELED,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => OrderPaymentIntent::STRIPE_PROCESSING,
            'confirmation_count' => 1,
        ]);
    }
}
