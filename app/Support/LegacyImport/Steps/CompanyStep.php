<?php

namespace App\Support\LegacyImport\Steps;

use App\Support\LegacyImport\ImportContext;
use App\Support\LegacyImport\LegacyValue;
use Illuminate\Support\Facades\DB;

/**
 * Kunde → addresses + contacts + phone_numbers + b2b.
 *
 * Only relevant Kunden are imported (an active user, an order, a surviving
 * vehicle or a pending invitation). Saved billing-address and cost-centre
 * books have no V2 home and are archived in the map payload.
 */
final class CompanyStep extends AbstractStep
{
    public function name(): string
    {
        return 'companies';
    }

    public function run(ImportContext $context): void
    {
        $contactEmailCounts = array_count_values(array_filter(array_map(
            fn (array $k) => LegacyValue::email($k['kontaktperson_email']),
            $context->plan->kunden,
        )));

        foreach ($context->plan->kunden as $id => $row) {
            if (! isset($context->plan->relevantKunden[$id])) {
                $this->skip($context, $id, $context->plan->irrelevantKunden[$id]);

                continue;
            }

            $hash = LegacyValue::hash($row);

            if ($this->seenBefore($context, 'kunde', $id, $hash)) {
                continue;
            }

            DB::transaction(fn () => $this->import($context, $id, $row, $hash, $contactEmailCounts));
        }
    }

    private function skip(ImportContext $context, string $id, string $reason): void
    {
        if ($context->map->find('kunde', $id) === null) {
            $context->map->record('kunde', $id, 'skipped', payload: ['reason' => $reason]);
        }

        $context->report->add('kunde', $id, 'skipped', $reason);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, int>  $contactEmailCounts
     */
    private function import(ImportContext $context, string $id, array $row, string $hash, array $contactEmailCounts): void
    {
        $placeholders = config('legacy_import.placeholders');
        $createdAt = LegacyValue::timestamp($row['created_date']) ?? now()->format('Y-m-d H:i:s');
        $updatedAt = LegacyValue::timestamp($row['updated_date']) ?? $createdAt;
        $notes = [];

        [$street, $number] = LegacyValue::splitStreet($row['rechnungsadresse_strasse']);

        if ($street === null) {
            $notes[] = 'placeholder_address';
        } elseif ($number === null) {
            $notes[] = 'house_number_missing';
        }

        $name = LegacyValue::splitName($row['kontaktperson_name']);

        if ($name['last'] === null) {
            $notes[] = 'placeholder_contact';
        }

        $addressId = $this->uuid();
        DB::table('addresses')->insert([
            'address_id' => $addressId,
            'street' => $street ?? $placeholders['street'],
            'number' => $number ?? $placeholders['number'],
            'zip_code' => LegacyValue::text($row['rechnungsadresse_plz']) ?? $placeholders['zip_code'],
            'city' => LegacyValue::text($row['rechnungsadresse_ort']) ?? $placeholders['city'],
            'country' => LegacyValue::text($row['rechnungsadresse_land']) ?? $placeholders['country'],
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        $contactId = $this->uuid();
        DB::table('contacts')->insert([
            'contact_id' => $contactId,
            'address_id' => $addressId,
            'first_name' => $name['first'] ?? $placeholders['contact_first_name'],
            'last_name' => $name['last'] ?? $placeholders['contact_last_name'],
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        $rawPhone = LegacyValue::phoneText($row['kontaktperson_telefon']);

        if ($rawPhone !== null) {
            $phone = LegacyValue::phone($rawPhone);

            if ($phone === null) {
                $notes[] = 'phone_unparseable';
            } else {
                DB::table('phone_numbers')->insert([
                    'phone_id' => $this->uuid(),
                    'contact_id' => $contactId,
                    'international_prefix' => $phone['prefix'],
                    'phone_number' => $phone['number'],
                    'is_primary_contact' => true,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ]);
            }
        }

        $contactEmail = LegacyValue::email($row['kontaktperson_email']);
        $withheldContactEmail = null;

        // A LeasyBack address is never a company's operational contact: it
        // would route that company's notifications to staff. It is kept in the
        // map instead.
        if ($contactEmail !== null && $this->isInternalAddress($contactEmail)) {
            $withheldContactEmail = $contactEmail;
            $contactEmail = null;
            $notes[] = 'internal_contact_email_withheld';
        } elseif ($contactEmail !== null && ($contactEmailCounts[$contactEmail] ?? 0) > 1) {
            $notes[] = 'shared_contact_email';
        }

        $b2bId = $this->uuid();
        DB::table('b2b')->insert([
            'b2b_id' => $b2bId,
            'contact_id' => $contactId,
            'address_id' => $addressId,
            'company_name' => trim($row['firmenname']),
            'contact_email' => $contactEmail,
            'is_active' => LegacyValue::bool($row['aktiv']) ?? true,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);

        $context->map->record('kunde', $id, 'imported', 'b2b', $b2bId, $this->filled([
            'relevance' => $context->plan->relevantKunden[$id],
            'kundennummer' => LegacyValue::text($row['kundennummer']),
            'kontaktperson_telefon' => $rawPhone,
            'withheld_internal_contact_email' => $withheldContactEmail,
            'address_id' => $addressId,
            'contact_id' => $contactId,
            'archived_billing_addresses' => LegacyValue::json($row['gespeicherte_rechnungsadressen']),
            'archived_cost_centres' => LegacyValue::json($row['gespeicherte_kostenstellen']),
        ]), $hash);

        $context->report->add('kunde', $id, 'imported', $context->plan->relevantKunden[$id]);

        foreach ($notes as $note) {
            $context->report->add('kunde', $id, 'warning', $note);
        }
    }

    private function isInternalAddress(string $email): bool
    {
        return in_array(substr((string) strrchr($email, '@'), 1), config('legacy_import.staff_email_domains'), true);
    }
}
