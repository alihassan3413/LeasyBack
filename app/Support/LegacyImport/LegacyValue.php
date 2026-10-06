<?php

namespace App\Support\LegacyImport;

use Carbon\Carbon;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Throwable;

/**
 * Pure value normalisers shared by the import steps. Nothing here touches the
 * database.
 */
final class LegacyValue
{
    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function email(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === null ? null : mb_strtolower($text);
    }

    public static function bool(mixed $value): ?bool
    {
        return match (mb_strtolower((string) self::text($value))) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    public static function json(mixed $value): mixed
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        $decoded = json_decode($text, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * Ids out of `fahrzeug_ids`, which Base44 stores as a JSON array in text.
     *
     * @return list<string>
     */
    public static function idList(mixed $value): array
    {
        $decoded = self::json($value);

        if (is_array($decoded)) {
            return array_values(array_filter(array_map(fn ($id) => self::text(is_scalar($id) ? $id : null), $decoded)));
        }

        preg_match_all('/[0-9a-f]{24}/', (string) $value, $matches);

        return $matches[0];
    }

    /** A calendar date (Y-m-d), whether the source holds a date or a timestamp. */
    public static function date(mixed $value): ?string
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        try {
            return Carbon::parse($text, 'UTC')->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /** A source timestamp as a `Y-m-d H:i:s` string in the application timezone. */
    public static function timestamp(mixed $value): ?string
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        try {
            return Carbon::parse($text, config('legacy_import.source_timezone', 'UTC'))
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /** Same rule as VehicleImportService::plate(): upper-case, whitespace collapsed. */
    public static function plate(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === null ? null : mb_strtoupper((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function vin(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === null ? null : mb_strtoupper($text);
    }

    public static function isValidVin(?string $vin): bool
    {
        return $vin !== null && preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin) === 1;
    }

    /**
     * "Beispielstraße 12-16" → ['Beispielstraße', '12-16']; no trailing
     * house number → [street, null].
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function splitStreet(mixed $value): array
    {
        $text = self::text($value);

        if ($text === null) {
            return [null, null];
        }

        if (preg_match('/^(.*\S)\s+(\d+(?:\s?[a-zA-Z])?(?:\s*[-\/]\s*\d+(?:\s?[a-zA-Z])?)?)$/u', $text, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [$text, null];
    }

    /**
     * @return array{first: string|null, last: string|null}
     */
    public static function splitName(mixed $value): array
    {
        $text = self::text($value);

        if ($text === null) {
            return ['first' => null, 'last' => null];
        }

        $parts = preg_split('/\s+/u', $text) ?: [$text];

        if (count($parts) === 1) {
            return ['first' => null, 'last' => $parts[0]];
        }

        $last = array_pop($parts);

        return ['first' => implode(' ', $parts), 'last' => $last];
    }

    /**
     * @return array{prefix: string, number: string}|null
     */
    public static function phone(mixed $value): ?array
    {
        $text = self::text(ltrim((string) $value, "'\" "));

        if ($text === null) {
            return null;
        }

        try {
            $util = PhoneNumberUtil::getInstance();
            $parsed = $util->parse($text, 'DE');

            if (! $util->isValidNumber($parsed)) {
                return null;
            }

            return [
                'prefix' => '+'.$parsed->getCountryCode(),
                'number' => $util->getNationalSignificantNumber($parsed),
            ];
        } catch (NumberParseException) {
            return null;
        }
    }

    /** Free text kept as-is, minus the leading apostrophe Excel adds to numbers. */
    public static function phoneText(mixed $value): ?string
    {
        return self::text(ltrim((string) $value, "'"));
    }

    public static function hash(mixed $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
