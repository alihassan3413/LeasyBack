<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\User;
use App\Models\Vehicle;
use App\Modules\PartnerApi\Services\PartnerWebhookEvents;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderAuditLog;
use App\Modules\UserProfile\Order\Models\OrderBilling;
use App\Modules\UserProfile\Payment\Models\LexwareInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
    /** Billing is prepared once the vehicle is back with the leasing company. */
    public const EDITABLE_STATUSES = ['vehicle_returned', 'invoice_processed'];

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

        $status = LeasybackOrder::whereKey($order->id)->value('order_status');

        // Billing follows the return to the leasing company (§6, §13). Before
        // that there is nothing to bill; after completion the billing is the
        // record the order was closed on and must not change underneath it.
        if (! in_array($status, self::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'invoice_reference' => 'Die Abrechnung kann nur nach der Rückgabe an den Leasinggeber und vor dem Abschluss des Auftrags bearbeitet werden.',
            ]);
        }

        $becameProcessed = DB::transaction(function () use ($order, $user, $validated): bool {
            $billing = OrderBilling::where('order_id', $order->id)->lockForUpdate()->first();
            $markProcessed = (bool) ($validated['mark_processed'] ?? false);
            $alreadyProcessed = $billing?->isProcessed() ?? false;

            // Only the fields actually sent are written, so saving the reference
            // cannot wipe the attached document and vice versa.
            $reference = array_key_exists('invoice_reference', $validated)
                ? $this->trimToNull($validated['invoice_reference'])
                : $billing?->invoice_reference;
            $documentId = array_key_exists('invoice_document_id', $validated)
                ? $this->trimToNull($validated['invoice_document_id'])
                : $billing?->invoice_document_id;

            // "Processed" is what unlocks completion, so it has to point at an
            // actual invoice — and keep pointing at one once it is set.
            $hasLexwareDraft = LexwareInvoice::where('order_id', $order->id)
                ->where('purpose', B2bLexwareDraftService::PURPOSE)
                ->exists();

            if (($markProcessed || $alreadyProcessed) && $reference === null && $documentId === null && ! $hasLexwareDraft) {
                throw ValidationException::withMessages([
                    'invoice_reference' => 'Bitte erstellen Sie den Lexware-Rechnungsentwurf, geben Sie eine Rechnungsnummer an oder hängen Sie das Rechnungsdokument an, bevor die Abrechnung als verarbeitet gilt.',
                ]);
            }

            $attributes = [
                'auftragsnummer' => $order->auftragsnummer,
                'invoice_reference' => $reference,
                'invoice_document_id' => $documentId,
                'updated_by_user_id' => $user->id,
            ];

            if ($markProcessed && ! $alreadyProcessed) {
                $attributes['billing_status'] = OrderBilling::STATUS_PROCESSED;
                $attributes['processed_at'] = now();
                $attributes['processed_by_user_id'] = $user->id;
            }

            $old = $billing === null ? null : [
                'billing_status' => $billing->billing_status,
                'invoice_reference' => $billing->invoice_reference,
                'invoice_document_id' => $billing->invoice_document_id,
            ];

            if ($billing === null) {
                $billing = OrderBilling::create([
                    'order_id' => $order->id,
                    'billing_status' => OrderBilling::STATUS_PENDING,
                    'created_by_user_id' => $user->id,
                    ...$attributes,
                ]);
            } else {
                $billing->update($attributes);
            }

            $new = [
                'billing_status' => $billing->billing_status,
                'invoice_reference' => $billing->invoice_reference,
                'invoice_document_id' => $billing->invoice_document_id,
            ];

            // §19: billing is what gates completion, so every change to it is
            // part of the audit history. A save that changes nothing writes
            // nothing.
            if ($old != $new) {
                OrderAuditLog::create([
                    'order_id' => $order->id,
                    'vehicle_id' => $order->vehicle_id,
                    'action' => $markProcessed && ! $alreadyProcessed ? 'BILLING_PROCESSED' : 'BILLING_UPDATED',
                    'old_values' => $old,
                    'new_values' => $new,
                    'changed_by_user_id' => $user->id,
                ]);
            }

            return $markProcessed && ! $alreadyProcessed;
        });

        // Only on the transition into processed, and only once — `update()`
        // refuses to unmark, so this can never fire twice for one order. The
        // event carries the state, not the figures: no partner endpoint serves
        // billing amounts, and a webhook must not be a side door into data no
        // endpoint serves.
        if ($becameProcessed) {
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
        return $this->present(OrderBilling::where('order_id', $orderId)->first());
    }

    /**
     * Billing for many orders in one query, keyed by order id.
     *
     * An order with no billing row still gets the pending default, exactly as
     * forOrder() returns it — the caller must not have to tell "no row yet"
     * apart from "not loaded".
     *
     * @param  array<int, string>  $orderIds
     * @return array<string, array<string, mixed>>
     */
    public function forOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = OrderBilling::whereIn('order_id', $orderIds)->get()->keyBy('order_id');

        $billing = [];

        foreach ($orderIds as $orderId) {
            $billing[$orderId] = $this->present($rows->get($orderId));
        }

        return $billing;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(?OrderBilling $billing): array
    {
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
