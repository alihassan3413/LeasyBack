<?php

namespace App\Support\LegacyImport\Steps;

use App\Support\LegacyImport\ImportContext;
use App\Support\LegacyImport\LegacyValue;

/**
 * Datasets V2 has no home for. Leads and pending invitations are preserved in
 * legacy_import_map.payload (the invitations are the list to re-invite from
 * after go-live — nothing is sent here); notifications are derived data that V2
 * regenerates, so they are only reported.
 */
final class ArchiveStep extends AbstractStep
{
    public function name(): string
    {
        return 'archive';
    }

    public function run(ImportContext $context): void
    {
        foreach ($context->export->rows('lead') as $row) {
            $this->archive($context, 'lead', $row, 'no_v2_lead_model');
        }

        foreach ($context->export->rows('einladung') as $row) {
            $this->archive($context, 'einladung', $row, 'reinvite_after_cutover');
        }

        foreach ($context->export->rows('benachrichtigung') as $row) {
            $context->report->add('benachrichtigung', $row['id'], 'skipped', 'not_migrated_by_design');
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function archive(ImportContext $context, string $entity, array $row, string $reason): void
    {
        $hash = LegacyValue::hash($row);

        if ($this->seenBefore($context, $entity, $row['id'], $hash)) {
            return;
        }

        $context->map->record($entity, $row['id'], 'archived', payload: ['reason' => $reason, 'row' => $row], hash: $hash);
        $context->report->add($entity, $row['id'], 'archived', $reason);
    }
}
