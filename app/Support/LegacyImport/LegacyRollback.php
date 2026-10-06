<?php

namespace App\Support\LegacyImport;

use App\Models\LegacyImportMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Undoes one import batch, driven only by that batch's map rows: nothing the
 * importer did not create is ever touched. A company, vehicle or user that has
 * gained rows of its own since the import (anything not in the map) is left in
 * place and reported instead of cascading over live data.
 */
final class LegacyRollback
{
    /** Child-first order. */
    private const ORDER = [
        'dokument', 'historie', 'kommentar', 'auftrag_split', 'auftrag',
        'fahrzeug', 'membership', 'user', 'billing_address', 'cost_centre', 'kunde',
    ];

    /** Files are not transactional, so a dry run must never touch them. */
    private bool $dryRun = false;

    public function run(string $batchId, bool $dryRun): ImportReport
    {
        $this->dryRun = $dryRun;
        $report = new ImportReport;

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            foreach (self::ORDER as $entity) {
                $rows = LegacyImportMap::query()->where('batch_id', $batchId)->where('entity', $entity)->orderByDesc('id')->get();

                foreach ($rows as $row) {
                    DB::transaction(fn () => $this->undo($row, $report));
                }
            }

            LegacyImportMap::query()->where('batch_id', $batchId)->whereIn('status', ['archived', 'skipped', 'deduplicated', 'linked'])->delete();
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        return $report;
    }

    private function undo(LegacyImportMap $row, ImportReport $report): void
    {
        if ($row->status !== 'imported') {
            return;
        }

        $blocker = match ($row->entity) {
            'kunde' => $this->companyBlocker((string) $row->target_id),
            'fahrzeug' => $this->vehicleBlocker((string) $row->target_id),
            'auftrag', 'auftrag_split' => $this->orderBlocker((string) $row->target_id),
            'user' => $this->userBlocker((int) $row->target_id),
            default => null,
        };

        if ($blocker !== null) {
            $report->add($row->entity, $row->legacy_id, 'kept', $blocker);

            return;
        }

        match ($row->entity) {
            'dokument' => $this->deleteDocument($row),
            'historie' => DB::table('leasyback_order_status_updates')->where('id', $row->target_id)->delete(),
            'kommentar' => DB::table('order_messages')->where('id', $row->target_id)->delete(),
            'auftrag', 'auftrag_split' => $this->deleteOrder($row),
            'fahrzeug' => DB::table('vehicles')->where('vehicle_id', $row->target_id)->delete(),
            'membership' => $this->deleteMembership($row),
            'user' => DB::table('users')->where('id', $row->target_id)->delete(),
            'kunde' => $this->deleteCompany($row),
            'billing_address' => DB::table('company_billing_addresses')->where('id', $row->target_id)->delete(),
            'cost_centre' => DB::table('company_cost_centres')->where('id', $row->target_id)->delete(),
        };

        $row->delete();
        $report->add($row->entity, $row->legacy_id, 'rolled_back');
    }

    /*
     * Children are undone first (see ORDER), so anything still attached to a
     * parent at this point was not created by the import.
     */
    private function companyBlocker(string $b2bId): ?string
    {
        return (DB::table('vehicles')->where('b2b_id', $b2bId)->exists() || DB::table('user_b2b')->where('b2b_id', $b2bId)->exists())
            ? 'has_rows_not_created_by_import' : null;
    }

    private function vehicleBlocker(string $vehicleId): ?string
    {
        return DB::table('leasyback_orders')->where('vehicle_id', $vehicleId)->exists() ? 'has_orders_not_created_by_import' : null;
    }

    /**
     * An account that has signed in since the import (tokens, sessions, MFA) or
     * gained a membership of its own is live; it is kept.
     */
    private function userBlocker(int $userId): ?string
    {
        if (DB::table('user_b2b')->where('user_id', $userId)->exists()) {
            return 'has_memberships_not_created_by_import';
        }

        $signedIn = DB::table('personal_access_tokens')->where('tokenable_id', $userId)->exists()
            || DB::table('sessions')->where('user_id', $userId)->exists()
            || DB::table('users')->where('id', $userId)->whereNotNull('mfa_confirmed_at')->exists();

        return $signedIn ? 'has_signed_in_since_import' : null;
    }

    /**
     * Deleting an order cascades to everything attached to it, so an order that
     * has seen any activity since the import is kept. The import itself writes
     * no audit rows, offers, quotations, payments or billing, and its own
     * messages, history and files were already undone (children go first).
     */
    private function orderBlocker(string $orderId): ?string
    {
        $auftragsnummer = DB::table('leasyback_orders')->where('id', $orderId)->value('auftragsnummer');

        $active = DB::table('leasyback_order_audit_log')->where('order_id', $orderId)->exists()
            || DB::table('leasyback_offers')->where('order_id', $orderId)->exists()
            || DB::table('b2b_workshop_quotations')->where('order_id', $orderId)->exists()
            || DB::table('b2b_appraisal_positions')->where('order_id', $orderId)->exists()
            || DB::table('b2b_order_billing')->where('order_id', $orderId)->exists()
            || DB::table('order_payments')->where('order_id', $orderId)->exists()
            || DB::table('order_messages')->where('order_id', $orderId)->exists()
            || DB::table('leasyback_order_attachments')->where('order_id', $orderId)->exists()
            || DB::table('leasyback_order_status_updates')->where('auftragsnummer', $auftragsnummer)->exists()
            || DB::table('vehicle_report_documents')->where('auftragsnummer', $auftragsnummer)->exists();

        return $active ? 'has_activity_since_import' : null;
    }

    private function deleteDocument(LegacyImportMap $row): void
    {
        $table = $row->target_table === 'leasyback_order_attachments' ? 'leasyback_order_attachments' : 'vehicle_report_documents';
        $path = DB::table($table)->where('id', $row->target_id)->value('path');

        DB::table($table)->where('id', $row->target_id)->delete();

        if ($path !== null && ! $this->dryRun) {
            Storage::disk('documents')->delete($path);
        }
    }

    private function deleteOrder(LegacyImportMap $row): void
    {
        $reference = $row->payload['auftragsnummer'] ?? null;

        DB::table('leasyback_orders')->where('id', $row->target_id)->delete();

        if ($reference !== null) {
            DB::table('order_number_reservations')->where('reference', $reference)->delete();
        }
    }

    private function deleteMembership(LegacyImportMap $row): void
    {
        [$userId, $b2bId] = explode('|', (string) $row->target_id);

        DB::table('user_b2b')->where('user_id', $userId)->where('b2b_id', $b2bId)->delete();
        DB::table('users')->where('id', $userId)->where('active_b2b_id', $b2bId)->update(['active_b2b_id' => null]);
    }

    private function deleteCompany(LegacyImportMap $row): void
    {
        $payload = $row->payload ?? [];

        DB::table('b2b')->where('b2b_id', $row->target_id)->delete();

        if (isset($payload['contact_id'])) {
            DB::table('phone_numbers')->where('contact_id', $payload['contact_id'])->delete();
            DB::table('contacts')->where('contact_id', $payload['contact_id'])->delete();
        }

        if (isset($payload['address_id'])) {
            DB::table('addresses')->where('address_id', $payload['address_id'])->delete();
        }
    }
}
