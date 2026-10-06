<?php

namespace App\Support\LegacyImport;

/**
 * Builds `leasyback_orders.request_payload` and the logistics addresses for a
 * Base44 Auftrag, in the shape each V2 service stores (see OrderService:
 * b2b_collection, vehicle_relocation, vehicle_appraisal, accident_damage).
 * Every payload also carries a `legacy` block with the original values.
 */
final class OrderPayloads
{
    /**
     * @param  list<array<string, string>>  $history  this Auftrag's history rows
     * @param  list<array<string, mixed>>  $vehicles  snapshots for a Gutachten
     */
    public function __construct(private readonly array $history = [], private readonly array $vehicles = []) {}

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    public function payload(array $row, string $service, int $index = 0, int $count = 1): array
    {
        $legacy = $this->legacy($row, $index, $count);

        return match ($service) {
            'ueberfuehrung' => $this->relocation($row, $legacy),
            'gutachten' => $this->appraisal($row, $legacy),
            'unfallschaden' => $this->accident($row, $legacy),
            default => ['order_type' => 'b2b_collection', 'legacy' => $legacy],
        };
    }

    /**
     * @param  array<string, string>  $row
     * @return array{pickup: array<string, string>|null, delivery: array<string, string>|null}
     */
    public function logisticsAddresses(array $row, string $service): array
    {
        return match ($service) {
            'ueberfuehrung' => [
                'pickup' => $this->address($row['abholadresse_strasse'], $row['abholadresse_plz'], $row['abholadresse_ort'], $row['abholadresse_land']),
                'delivery' => $this->address($row['zieladresse_strasse'], $row['zieladresse_plz'], $row['zieladresse_ort'], $row['zieladresse_land']),
            ],
            'gutachten' => ['pickup' => $this->appraisalLocation($row), 'delivery' => null],
            'unfallschaden' => [
                'pickup' => $this->accidentLocation($row),
                'delivery' => $this->returnDiffers($row) ? $this->address($row['rueckfuehrort_strasse'], $row['rueckfuehrort_plz'], $row['rueckfuehrort_ort'], $row['rueckfuehrort_land']) : null,
            ],
            default => [
                'pickup' => $this->address($row['fahrzeugstandort_strasse'], $row['fahrzeugstandort_plz'], $row['fahrzeugstandort_ort'], $row['fahrzeugstandort_land']),
                'delivery' => $this->withName(
                    $this->address($row['rueckgabeort_strasse'], $row['rueckgabeort_plz'], $row['rueckgabeort_ort'], $row['rueckgabeort_land']),
                    $row['rueckgabeort_name'],
                ),
            ],
        };
    }

    /**
     * The inspection site of a Gutachten, for the logistics row.
     *
     * @param  array<string, string>  $row
     * @return array{name: string|null, address: string|null}
     */
    public function inspectionSite(array $row): array
    {
        $line = trim(implode(', ', array_filter([
            LegacyValue::text($row['gutachten_pruefstelle_strasse']),
            trim(implode(' ', array_filter([LegacyValue::text($row['gutachten_pruefstelle_plz']), LegacyValue::text($row['gutachten_pruefstelle_ort'])]))),
        ])));

        return ['name' => LegacyValue::text($row['gutachten_pruefstelle_name']), 'address' => $line === '' ? null : $line];
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private function relocation(array $row, array $legacy): array
    {
        $from = LegacyValue::text($row['zeitfenster_von']);
        $to = LegacyValue::text($row['zeitfenster_bis']);

        return $this->prune([
            'order_type' => 'vehicle_relocation',
            'pickup_address' => $this->address($row['abholadresse_strasse'], $row['abholadresse_plz'], $row['abholadresse_ort'], $row['abholadresse_land']),
            'destination_address' => $this->address($row['zieladresse_strasse'], $row['zieladresse_plz'], $row['zieladresse_ort'], $row['zieladresse_land']),
            'preferred_date' => LegacyValue::date($row['wunschtermin']),
            'time_from' => $from,
            'time_to' => $to,
            'time_slot' => ($from !== null && $to !== null) ? $from.'-'.$to : null,
            'pickup_contact' => $this->contact($row['ansprechpartner_abholung_name'], $row['ansprechpartner_abholung_email'], $row['ansprechpartner_abholung_telefon']),
            'destination_contact' => $this->contact($row['ansprechpartner_ziel_name'], $row['ansprechpartner_ziel_email'], $row['ansprechpartner_ziel_telefon']),
            'billing_address' => $this->billingAddress($row),
            'cost_centre' => $this->costCentre($row),
            'vehicle_ready' => LegacyValue::bool($row['fahrzeug_fahrbereit']),
            'notes' => $row['bemerkungen'],
            'legacy' => $legacy,
        ]);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private function appraisal(array $row, array $legacy): array
    {
        $from = LegacyValue::text($row['zeitfenster_von']);
        $to = LegacyValue::text($row['zeitfenster_bis']);
        $pickup = LegacyValue::bool($row['gutachten_abholung_gewuenscht']) === true;

        return $this->prune([
            'order_type' => 'vehicle_appraisal',
            'billing_address' => $this->billingAddress($row),
            'cost_centre' => $this->costCentre($row),
            'vehicle_location' => $this->appraisalLocation($row),
            'pickup_requested' => $pickup,
            'return_transport' => $pickup && LegacyValue::bool($row['gutachten_rueckfuehrung_gewuenscht']) === true,
            'leasing_company' => LegacyValue::text($row['leasinggeber_auftrag']),
            'preferred_date' => LegacyValue::date($row['wunschtermin']),
            'time_from' => $from,
            'time_to' => $to,
            'time_slot' => ($from !== null && $to !== null) ? $from.'-'.$to : null,
            'location_contact' => $this->contact($row['ansprechpartner_vor_ort_name'], $row['ansprechpartner_vor_ort_email'], $row['ansprechpartner_vor_ort_telefon']),
            'notes' => $row['bemerkungen'],
            'vehicles' => $this->vehicles,
            'legacy' => $legacy,
        ], keep: ['pickup_requested', 'return_transport']);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private function accident(array $row, array $legacy): array
    {
        $differs = $this->returnDiffers($row);

        return $this->prune([
            'order_type' => 'accident_damage',
            'billing_address' => $this->billingAddress($row),
            'cost_centre' => $this->costCentre($row),
            'vehicle_location' => $this->accidentLocation($row),
            'location_contact' => $this->contact($row['ansprechpartner_vor_ort_name'], $row['ansprechpartner_vor_ort_email'], $row['ansprechpartner_vor_ort_telefon']),
            'return_differs' => $differs,
            'return_address' => $differs ? $this->address($row['rueckfuehrort_strasse'], $row['rueckfuehrort_plz'], $row['rueckfuehrort_ort'], $row['rueckfuehrort_land']) : null,
            'return_contact' => $differs ? $this->contact($row['ansprechpartner_ziel_name'], $row['ansprechpartner_ziel_email'], $row['ansprechpartner_ziel_telefon']) : null,
            'notes' => $this->accidentNotes($row),
            'legacy' => $legacy,
        ], keep: ['return_differs']);
    }

    /**
     * Base44's accident facts have no field in the V2 form, so they are added
     * to the notes where staff will read them (and kept in `legacy`).
     *
     * @param  array<string, string>  $row
     */
    private function accidentNotes(array $row): ?string
    {
        $lines = [];

        foreach (['unfall_datum' => 'Unfalldatum', 'unfall_uhrzeit' => 'Uhrzeit', 'unfallhergang' => 'Unfallhergang', 'schadensbeschreibung' => 'Schaden', 'polizei_eingeschaltet' => 'Polizei eingeschaltet', 'polizei_aktenzeichen' => 'Aktenzeichen'] as $column => $label) {
            if (LegacyValue::text($row[$column]) !== null) {
                $lines[] = $label.': '.trim($row[$column]);
            }
        }

        $unfallort = trim(implode(', ', array_filter([LegacyValue::text($row['unfallort_strasse']), trim(implode(' ', array_filter([LegacyValue::text($row['unfallort_plz']), LegacyValue::text($row['unfallort_ort'])])))])));

        if ($unfallort !== '') {
            $lines[] = 'Unfallort: '.$unfallort;
        }

        return LegacyValue::text(implode("\n", array_filter([LegacyValue::text($row['bemerkungen']), $lines === [] ? null : implode("\n", $lines)], fn ($part) => $part !== null)));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function returnDiffers(array $row): bool
    {
        return LegacyValue::bool($row['rueckfuehrort_abweichend']) === true || LegacyValue::text($row['rueckfuehrort_strasse']) !== null;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>|null
     */
    private function appraisalLocation(array $row): ?array
    {
        return $this->address($row['gutachten_standort_strasse'], $row['gutachten_standort_plz'], $row['gutachten_standort_ort'], $row['fahrzeugstandort_land'])
            ?? $this->address($row['fahrzeugstandort_strasse'], $row['fahrzeugstandort_plz'], $row['fahrzeugstandort_ort'], $row['fahrzeugstandort_land']);
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>|null
     */
    private function accidentLocation(array $row): ?array
    {
        return $this->address($row['fahrzeugstandort_strasse'], $row['fahrzeugstandort_plz'], $row['fahrzeugstandort_ort'], $row['fahrzeugstandort_land'])
            ?? $this->address($row['unfallort_strasse'], $row['unfallort_plz'], $row['unfallort_ort'], $row['abholadresse_land']);
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function legacy(array $row, int $index, int $count): array
    {
        return $this->prune([
            'source' => 'base44',
            'auftrag_id' => $row['id'],
            'typ' => $row['typ'],
            'status' => $row['status'],
            'tracking_status' => $row['tracking_status'],
            'tracking_status_dates' => LegacyValue::json($row['tracking_status_dates']),
            'pausiert' => LegacyValue::bool($row['pausiert']),
            'ist_testauftrag' => LegacyValue::bool($row['ist_testauftrag']),
            'leasinggeber_auftrag' => $row['leasinggeber_auftrag'],
            'kostenstelle' => ['name' => $row['kostenstelle_name'], 'nummer' => $row['kostenstelle_nummer']],
            'rechnungsadresse' => [
                'name' => $row['rechnungsadresse_name'], 'strasse' => $row['rechnungsadresse_strasse'], 'plz' => $row['rechnungsadresse_plz'],
                'ort' => $row['rechnungsadresse_ort'], 'land' => $row['rechnungsadresse_land'],
            ],
            'ansprechpartner' => [
                'vor_ort' => ['name' => $row['ansprechpartner_vor_ort_name'], 'email' => $row['ansprechpartner_vor_ort_email'], 'telefon' => $row['ansprechpartner_vor_ort_telefon']],
                'abholung' => ['name' => $row['ansprechpartner_abholung_name'], 'email' => $row['ansprechpartner_abholung_email'], 'telefon' => $row['ansprechpartner_abholung_telefon']],
                'ziel' => ['name' => $row['ansprechpartner_ziel_name'], 'email' => $row['ansprechpartner_ziel_email'], 'telefon' => $row['ansprechpartner_ziel_telefon']],
            ],
            'adressen' => [
                'fahrzeugstandort' => $this->rawAddress($row, 'fahrzeugstandort'),
                'rueckgabeort' => $this->rawAddress($row, 'rueckgabeort') + ['name' => $row['rueckgabeort_name']],
                'abholadresse' => $this->rawAddress($row, 'abholadresse'),
                'zieladresse' => $this->rawAddress($row, 'zieladresse'),
            ],
            'wunschtermin' => $row['wunschtermin'],
            'zeitfenster' => ['von' => $row['zeitfenster_von'], 'bis' => $row['zeitfenster_bis']],
            'bemerkungen' => $row['bemerkungen'],
            'fahrzeug_fahrbereit' => LegacyValue::bool($row['fahrzeug_fahrbereit']),
            'gutachten_unfall' => $this->gutachtenAndAccidentFields($row),
            'history' => array_map(fn (array $h) => [
                'at' => $h['created_date'], 'von' => $h['alter_status'], 'nach' => $h['neuer_status'],
                'kommentar' => $h['kommentar'], 'durch' => $h['geaendert_von_email'],
            ], $this->history),
            'vehicle_legacy_ids' => LegacyValue::idList($row['fahrzeug_ids']),
            'split' => $count > 1 ? ['position' => $index + 1, 'of' => $count] : null,
        ]);
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function gutachtenAndAccidentFields(array $row): array
    {
        return array_filter($row, fn ($value, $column) => str_starts_with($column, 'gutachten_') || str_starts_with($column, 'unfall') || str_starts_with($column, 'polizei_') || str_starts_with($column, 'rueckfuehr') || in_array($column, ['schadensbeschreibung'], true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function rawAddress(array $row, string $prefix): array
    {
        return [
            'strasse' => $row[$prefix.'_strasse'], 'plz' => $row[$prefix.'_plz'],
            'ort' => $row[$prefix.'_ort'], 'land' => $row[$prefix.'_land'],
        ];
    }

    /**
     * @return array{street: string, number: string, zip_code: string, city: string, country: string}|null
     */
    public function address(mixed $street, mixed $zip, mixed $city, mixed $country): ?array
    {
        [$name, $number] = LegacyValue::splitStreet($street);

        if ($name === null && LegacyValue::text($zip) === null && LegacyValue::text($city) === null) {
            return null;
        }

        return [
            'street' => $name ?? '',
            'number' => $number ?? '',
            'zip_code' => LegacyValue::text($zip) ?? '',
            'city' => LegacyValue::text($city) ?? '',
            'country' => LegacyValue::text($country) ?? config('legacy_import.placeholders.country'),
        ];
    }

    /**
     * @param  array<string, string>|null  $address
     * @return array<string, string>|null
     */
    private function withName(?array $address, mixed $name): ?array
    {
        $name = LegacyValue::text($name);

        return ($address !== null && $name !== null) ? $address + ['additional_address' => $name] : $address;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>|null
     */
    public function billingAddress(array $row): ?array
    {
        $address = $this->address($row['rechnungsadresse_strasse'], $row['rechnungsadresse_plz'], $row['rechnungsadresse_ort'], $row['rechnungsadresse_land']);
        $name = LegacyValue::text($row['rechnungsadresse_name']);

        return $address === null && $name === null ? null : ['name' => $name ?? ''] + ($address ?? []);
    }

    /**
     * @param  array<string, string>  $row
     * @return array{name: string|null, number: string|null}
     */
    private function costCentre(array $row): array
    {
        return ['name' => LegacyValue::text($row['kostenstelle_name']), 'number' => LegacyValue::text($row['kostenstelle_nummer'])];
    }

    /**
     * @return array{name: string|null, phone: string|null, email: string|null}
     */
    private function contact(mixed $name, mixed $email, mixed $phone): array
    {
        return ['name' => LegacyValue::text($name), 'email' => LegacyValue::email($email), 'phone' => LegacyValue::phoneText($phone)];
    }

    /**
     * Recursively drop null, empty strings and empty arrays (booleans stay).
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keep  top-level keys that stay even when false
     * @return array<string, mixed>
     */
    private function prune(array $values, array $keep = []): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->prune($value);
            }

            if (in_array($key, $keep, true)) {
                continue;
            }

            if ($values[$key] === null || $values[$key] === '' || $values[$key] === []) {
                unset($values[$key]);
            }
        }

        return $values;
    }
}
