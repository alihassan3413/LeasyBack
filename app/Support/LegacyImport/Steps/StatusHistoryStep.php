<?php

namespace App\Support\LegacyImport\Steps;

use App\Support\LegacyImport\ImportContext;
use App\Support\LegacyImport\LegacyValue;
use Illuminate\Support\Facades\DB;

/**
 * AuftragStatushistorie → leasyback_order_status_updates.
 *
 * Labels with no V2 equivalent (Pausiert, the Gutachten statuses) and labels
 * that repeat the previous mapped status produce no row; they stay readable in
 * the order's `request_payload.legacy.history` and in the report. A history
 * comment becomes an internal order note.
 */
final class StatusHistoryStep extends AbstractStep
{
    public function name(): string
    {
        return 'history';
    }

    public function run(ImportContext $context): void
    {
        $byOrder = [];

        foreach ($context->export->rows('historie') as $entry) {
            $byOrder[$entry['auftrag_id']][] = $entry;
        }

        foreach ($byOrder as $auftragId => $entries) {
            usort($entries, fn (array $a, array $b) => [$a['created_date'], $a['id']] <=> [$b['created_date'], $b['id']]);

            $order = $context->map->find('auftrag', $auftragId);
            $resolvable = $order !== null && $order->status === 'imported';
            $previous = null;
            $labels = config('legacy_import.history_status_by_service')[$order?->payload['service_type'] ?? ''] ?? [];
            $labels += config('legacy_import.history_status');

            foreach ($entries as $entry) {
                $mapped = $labels[$entry['neuer_status']] ?? null;
                $skipReason = null;

                if ($mapped === null) {
                    $skipReason = 'no_v2_equivalent';
                } elseif ($mapped === $previous) {
                    $skipReason = 'collapsed_same_status';
                }

                $old = $previous;

                if ($skipReason === null) {
                    $previous = $mapped;
                }

                $hash = LegacyValue::hash($entry);

                if ($this->seenBefore($context, 'historie', $entry['id'], $hash)) {
                    continue;
                }

                if (! $resolvable) {
                    $reason = $order?->status === 'archived' ? 'parent_order_archived' : 'parent_order_not_imported';
                    $context->map->record('historie', $entry['id'], 'skipped', payload: ['reason' => $reason], hash: $hash);
                    $context->report->add('historie', $entry['id'], 'skipped', $reason);

                    continue;
                }

                DB::transaction(fn () => $this->import($context, $entry, $order->payload['auftragsnummer'], (string) $order->target_id, $old, $mapped, $skipReason, $hash));
            }
        }
    }

    /**
     * @param  array<string, string>  $entry
     */
    private function import(ImportContext $context, array $entry, string $auftragsnummer, string $orderId, ?string $old, ?string $new, ?string $skipReason, string $hash): void
    {
        $createdAt = LegacyValue::timestamp($entry['created_date']) ?? now()->format('Y-m-d H:i:s');
        $actor = LegacyValue::email($entry['geaendert_von_email']);
        $comment = LegacyValue::text($entry['kommentar']);

        if ($comment !== null) {
            DB::table('b2b_order_notes')->insert([
                'id' => $this->uuid(),
                'order_id' => $orderId,
                'auftragsnummer' => $auftragsnummer,
                'visibility' => 'internal',
                'body' => 'Statuskommentar (Base44, '.$entry['neuer_status'].'): '.$comment,
                'author_user_id' => $context->userIdForEmail($actor),
                'author_name' => $actor ?? 'Base44-Import',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        if ($skipReason !== null) {
            $context->map->record('historie', $entry['id'], 'skipped', payload: ['reason' => $skipReason, 'label' => $entry['neuer_status']], hash: $hash);
            $context->report->add('historie', $entry['id'], 'skipped', $skipReason, $entry['neuer_status']);

            return;
        }

        $statusUpdateId = $this->uuid();

        DB::table('leasyback_order_status_updates')->insert([
            'id' => $statusUpdateId,
            'auftragsnummer' => $auftragsnummer,
            'old_status' => $old,
            'new_status' => $new,
            'updated_by_user_id' => $context->userIdForEmail($actor),
            'updated_by' => $actor ?? 'base44-import',
            'auth_source' => 'base44_import',
            'created_at' => $createdAt,
        ]);

        $context->map->record('historie', $entry['id'], 'imported', 'leasyback_order_status_updates', $statusUpdateId, ['label' => $entry['neuer_status']], $hash);
        $context->report->add('historie', $entry['id'], 'imported', (string) $new);
    }
}
