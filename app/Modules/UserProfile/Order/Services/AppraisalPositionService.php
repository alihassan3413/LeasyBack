<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\User;
use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * B2B-only repair positions of an order's initial appraisal (§8). The whole
 * set is submitted at once and reconciled against what is stored: rows keep
 * their id across edits, new rows are inserted and omitted rows are deleted,
 * all inside one transaction.
 *
 * Every write path is guarded on the order's persisted vehicle being B2B, and
 * positions are attached to the Admin order payload only — nothing here
 * reaches a customer response in this phase.
 */
class AppraisalPositionService
{
    /**
     * Values are entered by hand. The TÜV SÜD pull cannot supply them: it is
     * scoped to `leasyback_partner = 'tuvsud'` orders, which a B2B collection
     * order never is, and the TIM ingest stores documents only — no amounts.
     * `source` exists so a later extractor can mark its rows and the UI can
     * show which ones an admin has since corrected.
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
     * @param  array<string, mixed>  $validated
     */
    public function sync(LeasybackOrder $order, Vehicle $vehicle, User $user, array $validated): void
    {
        if ($vehicle->vehicle_belongs !== 'B2B') {
            return;
        }

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
