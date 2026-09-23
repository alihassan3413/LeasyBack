<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Portal timestamps, serialised so the client cannot misread them.
 *
 * Storage is unchanged: the columns stay UTC, and this only decides how one is
 * written into a response. What it fixes is that the portal payloads used to
 * carry timestamps exactly as the database driver returned them —
 * `2026-08-26 10:05:28`, with no offset — and JavaScript reads a string in that
 * shape as *local* time. The portal therefore printed the stored UTC clock as
 * though it were the reader's: two hours early in a German summer, five in
 * Karachi, and Admin and customer quoting different times for one event.
 *
 * Every timestamp that reaches a timeline is stamped with its zone here, and
 * resources/js/lib/portalDate.ts renders it in Europe/Berlin. That file also
 * reads an offset-less value as UTC, so the two halves are independent: a
 * payload this class has not touched still displays correctly.
 *
 * Business *dates* — a requested collection day, a leasing end — are
 * deliberately not passed through here. They carry no instant, and turning one
 * into a timestamp only invites a zone to move it to the wrong day.
 */
final class PortalTimestamp
{
    public const TIME_ZONE = 'Europe/Berlin';

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIME_ZONE);
    }

    public static function instant(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone(self::TIME_ZONE);
        }

        $value = (string) $value;

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return CarbonImmutable::parse($value, self::TIME_ZONE);
            }

            return CarbonImmutable::parse($value)->setTimezone(self::TIME_ZONE);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * One timestamp as ISO-8601 with its offset, or null when there is none.
     */
    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            /*
             * Unparseable, which means it is not a timestamp at all. Handed
             * back untouched rather than nulled: a value this class cannot read
             * is not automatically one the client cannot.
             */
            return (string) $value;
        }
    }

    /**
     * The same over the named keys of a database row.
     *
     * Rows reach the portal as raw objects from the query builder, so this is
     * where a payload gets normalised without every call site restating the
     * conversion. Keys are named explicitly rather than detected, so a date
     * column can never be swept up by accident.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public static function normalizeRow(mixed $row, array $keys): array
    {
        $array = (array) $row;

        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                $array[$key] = self::iso($array[$key]);
            }
        }

        return $array;
    }

    /**
     * normalizeRow() over a list of rows.
     *
     * @param  iterable<int, mixed>  $rows
     * @param  array<int, string>  $keys
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeRows(iterable $rows, array $keys): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $normalized[] = self::normalizeRow($row, $keys);
        }

        return $normalized;
    }
}
