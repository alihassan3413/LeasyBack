<?php

namespace App\Support\LegacyImport\Steps;

use App\Support\LegacyImport\ImportContext;
use App\Support\LegacyImport\LegacyValue;
use Illuminate\Support\Facades\DB;

/**
 * Kunde.gespeicherte_rechnungsadressen / gespeicherte_kostenstellen →
 * company_billing_addresses / company_cost_centres.
 *
 * The lists also stay archived in the company's map payload. The first saved address
 * becomes the company default, as the booking form does for a company's first
 * one. Duplicate entries fold into one, like the form's firstOrCreate.
 */
final class BooksStep extends AbstractStep
{
    public function name(): string
    {
        return 'books';
    }

    public function run(ImportContext $context): void
    {
        foreach ($context->plan->relevantKunden as $kundeId => $reason) {
            $b2bId = $context->map->target('kunde', $kundeId);

            if ($b2bId === null) {
                continue;
            }

            $row = $context->plan->kunden[$kundeId];

            DB::transaction(function () use ($context, $kundeId, $b2bId, $row) {
                $this->addresses($context, $kundeId, $b2bId, $row);
                $this->costCentres($context, $kundeId, $b2bId, $row);
            });
        }
    }

    /**
     * @param  array<string, string>  $kunde
     */
    private function addresses(ImportContext $context, string $kundeId, string $b2bId, array $kunde): void
    {
        $entries = $this->entries($kunde['gespeicherte_rechnungsadressen']);
        $createdAt = LegacyValue::timestamp($kunde['created_date']) ?? now()->format('Y-m-d H:i:s');
        $seen = [];
        $hasDefault = DB::table('company_billing_addresses')->where('b2b_id', $b2bId)->where('is_default', true)->exists();

        foreach ($entries as $index => $entry) {
            $legacyId = $kundeId.'#'.($index + 1);
            $hash = LegacyValue::hash($entry);

            if ($this->seenBefore($context, 'billing_address', $legacyId, $hash)) {
                continue;
            }

            [$street, $number] = LegacyValue::splitStreet($entry['strasse'] ?? null);
            $details = [
                'street' => $street ?? '',
                'number' => $number ?? '',
                'zip_code' => LegacyValue::text($entry['plz'] ?? null) ?? '',
                'city' => LegacyValue::text($entry['ort'] ?? null) ?? '',
                'country' => LegacyValue::text($entry['land'] ?? null) ?? config('legacy_import.placeholders.country'),
            ];
            $name = LegacyValue::text($entry['name'] ?? null) ?? '';

            if ($details['street'] === '' && $details['zip_code'] === '' && $details['city'] === '') {
                $context->map->record('billing_address', $legacyId, 'skipped', payload: ['reason' => 'empty_entry'], hash: $hash);
                $context->report->add('billing_address', $legacyId, 'skipped', 'empty_entry');

                continue;
            }

            $key = LegacyValue::hash([$name, $details]);

            if (isset($seen[$key])) {
                $context->map->record('billing_address', $legacyId, 'deduplicated', 'company_billing_addresses', $seen[$key], null, $hash);
                $context->report->add('billing_address', $legacyId, 'deduplicated', 'duplicate_entry');

                continue;
            }

            $id = $this->uuid();
            DB::table('company_billing_addresses')->insert([
                'id' => $id,
                'b2b_id' => $b2bId,
                'name' => $name,
                'details' => $this->json($details),
                'is_default' => ! $hasDefault,
                'created_by_user_id' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $seen[$key] = $id;
            $hasDefault = true;

            $context->map->record('billing_address', $legacyId, 'imported', 'company_billing_addresses', $id, null, $hash);
            $context->report->add('billing_address', $legacyId, 'imported', $details['street'] === '' || $details['number'] === '' ? 'house_number_missing' : '');
        }
    }

    /**
     * @param  array<string, string>  $kunde
     */
    private function costCentres(ImportContext $context, string $kundeId, string $b2bId, array $kunde): void
    {
        $createdAt = LegacyValue::timestamp($kunde['created_date']) ?? now()->format('Y-m-d H:i:s');
        $seen = [];

        foreach ($this->entries($kunde['gespeicherte_kostenstellen']) as $index => $entry) {
            $legacyId = $kundeId.'#'.($index + 1);
            $hash = LegacyValue::hash($entry);

            if ($this->seenBefore($context, 'cost_centre', $legacyId, $hash)) {
                continue;
            }

            $name = LegacyValue::text($entry['name'] ?? null);
            $number = LegacyValue::text($entry['nummer'] ?? null);
            $note = null;

            if ($name === null && $number !== null) {
                $name = $number;
                $note = 'number_used_as_name';
            }

            if ($name === null) {
                $context->map->record('cost_centre', $legacyId, 'skipped', payload: ['reason' => 'empty_entry'], hash: $hash);
                $context->report->add('cost_centre', $legacyId, 'skipped', 'empty_entry');

                continue;
            }

            $key = LegacyValue::hash([$name, $number]);

            if (isset($seen[$key])) {
                $context->map->record('cost_centre', $legacyId, 'deduplicated', 'company_cost_centres', $seen[$key], null, $hash);
                $context->report->add('cost_centre', $legacyId, 'deduplicated', 'duplicate_entry');

                continue;
            }

            $id = $this->uuid();
            DB::table('company_cost_centres')->insert([
                'id' => $id,
                'b2b_id' => $b2bId,
                'name' => $name,
                'number' => $number,
                'created_by_user_id' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $seen[$key] = $id;

            $context->map->record('cost_centre', $legacyId, 'imported', 'company_cost_centres', $id, null, $hash);
            $context->report->add('cost_centre', $legacyId, 'imported', $note ?? '');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(string $json): array
    {
        $decoded = LegacyValue::json($json);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }
}
