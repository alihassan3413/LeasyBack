<?php

namespace App\Support\LegacyImport\Steps;

use App\Support\LegacyImport\ImportContext;
use App\Support\LegacyImport\LegacyValue;
use Illuminate\Support\Facades\DB;

/**
 * Fahrzeug → vehicles (B2B). Test ("DUMMY") vehicles are dropped unless an
 * order uses them; duplicate plates collapse onto one survivor, and every
 * dropped duplicate stays resolvable (map status `deduplicated`) so orders that
 * pointed at it land on the survivor.
 */
final class VehicleStep extends AbstractStep
{
    public function name(): string
    {
        return 'vehicles';
    }

    public function run(ImportContext $context): void
    {
        foreach (['import', 'deduplicated', 'skip'] as $action) {
            foreach ($context->plan->vehicleOutcome as $id => $outcome) {
                if ($outcome['action'] !== $action) {
                    continue;
                }

                $row = $context->plan->vehicles[$id];
                $hash = LegacyValue::hash($row);

                if ($this->seenBefore($context, 'fahrzeug', $id, $hash)) {
                    continue;
                }

                match ($action) {
                    'import' => DB::transaction(fn () => $this->import($context, $id, $row, $hash)),
                    'deduplicated' => $this->fold($context, $id, $outcome['winner'], $hash),
                    'skip' => $this->drop($context, $id, $row, $outcome['reason'], $hash),
                };
            }
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function import(ImportContext $context, string $id, array $row, string $hash): void
    {
        $b2bId = $context->map->target('kunde', $row['kunde_id']);

        if ($b2bId === null) {
            $this->drop($context, $id, $row, 'company_not_imported', $hash);

            return;
        }

        $plate = LegacyValue::plate($row['kennzeichen']);

        if (DB::table('vehicles')->where('license_plate', $plate)->exists()) {
            $this->drop($context, $id, $row, 'plate_exists_in_v2', $hash);

            return;
        }

        $notes = [];
        $vin = LegacyValue::vin($row['fahrgestellnummer_vin']);

        if ($vin !== null && ! LegacyValue::isValidVin($vin)) {
            $notes[] = 'vin_invalid_dropped';
            $originalVin = $vin;
            $vin = null;
        }

        $mileage = null;

        if (LegacyValue::text($row['kilometerstand']) !== null) {
            $km = (int) round((float) $row['kilometerstand']);
            $mileage = ($km >= 0 && $km <= 9_999_999) ? $km : null;

            if ($mileage === null) {
                $notes[] = 'mileage_out_of_range';
            }
        }

        $createdAt = LegacyValue::timestamp($row['created_date']) ?? now()->format('Y-m-d H:i:s');
        $vehicleId = $this->uuid();

        DB::table('vehicles')->insert([
            'vehicle_id' => $vehicleId,
            'license_plate' => $plate,
            'vin' => $vin,
            'make' => $this->manufacturer($row['hersteller']),
            'model' => LegacyValue::text($row['modell']),
            'first_registration_date' => LegacyValue::date($row['erstzulassung']),
            'leasing_end_date' => LegacyValue::date($row['rueckgabedatum']),
            'leasinggeber' => $this->lessor($row['leasinggeber']),
            'mileage' => $mileage,
            'b2b_id' => $b2bId,
            'b2c_user_id' => null,
            'vehicle_belongs' => 'B2B',
            'created_by_user_id' => $context->userIdForEmail($row['created_by'] ?? null),
            'created_at' => $createdAt,
            'updated_at' => LegacyValue::timestamp($row['updated_date']) ?? $createdAt,
        ]);

        $context->map->record('fahrzeug', $id, 'imported', 'vehicles', $vehicleId, $this->filled([
            'original_plate' => $row['kennzeichen'] !== $plate ? $row['kennzeichen'] : null,
            'original_vin' => $originalVin ?? null,
            'kraftstoffart' => LegacyValue::text($row['kraftstoffart']),
            'notizen' => LegacyValue::text($row['notizen']),
            'interne_fahrzeugnummer' => LegacyValue::text($row['interne_fahrzeugnummer']),
            'status' => LegacyValue::text($row['status']),
            'abgeschlossen' => LegacyValue::bool($row['abgeschlossen']),
            'standort_ort' => LegacyValue::text($row['standort_ort']),
            'standort_plz' => LegacyValue::text($row['standort_plz']),
            'hersteller' => $row['hersteller'],
            'leasinggeber' => LegacyValue::text($row['leasinggeber']),
            'order_referenced' => isset($context->plan->referencedVehicleIds[$id]) ? true : null,
        ]), $hash);

        $context->report->add('fahrzeug', $id, 'imported');

        foreach ($notes as $note) {
            $context->report->add('fahrzeug', $id, 'warning', $note);
        }
    }

    private function fold(ImportContext $context, string $id, ?string $winnerId, string $hash): void
    {
        $target = $winnerId === null ? null : $context->map->target('fahrzeug', $winnerId);

        if ($target === null) {
            $this->drop($context, $id, $context->plan->vehicles[$id], 'survivor_not_imported', $hash);

            return;
        }

        $context->map->record('fahrzeug', $id, 'deduplicated', 'vehicles', $target, ['survivor' => $winnerId], $hash);
        $context->report->add('fahrzeug', $id, 'deduplicated', 'duplicate_plate', 'survivor '.$winnerId);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function drop(ImportContext $context, string $id, array $row, string $reason, string $hash): void
    {
        $context->map->record('fahrzeug', $id, 'skipped', payload: ['reason' => $reason, 'kunde_id' => $row['kunde_id']], hash: $hash);
        $context->report->add('fahrzeug', $id, 'skipped', $reason);
    }

    private function manufacturer(string $raw): ?string
    {
        $value = LegacyValue::text($raw);

        if ($value === null) {
            return null;
        }

        $alias = config('legacy_import.manufacturer_aliases')[mb_strtolower($value)] ?? null;

        if ($alias !== null) {
            return $alias;
        }

        return ($value === mb_strtoupper($value) && mb_strlen($value) > 3) ? mb_convert_case($value, MB_CASE_TITLE) : $value;
    }

    private function lessor(string $raw): ?string
    {
        $value = LegacyValue::text($raw);

        if ($value === null || in_array(mb_strtolower($value), config('legacy_import.lessor_unknown_values'), true)) {
            return null;
        }

        return config('legacy_import.lessor_aliases')[mb_strtolower($value)] ?? $value;
    }
}
