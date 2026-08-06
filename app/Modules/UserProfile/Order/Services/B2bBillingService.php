<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\User;
use App\Models\Vehicle;
use App\Modules\PartnerApi\Services\PartnerWebhookEvents;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderBilling;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Minimal internal B2B billing (§13, §21).
 *
 * There is no Lexware integration and no payment provider in this codebase.
 * This exists for exactly one reason: §21 forbids completing an order before
 * its billing has been processed, and that rule needs a fact to check. It
 * records that fact — status, optional reference, optional invoice document,
 * and when and by whom it was marked processed.
 *
 * Extension point for the planned Stripe work: add the payment fields to
 * `b2b_order_billing` and new `billing_status` values when that phase starts.
 * The completion gate reads `isProcessed()`, so a payment-driven flow can
 * satisfy it without the gate itself changing.
 */
class B2bBillingService
{
    public function __construct(private readonly PartnerWebhookEvents $webhooks) {}

    /**
     * @param  array<int, string>  $allowedDocumentIds
     * @return array<string, mixed>
     */
    public static function rules(array $allowedDocumentIds): array
    {
        return [
            'invoice_reference' => ['nullable', 'string', 'max:100'],
            'invoice_document_id' => ['nullable', 'uuid', Rule::in($allowedDocumentIds)],
            'mark_processed' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Only this order's own report documents may be attached as the invoice.
     *
     * @return array<int, string>
     */
    public function allowedDocumentIds(LeasybackOrder $order): array
    {
        return DB::table('vehicle_report_documents')
            ->where('auftragsnummer', $order->auftragsnummer)
            ->pluck('id')
            ->all();
    }

    /**
     * Creates or updates the billing record. Marking processed is explicit and
     * one-way here — unmarking is deliberately not offered, because the
     * completion gate depends on it and silently reopening it would let a
     * completed order lose its justification.
     *
     * @param  array<string, mixed>  $validated
     */
    public function update(LeasybackOrder $order, Vehicle $vehicle, User $user, array $validated): void
    {
        if ($vehicle->vehicle_belongs !== 'B2B') {
            return;
        }

        $billing = OrderBilling::where('order_id', $order->id)->first();
        $markProcessed = (bool) ($validated['mark_processed'] ?? false);
        $alreadyProcessed = $billing?->isProcessed() ?? false;

        $attributes = [
            'auftragsnummer' => $order->auftragsnummer,
            'invoice_reference' => $this->trimToNull($validated['invoice_reference'] ?? null),
            'invoice_document_id' => $this->trimToNull($validated['invoice_document_id'] ?? null),
            'updated_by_user_id' => $user->id,
        ];

        if ($markProcessed && ! $alreadyProcessed) {
            $attributes['billing_status'] = OrderBilling::STATUS_PROCESSED;
            $attributes['processed_at'] = now();
            $attributes['processed_by_user_id'] = $user->id;
        }

        if ($billing === null) {
            OrderBilling::create([
                'order_id' => $order->id,
                'billing_status' => OrderBilling::STATUS_PENDING,
                'created_by_user_id' => $user->id,
                ...$attributes,
            ]);
        } else {
            $billing->update($attributes);
        }

        // Only on the transition into processed, and only once — `update()`
        // refuses to unmark, so this can never fire twice for one order. The
        // event carries the state, not the figures: no partner endpoint serves
        // billing amounts, and a webhook must not be a side door into data no
        // endpoint serves.
        if ($markProcessed && ! $alreadyProcessed) {
            $this->webhooks->billingCompleted($order->fresh() ?? $order, $vehicle);
        }
    }

    /**
     * The gate's single question. A missing record means not processed, so an
     * order with no billing at all can never complete.
     */
    public function isProcessed(string $orderId): bool
    {
        return OrderBilling::where('order_id', $orderId)->first()?->isProcessed() ?? false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forOrder(string $orderId): ?array
    {
        $billing = OrderBilling::where('order_id', $orderId)->first();

        if ($billing === null) {
            return [
                'billing_status' => OrderBilling::STATUS_PENDING,
                'invoice_reference' => null,
                'invoice_document_id' => null,
                'processed_at' => null,
                'is_processed' => false,
            ];
        }

        return [
            'billing_status' => $billing->billing_status,
            'invoice_reference' => $billing->invoice_reference,
            'invoice_document_id' => $billing->invoice_document_id,
            'processed_at' => $billing->processed_at?->toISOString(),
            'is_processed' => $billing->isProcessed(),
        ];
    }

    private function trimToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return $value === null ? null : (string) $value;
        }

        return trim($value) === '' ? null : trim($value);
    }
}
