<?php

namespace App\Support\LegacyImport;

use App\Models\LegacyImportMap;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compares the export with what the map and the database now hold. Every check
 * lands in the report as `pass` or `fail`; a single failure means the migration
 * is not reconciled.
 */
final class LegacyReconciler
{
    /** @var array<string, array{table: string, key: string}> */
    private const TARGETS = [
        'kunde' => ['table' => 'b2b', 'key' => 'b2b_id'],
        'user' => ['table' => 'users', 'key' => 'id'],
        'fahrzeug' => ['table' => 'vehicles', 'key' => 'vehicle_id'],
        'auftrag' => ['table' => 'leasyback_orders', 'key' => 'id'],
        'auftrag_split' => ['table' => 'leasyback_orders', 'key' => 'id'],
        'historie' => ['table' => 'leasyback_order_status_updates', 'key' => 'id'],
        'kommentar' => ['table' => 'order_messages', 'key' => 'id'],
        'dokument' => ['table' => 'vehicle_report_documents', 'key' => 'id'],
        'billing_address' => ['table' => 'company_billing_addresses', 'key' => 'id'],
        'cost_centre' => ['table' => 'company_cost_centres', 'key' => 'id'],
    ];

    public function run(LegacyExport $export): ImportReport
    {
        $report = new ImportReport;

        $this->coverage($report, 'kunde', array_column($export->rows('kunde'), 'id'));
        $this->coverage($report, 'user', array_filter(array_map(fn ($r) => LegacyValue::email($r['email']), $export->rows('users'))));
        $this->coverage($report, 'fahrzeug', array_column($export->rows('fahrzeug'), 'id'));
        $this->coverage($report, 'auftrag', array_column($export->rows('auftrag'), 'id'));
        $this->coverage($report, 'historie', array_column($export->rows('historie'), 'id'));
        $this->coverage($report, 'kommentar', array_column($export->rows('kommentar'), 'id'));
        $this->coverage($report, 'lead', array_column($export->rows('lead'), 'id'));
        $this->coverage($report, 'einladung', array_column($export->rows('einladung'), 'id'));

        foreach (self::TARGETS as $entity => $target) {
            $this->targetsExist($report, $entity, $target['table'], $target['key']);
        }

        $this->check($report, 'vehicles_duplicate_plates', 0, (int) DB::table('vehicles')->select('license_plate')->groupBy('license_plate')->havingRaw('COUNT(*) > 1')->get()->count());
        $this->check($report, 'orders_without_vehicle', 0, DB::table('leasyback_orders as o')->leftJoin('vehicles as v', 'v.vehicle_id', '=', 'o.vehicle_id')->whereNull('v.vehicle_id')->count());
        $this->check($report, 'imported_vehicles_without_company', 0, $this->importedVehicleRows()->whereNull('b2b_id')->count());
        $this->check($report, 'closed_orders_holding_a_vehicle_slot', 0, DB::table('leasyback_order_vehicles as ov')->join('leasyback_orders as o', 'o.id', '=', 'ov.order_id')->whereIn('o.order_status', ['completed', 'cancelled', 'discarded'])->whereNotNull('ov.active_vehicle_id')->count());
        $this->check($report, 'appraisal_orders_without_vehicle_links', 0, DB::table('leasyback_orders as o')->where('o.service_type', 'gutachten')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('leasyback_order_vehicles as ov')->whereColumn('ov.order_id', 'o.id'))->count());
        $this->check($report, 'imported_companies_with_users_but_no_owner', 0, $this->companiesWithoutOwner());

        return $report;
    }

    /**
     * @param  iterable<string>  $legacyIds
     */
    private function coverage(ImportReport $report, string $entity, iterable $legacyIds): void
    {
        $expected = count(array_unique(is_array($legacyIds) ? $legacyIds : iterator_to_array($legacyIds)));
        $mapped = LegacyImportMap::query()->where('entity', $entity)->count();

        $this->check($report, "{$entity}_every_source_row_has_a_map_row", $expected, $mapped);

        foreach (LegacyImportMap::query()->where('entity', $entity)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status') as $status => $n) {
            $report->add($entity, 'count', 'info', $status, (string) $n);
        }
    }

    private function targetsExist(ImportReport $report, string $entity, string $table, string $key): void
    {
        $missing = 0;

        $rows = LegacyImportMap::query()->where('entity', $entity)->where('status', 'imported')->get(['target_table', 'target_id']);

        foreach ($rows->groupBy(fn ($row) => $row->target_table ?? $table) as $targetTable => $group) {
            if (! Schema::hasTable($targetTable)) {
                $missing += $group->count();

                continue;
            }

            $group->pluck('target_id')->chunk(500)->each(function ($ids) use ($targetTable, $key, &$missing) {
                $missing += $ids->count() - DB::table($targetTable)->whereIn($key, $ids->all())->count();
            });
        }

        $this->check($report, "{$entity}_imported_targets_exist", 0, $missing);
    }

    private function importedVehicleRows(): Builder
    {
        return DB::table('vehicles')->whereIn('vehicle_id', LegacyImportMap::query()->where('entity', 'fahrzeug')->where('status', 'imported')->select('target_id'));
    }

    private function companiesWithoutOwner(): int
    {
        $companies = LegacyImportMap::query()->where('entity', 'kunde')->where('status', 'imported')->pluck('target_id');

        return $companies->filter(fn ($b2bId) => DB::table('user_b2b')->where('b2b_id', $b2bId)->exists()
            && ! DB::table('user_b2b')->where('b2b_id', $b2bId)->where('role', 'owner')->where('status', 'active')->exists())->count();
    }

    private function check(ImportReport $report, string $name, int $expected, int $actual): void
    {
        $ok = $actual === $expected;

        $report->add('check', $name, $ok ? 'pass' : 'fail', '', "expected {$expected}, got {$actual}");
    }
}
