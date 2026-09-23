<?php

namespace Database\Factories;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderPayment>
 */
class OrderPaymentFactory extends Factory
{
    protected $model = OrderPayment::class;

    public function definition(): array
    {
        return [
            'order_id' => LeasybackOrder::factory(),
            'auftragsnummer' => 'AUF-'.fake()->unique()->numerify('########'),
            'purpose' => PaymentPurpose::Repair,
            'amount_cents' => 119000,
            'currency' => 'eur',
            'status' => PaymentStatus::Pending,
        ];
    }

    public function repair(): static
    {
        return $this->state(fn () => ['purpose' => PaymentPurpose::Repair]);
    }

    public function cancellationFee(): static
    {
        return $this->state(fn () => [
            'purpose' => PaymentPurpose::CancellationFee,
            'amount_cents' => (int) config('payments.cancellation_fee_cents'),
        ]);
    }

    public function withStatus(PaymentStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Paid,
            'notified_status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
        ]);
    }

    /**
     * A repair that came to nothing. Reached with no Stripe object at all, so
     * a test using this state must not also create an intent.
     */
    public function notRequired(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::NotRequired,
            'notified_status' => PaymentStatus::NotRequired->value,
            'amount_cents' => 0,
        ]);
    }

    /**
     * A fee owed with no usable mandate behind it — also no Stripe object.
     */
    public function requiresManualCollection(): static
    {
        return $this->cancellationFee()->state(fn () => [
            'status' => PaymentStatus::RequiresManualCollection,
            'notified_status' => PaymentStatus::RequiresManualCollection->value,
        ]);
    }

    /**
     * Already at the automatic-retry cap, so only a person may confirm again.
     */
    public function atAutoConfirmationCap(): static
    {
        return $this->state(fn () => [
            'auto_confirmation_count' => (int) config('payments.max_auto_confirmations'),
        ]);
    }
}
