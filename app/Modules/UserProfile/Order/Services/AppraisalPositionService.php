<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The repair positions of an order's initial appraisal (§8). The whole set is
 * submitted at once and reconciled against what is stored: rows keep their id
 * across edits, new rows are inserted and omitted rows are deleted, all inside
 * one transaction.
 *
 * Positions are channel-agnostic. They were built for B2B and guarded on the
 * vehicle being B2B, but nothing in the model or this service is company-aware:
 * every row is scoped to one order, and "this panel is damaged and costs this
 * much to repair" is the same fact for a fleet car and a private one. The guard
 * was scope, not a business rule, so it is gone.
 *
 * What is genuinely channel-specific — the net-only presentation of §9, the
 * workshop quotation flow, billing — keeps its own guards where it lives.
 *
 * Positions are attached to the Admin order payload only; nothing here reaches
 * a customer response.
 */
class AppraisalPositionService
{
    /**
     * Values are entered by hand in both channels, for different reasons. A B2B
     * collection order is never `leasyback_partner = 'tuvsud'`, so the TÜV SÜD
     * pull does not apply to it at all; a B2C order is, but that pull ingests
     * documents only — PDFs and images, no amounts. Either way nothing
     * machine-readable reaches this table yet. `source` exists so a later
     * extractor can mark its rows and the UI can show which ones an admin has
     * since corrected.
     *
     * @param  array<int, string>  $allowedDocumentIds
     * @return array<string, mixed>
     */
    public static function rules(array $allowedDocumentIds): array
    {
        return [
            'positions' => ['present', 'array', 'max:200'],
            'positions.*.id' => ['nullable', 'uuid'],
            'positions.*.component' => ['required', 'string', 'max:255'],
            'positions.*.damage_description' => ['nullable', 'string', 'max:2000'],
            'positions.*.original_amount_net' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'positions.*.chargeable_amount_net' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'positions.*.repair_method' => ['nullable', 'string', 'max:255'],
            'positions.*.damage_image_document_ids' => ['nullable', 'array', 'max:50'],
            'positions.*.damage_image_document_ids.*' => ['uuid', Rule::in($allowedDocumentIds)],
        ];
    }

    /**
     * The report documents of this order, which are the only images a position
     * may reference — a document belonging to another order can never be
     * attached, whatever the request contains.
     *
     * Both columns are matched, not just `auftragsnummer`. VehicleReportService
     * takes the order number and the vehicle id as independent inputs and never
     * checks that they agree, so a document row *can* carry this order's number
     * against a different vehicle. Matching the vehicle too keeps such a row out
     * of a position's damage images, and costs one indexed column.
     *
     * @return array<int, string>
     */
    public function allowedDocumentIds(LeasybackOrder $order): array
    {
        return DB::table('vehicle_report_documents')
            ->where('auftragsnummer', $order->auftragsnummer)
            ->where('vehicle_id', $order->vehicle_id)
            ->pluck('id')
            ->all();
    }

    /**
     * Reconcile the order's positions against the submitted set, in one
     * transaction.
     *
     * Deliberately no lifecycle gate: a published offer freezes its own snapshot
     * (RepairOfferService::snapshotOnPublish), so a later correction here cannot
     * rewrite what a customer was shown, and an admin keeps being able to fix a
     * mistyped appraisal at any point in the case.
     *
     * @param  array<string, mixed>  $validated
     */
    public function sync(LeasybackOrder $order, User $user, array $validated): void
    {
        $submitted = array_values($validated['positions'] ?? []);

        DB::transaction(function () use ($order, $user, $submitted) {
            $existing = AppraisalPosition::where('order_id', $order->id)->get()->keyBy('id');
            $keptIds = [];

            foreach ($submitted as $index => $position) {
                $attributes = [
                    'order_id' => $order->id,
                    'auftragsnummer' => $order->auftragsnummer,
                    'sort_order' => $index,
                    'component' => trim((string) $position['component']),
                    'damage_description' => $this->trimToNull($position['damage_description'] ?? null),
                    'original_amount_net' => $position['original_amount_net'],
                    'chargeable_amount_net' => $this->amountOrNull($position['chargeable_amount_net'] ?? null),
                    'repair_method' => $this->trimToNull($position['repair_method'] ?? null),
                    'damage_image_document_ids' => $this->imageIds($position['damage_image_document_ids'] ?? null),
                    'updated_by_user_id' => $user->id,
                ];

                $current = isset($position['id']) ? $existing->get($position['id']) : null;

                if ($current !== null) {
                    $current->update($attributes);
                    $keptIds[] = $current->id;

                    continue;
                }

                $keptIds[] = AppraisalPosition::create([
                    ...$attributes,
                    'source' => AppraisalPosition::SOURCE_MANUAL,
                    'created_by_user_id' => $user->id,
                ])->id;
            }

            AppraisalPosition::where('order_id', $order->id)
                ->whereNotIn('id', $keptIds === [] ? [''] : $keptIds)
                ->delete();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forOrder(string $orderId): array
    {
        return AppraisalPosition::where('order_id', $orderId)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (AppraisalPosition $position) => [
                'id' => $position->id,
                'sort_order' => $position->sort_order,
                'component' => $position->component,
                'damage_description' => $position->damage_description,
                'original_amount_net' => $position->original_amount_net,
                'chargeable_amount_net' => $position->chargeable_amount_net,
                'effective_amount_net' => $position->effectiveAmountNet(),
                'repair_method' => $position->repair_method,
                'source' => $position->source,
                'damage_image_document_ids' => $position->damage_image_document_ids ?? [],
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $positions
     * @return array{count: int, original_total_net: string, chargeable_total_net: string}
     */
    public function totals(array $positions): array
    {
        $original = '0';
        $chargeable = '0';

        foreach ($positions as $position) {
            $original = bcadd($original, (string) $position['original_amount_net'], 2);
            $chargeable = bcadd($chargeable, (string) $position['effective_amount_net'], 2);
        }

        return [
            'count' => count($positions),
            'original_total_net' => $original,
            'chargeable_total_net' => $chargeable,
        ];
    }

    /**
     * @param  array<int, string>|null  $ids
     * @return array<int, string>|null
     */
    private function imageIds(?array $ids): ?array
    {
        $filtered = array_values(array_filter($ids ?? [], fn (mixed $id) => is_string($id) && $id !== ''));

        return $filtered === [] ? null : $filtered;
    }

    private function amountOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function trimToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return $value === null ? null : (string) $value;
        }

        return trim($value) === '' ? null : trim($value);
    }
}
