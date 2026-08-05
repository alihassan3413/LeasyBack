<?php

namespace App\Modules\UserProfile\Vehicle\Services;

use App\Models\User;
use App\Modules\UserProfile\Vehicle\Support\VehicleRules;
use App\Support\SpreadsheetReader;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Bulk vehicle creation from an `.xlsx` or `.csv` fleet list (§5).
 *
 * §5's one hard rule shapes the whole class: *"The import must validate
 * required fields and show errors per row without discarding valid rows."*
 * That is a partial-success contract, so there is deliberately **no**
 * transaction around the loop. Each row is validated and written on its own,
 * inside the transaction VehicleService::createVehicle() already opens for
 * the vehicle plus its audit row. A row that fails — validation, a duplicate
 * registration number, anything — is recorded and the loop continues; every
 * row committed before it stays committed.
 *
 * Rows are also validated and written **sequentially**, not validated in bulk
 * and then written. `license_plate` carries a `unique:vehicles` rule, and if
 * the whole file were validated up front two identical plates inside the same
 * file would both pass — nothing would have been committed between the two
 * checks. Going row by row means the second one sees the first one's insert.
 *
 * Ownership never comes from the file. VehicleService resolves it from the
 * caller's active B2bContext membership, and every ownership/channel column
 * is stripped here before validation so there is nothing to resolve from.
 */
class VehicleImportService
{
    /**
     * Upper bound on rows read from one file. A fleet list is a few hundred
     * rows; anything past this is reported as truncated rather than silently
     * cut, so a partial import is never presented as a complete one.
     */
    public const MAX_ROWS = 2000;

    /** @var list<string> */
    public const ACCEPTED_EXTENSIONS = ['xlsx', 'csv'];

    /**
     * Columns that decide who a vehicle belongs to. Never mapped from a
     * heading and stripped from every row before validation — the channel and
     * the company come from the authenticated caller, never from the file.
     *
     * @var list<string>
     */
    private const OWNERSHIP_KEYS = [
        'vehicle_belongs',
        'b2b_id',
        'b2c_user_id',
        'created_by_user_id',
        'assigned_profile_id',
        'vehicle_id',
    ];

    /**
     * Canonical field => accepted headings, already normalised (lowercased,
     * umlauts folded, non-alphanumerics dropped).
     *
     * Ambiguous headings are deliberately absent. "Telefon" and "E-Mail" both
     * plausibly mean `driver_contact`, so neither is aliased: an unmapped
     * column is reported back to the user, which is honest, whereas guessing
     * would silently drop one of the two.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'license_plate' => ['kennzeichen', 'amtlicheskennzeichen', 'kfzkennzeichen', 'nummernschild', 'licenseplate', 'registrationnumber'],
        'vin' => ['fin', 'vin', 'fahrgestellnummer', 'fahrzeugidentifikationsnummer'],
        'make' => ['hersteller', 'marke', 'fabrikat', 'make', 'manufacturer'],
        'model' => ['modell', 'model', 'fahrzeugmodell'],
        'first_registration_date' => ['erstzulassung', 'erstzulassungsdatum', 'ez', 'firstregistration', 'firstregistrationdate'],
        'mileage' => ['kilometerstand', 'kmstand', 'laufleistung', 'mileage'],
        'leasinggeber' => ['leasinggeber', 'leasinggesellschaft', 'leasingcompany'],
        'contract_number' => ['vertragsnummer', 'vertragsnr', 'leasingvertragsnummer', 'contractnumber'],
        'leasing_end_date' => ['leasingende', 'leasingendedatum', 'vertragsende', 'leasingend', 'leasingenddate'],
        'cost_centre' => ['kostenstelle', 'costcentre', 'costcenter'],
        'driver_name' => ['fahrer', 'fahrername', 'driver', 'drivername', 'ansprechpartner'],
        'driver_contact' => ['kontakt', 'fahrerkontakt', 'kontaktdaten', 'drivercontact'],
        'collection_address.street' => ['strasse', 'street', 'abholadresse'],
        'collection_address.number' => ['hausnummer', 'hausnr', 'streetnumber'],
        'collection_address.additional_address' => ['adresszusatz', 'zusatz', 'additionaladdress'],
        'collection_address.zip_code' => ['plz', 'postleitzahl', 'zip', 'zipcode', 'postalcode'],
        'collection_address.city' => ['ort', 'stadt', 'city'],
        'collection_address.country' => ['land', 'country'],
    ];

    /** @var list<string> */
    private const DATE_FIELDS = ['first_registration_date', 'leasing_end_date'];

    public function __construct(private readonly VehicleService $vehicleService) {}

    /**
     * The heading row a downloadable template should carry, in order.
     *
     * @return list<string>
     */
    public static function templateHeadings(): array
    {
        return [
            'Kennzeichen', 'FIN', 'Hersteller', 'Modell', 'Erstzulassung',
            'Kilometerstand', 'Leasinggeber', 'Vertragsnummer', 'Leasingende',
            'Kostenstelle', 'Fahrer', 'Kontakt',
            'Strasse', 'Hausnummer', 'Adresszusatz', 'PLZ', 'Ort', 'Land',
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     imported: int,
     *     rejected: int,
     *     truncated: bool,
     *     ignored_columns: list<string>,
     *     errors: list<array{row: int, license_plate: ?string, messages: list<string>}>
     * }
     *
     * @throws ValidationException when the file itself is unusable
     */
    public function import(User $user, UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        $sheet = SpreadsheetReader::read($file->getRealPath(), $extension, self::MAX_ROWS + 1);

        $truncated = count($sheet['rows']) > self::MAX_ROWS;
        $rows = $truncated ? array_slice($sheet['rows'], 0, self::MAX_ROWS) : $sheet['rows'];

        [$map, $ignored] = $this->mapHeadings($sheet['headings']);

        if (! in_array('license_plate', $map, true)) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei enthält keine Spalte „Kennzeichen". Bitte verwenden Sie die Vorlage.',
            ]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'Die Datei enthält keine Fahrzeugzeilen.',
            ]);
        }

        $imported = 0;
        $errors = [];

        foreach ($rows as $row) {
            $payload = $this->buildPayload($map, $row['values']);
            $plate = is_string($payload['license_plate'] ?? null) ? $payload['license_plate'] : null;

            $messages = $this->persist($user, $payload);

            if ($messages === []) {
                $imported++;

                continue;
            }

            $errors[] = [
                'row' => $row['number'],
                'license_plate' => $plate,
                'messages' => $messages,
            ];
        }

        return [
            'total' => count($rows),
            'imported' => $imported,
            'rejected' => count($errors),
            'truncated' => $truncated,
            'ignored_columns' => $ignored,
            'errors' => $errors,
        ];
    }

    /**
     * Validate and write one row. Returns the row's error messages, or an
     * empty array when it was created.
     *
     * Every failure mode is contained here so the caller's loop cannot be
     * interrupted by one bad row.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function persist(User $user, array $payload): array
    {
        $validator = Validator::make(
            $payload,
            VehicleRules::forCreation(true),
            VehicleRules::messages(),
            VehicleRules::attributes(),
        );

        if ($validator->fails()) {
            return array_values($validator->errors()->all());
        }

        try {
            $this->vehicleService->createVehicle($user, $validator->validated());

            return [];
        } catch (QueryException $exception) {
            return [$this->describeQueryFailure($exception)];
        }
    }

    /**
     * A unique-constraint violation that slipped past the `unique` rule — two
     * identical plates racing, or a case the rule normalises differently.
     *
     * The message is deliberately generic. `vehicles_license_plate_unique` is
     * a **global** index, so the conflicting vehicle may belong to another
     * company or to a B2C customer, and saying so would disclose the existence
     * of a record outside the caller's company (§19).
     */
    private function describeQueryFailure(QueryException $exception): string
    {
        $message = strtolower($exception->getMessage());

        $isDuplicate = str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || $exception->getCode() === '23505';

        return $isDuplicate
            ? 'Dieses Kennzeichen ist bereits vergeben.'
            : 'Die Zeile konnte nicht gespeichert werden.';
    }

    /**
     * Column index => canonical field, plus the headings that matched nothing.
     *
     * @param  list<string>  $headings
     * @return array{0: array<int, string>, 1: list<string>}
     *
     * @throws ValidationException when two headings claim the same field
     */
    private function mapHeadings(array $headings): array
    {
        $map = [];
        $ignored = [];
        $claimed = [];

        foreach ($headings as $index => $heading) {
            $field = $this->matchHeading($heading);

            if ($field === null) {
                if (trim($heading) !== '') {
                    $ignored[] = $heading;
                }

                continue;
            }

            if (isset($claimed[$field])) {
                throw ValidationException::withMessages([
                    'file' => sprintf(
                        'Die Spalten „%s" und „%s" bezeichnen dasselbe Feld. Bitte entfernen Sie eine davon.',
                        $claimed[$field],
                        $heading,
                    ),
                ]);
            }

            $claimed[$field] = $heading;
            $map[$index] = $field;
        }

        return [$map, $ignored];
    }

    private function matchHeading(string $heading): ?string
    {
        $normalised = $this->normaliseHeading($heading);

        if ($normalised === '') {
            return null;
        }

        foreach (self::COLUMNS as $field => $aliases) {
            if (in_array($normalised, $aliases, true)) {
                return $field;
            }
        }

        return null;
    }

    private function normaliseHeading(string $heading): string
    {
        $folded = strtr(mb_strtolower(trim($heading)), [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        ]);

        return preg_replace('/[^a-z0-9]/', '', $folded) ?? '';
    }

    /**
     * Turn one spreadsheet row into a validatable payload.
     *
     * Only mapped columns contribute, and OWNERSHIP_KEYS are re-stripped at
     * the end even though no heading can produce one — the strip is the rule,
     * the alias table is just the first line of it.
     *
     * @param  array<int, string>  $map
     * @param  list<mixed>  $values
     * @return array<string, mixed>
     */
    private function buildPayload(array $map, array $values): array
    {
        $payload = [];
        $address = [];

        foreach ($map as $index => $field) {
            $raw = $values[$index] ?? null;

            if (str_starts_with($field, 'collection_address.')) {
                $key = substr($field, strlen('collection_address.'));
                $value = $this->text($raw);

                if ($value !== null) {
                    $address[$key] = $value;
                }

                continue;
            }

            $payload[$field] = match (true) {
                in_array($field, self::DATE_FIELDS, true) => $this->date($raw),
                $field === 'mileage' => $this->integer($raw),
                $field === 'license_plate' => $this->plate($raw),
                default => $this->text($raw),
            };
        }

        if ($address !== []) {
            $payload['collection_address'] = $address;
        }

        foreach (self::OWNERSHIP_KEYS as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    /**
     * Matches how the manual form stores a plate: uppercase, single-spaced.
     * `LicensePlateInput`/`normalizePlate` does exactly this before submitting,
     * so an imported plate and a hand-typed one are byte-identical — which is
     * also what lets the unique index catch a duplicate that differs only in
     * case or spacing.
     */
    private function plate(mixed $value): ?string
    {
        $text = $this->text($value);

        if ($text === null) {
            return null;
        }

        return mb_strtoupper((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function text(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        if (is_bool($value) || $value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Accepts "12345", "12.345", "12 345 km" and a numeric cell alike.
     */
    private function integer(mixed $value): int|string|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        $text = $this->text($value);

        if ($text === null) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $text) ?? '';

        // Not a number at all — hand the original back so the `integer` rule
        // reports it instead of silently importing a 0.
        return $digits === '' ? $text : (int) $digits;
    }

    /**
     * Normalises to `Y-m-d` before validation.
     *
     * This changes the accepted *format*, never the rule: the value still has
     * to satisfy `nullable|date`. Without it, a German `15.03.2022` cell would
     * depend on PHP's parser guessing right, and an xlsx date that lost its
     * cell format would arrive as the raw serial number.
     */
    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_int($value) || is_float($value)) {
            return $this->fromExcelSerial((float) $value);
        }

        $text = $this->text($value);

        if ($text === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $text) === 1) {
            return $this->fromExcelSerial((float) $text);
        }

        foreach (['d.m.Y', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $text);
            } catch (InvalidFormatException) {
                continue;
            }

            // Round-tripping rejects a value the parser silently repaired,
            // such as 32.01.2022 rolling over into February.
            if ($parsed !== false && $parsed->format($format) === $text) {
                return $parsed->format('Y-m-d');
            }
        }

        // Unrecognised — hand the original text to the `date` rule so the row
        // is rejected with a readable message rather than a wrong date.
        return $text;
    }

    /**
     * Excel's day zero is 1899-12-30 (the 1900 leap-year bug is baked in).
     */
    private function fromExcelSerial(float $serial): ?string
    {
        if ($serial <= 0 || $serial > 2958465) {
            return null;
        }

        return Carbon::create(1899, 12, 30)->addDays((int) $serial)->format('Y-m-d');
    }
}
