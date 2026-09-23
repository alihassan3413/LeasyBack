<?php

namespace App\Modules\UserProfile\Payment\Services;

use App\Models\LeasybackOrder as OrderRecord;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderAuditLog;
use App\Modules\UserProfile\Payment\Enums\FeeReason;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Enums\PaymentStatus;
use App\Modules\UserProfile\Payment\Jobs\ChargeCancellationFee;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Models\OrderPaymentMethod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only place a B2C €200 fee is ever created or charged.
 *
 * Every business trigger calls trigger(); none of them talks to Stripe. At most
 * one fee exists per case, guaranteed by UNIQUE(order_id, purpose) rather than
 * by any caller remembering to check.
 */
class B2cFeeService
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function trigger(LeasybackOrder $order, FeeReason $reason, array $context = []): ?OrderPayment
    {
        $record = $order instanceof OrderRecord ? $order : OrderRecord::find($order->id);

        if ($record === null || TransitionOrderStatus::isB2bOrder($record)) {
            return null;
        }

        $existing = $this->payments->paymentFor($record->id, PaymentPurpose::CancellationFee);

        if ($existing !== null) {
            $this->audit($record, $reason, $context, 'b2c_fee_trigger_ignored', $existing);

            return $existing;
        }

        [$fee, $isNew] = $this->open($record, $reason, $context);

        if (! $isNew) {
            return $fee;
        }

        $this->audit($record, $reason, $context, 'b2c_fee_triggered', $fee);

        if ($this->hasUsableMandate($record)) {
            ChargeCancellationFee::dispatch($fee->id);

            return $fee;
        }

        return $this->payments->transition($fee, PaymentStatus::RequiresManualCollection);
    }

    public function feeFor(LeasybackOrder $order): ?OrderPayment
    {
        return $this->payments->paymentFor($order->id, PaymentPurpose::CancellationFee);
    }

    public function isSettled(LeasybackOrder $order): bool
    {
        return $this->feeFor($order)?->status === PaymentStatus::Paid;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: OrderPayment, 1: bool}
     */
    private function open(LeasybackOrder $order, FeeReason $reason, array $context): array
    {
        $attributes = [
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'purpose' => PaymentPurpose::CancellationFee,
            'amount_cents' => (int) config('payments.cancellation_fee_cents'),
            'currency' => (string) config('services.stripe.currency', 'eur'),
        ];

        try {
            return [DB::transaction(function () use ($attributes, $reason, $context) {
                $fee = OrderPayment::create($attributes);
                $fee->forceFill([
                    'trigger_reason' => $reason,
                    'triggered_at' => now(),
                    'trigger_context' => $context,
                ])->save();

                return $fee->refresh();
            }), true];
        } catch (UniqueConstraintViolationException $e) {
            return [
                $this->payments->paymentFor($order->id, PaymentPurpose::CancellationFee) ?? throw $e,
                false,
            ];
        }
    }

    private function hasUsableMandate(LeasybackOrder $order): bool
    {
        return OrderPaymentMethod::where('order_id', $order->id)->first()?->isChargeableOffSession() === true;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(LeasybackOrder $order, FeeReason $reason, array $context, string $action, OrderPayment $fee): void
    {
        OrderAuditLog::create([
            'order_id' => $order->id,
            'vehicle_id' => $order->vehicle_id,
            'action' => $action,
            'new_values' => [
                'reason' => $reason->value,
                'amount_cents' => $fee->amount_cents,
                'payment_id' => $fee->id,
                'payment_status' => $fee->status->value,
                'order_status' => $order->order_status,
                'context' => $context,
            ],
            'changed_at' => now(),
        ]);
    }
}
