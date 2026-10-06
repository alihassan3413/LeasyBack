<?php

namespace App\Support\LegacyImport\Steps;

use App\Enums\OrderStatus;
use App\Modules\UserProfile\Order\Services\OrderNumberGenerator;
use App\Support\LegacyImport\ImportContext;
use App\Support\LegacyImport\LegacyValue;
use App\Support\LegacyImport\OrderPayloads;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Auftrag → leasyback_orders (+ leasyback_order_logistics).
 *
 * Written straight to the tables: the order services mail, broadcast and emit
 * webhooks, and a status transition would re-run the billing, document and
 * appointment guards on history. Base44's own status is authoritative; every
 * original label is kept in `request_payload.legacy`.
 *
 * Vehicles: a Gutachten is one order for all its vehicles, listed in
 * leasyback_order_vehicles exactly as the booking form stores it. Every other
 * service holds one vehicle per order, so a multi-vehicle Auftrag of those
 * becomes one order per vehicle (as OrderService books them).
 *
 * A type V2 has no service for is archived untouched.
 */
final class OrderStep extends AbstractStep
{
    private const TIME_SLOTS = ['08:00-10:00', '10:00-12:00', '12:00-14:00', '14:00-16:00', '16:00-18:00'];

    /** @var array<string, list<array<string, string>>> */
    private array $historyByOrder = [];

    public function name(): string
    {
        return 'orders';
    }

    public function run(ImportContext $context): void
    {
        foreach ($context->export->rows('historie') as $entry) {
            $this->historyByOrder[$entry['auftrag_id']][] = $entry;
        }

        $serviceTypes = config('legacy_import.service_types');

        foreach ($context->export->rows('auftrag') as $row) {
            $id = $row['id'];
            $hash = LegacyValue::hash($row);

            if ($this->seenBefore($context, 'auftrag', $id, $hash)) {
                continue;
            }

            $service = $serviceTypes[$row['typ']] ?? null;

            if ($service === null) {
                $this->park($context, $id, $row, 'archived', 'service_type_not_supported_in_v2:'.$row['typ'], $hash);

                continue;
            }

            $vehicles = $this->resolveVehicles($context, $row);

            if ($vehicles === []) {
                $this->park($context, $id, $row, 'skipped', 'no_resolvable_vehicle', $hash);

                continue;
            }

            if ($service === 'gutachten') {
                DB::transaction(fn () => $this->import($context, $row, $service, $vehicles, 0, 1, $hash));

                continue;
            }

            foreach ($vehicles as $index => $vehicleId) {
                DB::transaction(fn () => $this->import($context, $row, $service, [$vehicleId], $index, count($vehicles), $hash));
            }
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function park(ImportContext $context, string $id, array $row, string $status, string $reason, string $hash): void
    {
        $context->map->record('auftrag', $id, $status, payload: ['reason' => $reason, 'row' => $row], hash: $hash);
        $context->report->add('auftrag', $id, $status, $reason);
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string> distinct V2 vehicle ids
     */
    private function resolveVehicles(ImportContext $context, array $row): array
    {
        $resolved = [];

        foreach (LegacyValue::idList($row['fahrzeug_ids']) as $legacyId) {
            $target = $context->map->target('fahrzeug', $legacyId);

            if ($target === null) {
                $context->report->add('auftrag', $row['id'], 'warning', 'vehicle_not_resolvable', $legacyId);

                continue;
            }

            $resolved[$target] = $target;
        }

        return array_values($resolved);
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $vehicleIds  the order's vehicles; the first is the one on the order row
     */
    private function import(ImportContext $context, array $row, string $service, array $vehicleIds, int $index, int $count, string $hash): void
    {
        $legacyId = $index === 0 ? $row['id'] : $row['id'].'#'.($index + 1);
        $entity = $index === 0 ? 'auftrag' : 'auftrag_split';

        if ($index > 0 && $context->map->find($entity, $legacyId) !== null) {
            return;
        }

        [$status, $adjustment] = $this->finalStatus($row, $service);
        $closed = in_array($status, OrderStatus::closedValues(), true);

        if (! $closed && $this->hasOpenOrder($vehicleIds)) {
            $context->map->record($entity, $legacyId, 'skipped', payload: ['reason' => 'vehicle_has_open_order', 'row' => $index === 0 ? $row : null], hash: $hash);
            $context->report->add('auftrag', $legacyId, 'skipped', 'vehicle_has_open_order');

            return;
        }

        $primary = $vehicleIds[0];
        $createdAt = LegacyValue::timestamp($row['created_date']) ?? now()->format('Y-m-d H:i:s');
        $plate = (string) DB::table('vehicles')->where('vehicle_id', $primary)->value('license_plate');
        $auftragsnummer = app(OrderNumberGenerator::class)->reserve($plate, $primary, null, Carbon::parse($createdAt));
        $orderId = $this->uuid();

        $payloads = new OrderPayloads(
            $this->historyByOrder[$row['id']] ?? [],
            $service === 'gutachten' ? $this->vehicleSnapshots($vehicleIds) : [],
        );

        DB::table('leasyback_orders')->insert([
            'id' => $orderId,
            'vehicle_id' => $primary,
            'active_vehicle_id' => $closed ? null : $primary,
            'auftragsnummer' => $auftragsnummer,
            'leasyback_partner' => 'leasyback',
            'order_status' => $status,
            'service_type' => $service,
            'request_payload' => $this->json($payloads->payload($row, $service, $index, $count)),
            'created_by_user_id' => $context->userIdForBase44Id($row['angelegt_von_nutzer_id']) ?? $context->userIdForEmail($row['created_by'] ?? null),
            'sent_at' => $createdAt,
            'created_at' => $createdAt,
        ]);

        if ($service === 'gutachten') {
            foreach ($vehicleIds as $position => $vehicleId) {
                DB::table('leasyback_order_vehicles')->insert([
                    'id' => $this->uuid(),
                    'order_id' => $orderId,
                    'vehicle_id' => $vehicleId,
                    'active_vehicle_id' => $closed ? null : $vehicleId,
                    'position' => $position,
                    'created_at' => $createdAt,
                ]);
            }
        }

        $this->insertLogistics($row, $service, $payloads, $auftragsnummer, $createdAt);

        $context->map->record($entity, $legacyId, 'imported', 'leasyback_orders', $orderId, [
            'auftragsnummer' => $auftragsnummer,
            'vehicle_id' => $primary,
            'vehicle_ids' => $vehicleIds,
            'service_type' => $service,
            'source_status' => $row['status'],
            'source_tracking_status' => $row['tracking_status'],
        ], $hash);

        $context->report->add('auftrag', $legacyId, 'imported', $status, $auftragsnummer);

        if ($adjustment !== null) {
            $context->report->add('auftrag', $legacyId, 'warning', $adjustment);
        }

        if ($count > 1) {
            $context->report->add('auftrag', $legacyId, 'warning', 'split_one_order_per_vehicle', ($index + 1).' of '.$count);
        }

        if (count($vehicleIds) > 1) {
            $context->report->add('auftrag', $legacyId, 'warning', 'multi_vehicle_order', count($vehicleIds).' vehicles');
        }
    }

    /**
     * Whether any of these vehicles already holds an open-order slot — on an
     * order row or on a multi-vehicle order.
     *
     * @param  list<string>  $vehicleIds
     */
    private function hasOpenOrder(array $vehicleIds): bool
    {
        return DB::table('leasyback_orders')->whereIn('active_vehicle_id', $vehicleIds)->exists()
            || DB::table('leasyback_order_vehicles')->whereIn('active_vehicle_id', $vehicleIds)->exists();
    }

    /**
     * The snapshot a Gutachten stores next to the live vehicle links.
     *
     * @param  list<string>  $vehicleIds
     * @return list<array<string, mixed>>
     */
    private function vehicleSnapshots(array $vehicleIds): array
    {
        $rows = DB::table('vehicles')->whereIn('vehicle_id', $vehicleIds)->get()->keyBy('vehicle_id');

        return array_map(fn (string $id) => [
            'vehicle_id' => $id,
            'license_plate' => $rows[$id]->license_plate,
            'make' => $rows[$id]->make,
            'model' => $rows[$id]->model,
            'vin' => $rows[$id]->vin,
            'leasing_end_date' => LegacyValue::date($rows[$id]->leasing_end_date),
        ], $vehicleIds);
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: string, 1: string|null} V2 status and an optional note
     */
    private function finalStatus(array $row, string $service): array
    {
        $note = null;
        $status = config('legacy_import.order_status')[$row['status']] ?? null;

        if ($row['status'] === 'In Bearbeitung') {
            $status = config('legacy_import.in_progress_status')[LegacyValue::text($row['tracking_status'])] ?? config('legacy_import.in_progress_fallback');
        } elseif ($status === null) {
            $status = config('legacy_import.in_progress_fallback');
            $note = 'unknown_source_status:'.$row['status'];
        }

        $allowed = config('legacy_import.short_path_statuses')[$service] ?? null;

        if ($allowed !== null && ! in_array($status, $allowed, true)) {
            $status = config('legacy_import.short_path_fallback');
            $note = 'status_adjusted_for_'.($service === 'ueberfuehrung' ? 'relocation' : $service);
        }

        return [$status, $note];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function insertLogistics(array $row, string $service, OrderPayloads $payloads, string $auftragsnummer, string $createdAt): void
    {
        $addresses = $payloads->logisticsAddresses($row, $service);
        $site = $service === 'gutachten' ? $payloads->inspectionSite($row) : ['name' => null, 'address' => null];

        $window = LegacyValue::text($row['zeitfenster_von']).'-'.LegacyValue::text($row['zeitfenster_bis']);
        $requestedDate = LegacyValue::date($row['wunschtermin']);
        $notes = LegacyValue::text($row['bemerkungen']);

        if ($addresses['pickup'] === null && $addresses['delivery'] === null && $requestedDate === null && $notes === null && $site['name'] === null && $site['address'] === null) {
            return;
        }

        $values = [
            'id' => $this->uuid(),
            'auftragsnummer' => $auftragsnummer,
            'pickup_details' => $addresses['pickup'] === null ? null : $this->json($addresses['pickup']),
            'delivery_details' => $addresses['delivery'] === null ? null : $this->json($addresses['delivery']),
            'delivery_same_as_pickup' => false,
            'pickup_notes' => $notes,
            'requested_collection_date' => $requestedDate,
            'requested_collection_time_slot' => in_array($window, self::TIME_SLOTS, true) ? $window : null,
            'created_by_user_id' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];

        if ($service === 'gutachten') {
            $values += ['inspection_site_name' => $site['name'], 'inspection_site_address' => $site['address']];
        }

        DB::table('leasyback_order_logistics')->insert($values);
    }
}
