<?php

namespace App\Modules\UserProfile\Order\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one place an `auftragsnummer` is minted, for every channel: B2C
 * inspections, B2B collection orders and the Partner API alike.
 *
 * The reference has always been registration-number + `ymd`, and it stays that
 * way for the first order of a day — every historical value is still exactly
 * what it was, and the string is still something a human reads aloud on the
 * phone. What is new is what happens the *second* time a vehicle starts a
 * return on the same calendar day: the reference gains a `-02`, `-03`, …
 * suffix instead of colliding at the unique index. `-` cannot occur in the
 * base (the plate is stripped of spaces and dashes first), so the separator is
 * unambiguous and a prefix search still finds every order of that vehicle-day.
 *
 * Concurrency is handled by claiming, not by hoping: the candidate is inserted
 * into `order_number_reservations`, whose unique index decides the race. The
 * loser rescans and takes the next number. There is no random component and no
 * silent fallback — running out of attempts raises, because a reference that
 * nobody can predict from the plate and the date would be a worse outcome than
 * a visible failure.
 *
 * Claiming happens *before* the order row exists on purpose. One creation path
 * (createTuvsudOrder) puts the reference into an outbound booking payload
 * before persisting, so "insert the order and retry on conflict" would mean
 * re-sending that booking. Reserving first keeps every channel on one code
 * path.
 */
class OrderNumberGenerator
{
    /**
     * Rescans after losing a race. Each loss means another process claimed the
     * number this one had picked, so the loop only spins as far as the number
     * of writers actually competing for one vehicle-day.
     */
    private const MAX_ATTEMPTS = 10;

    /** The first order of a vehicle-day keeps the bare, historical form. */
    private const FIRST_SEQUENCE = 1;

    /**
     * Claim the next free reference for this vehicle, today.
     *
     * The returned value is reserved for the caller: no other call can hand it
     * out again, whether or not the order is ultimately created.
     */
    public function reserve(string $licensePlate, ?string $vehicleId = null, ?int $userId = null, ?CarbonInterface $on = null): string
    {
        $base = $this->base($licensePlate, $on);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $sequence = $this->nextFreeSequence($base);
            $reference = $this->compose($base, $sequence);

            try {
                DB::table('order_number_reservations')->insert([
                    'reference' => $reference,
                    'reference_base' => $base,
                    'sequence' => $sequence,
                    'vehicle_id' => $vehicleId,
                    'reserved_by_user_id' => $userId,
                    'created_at' => now(),
                ]);

                return $reference;
            } catch (UniqueConstraintViolationException) {
                // Someone else claimed it between the scan and the insert.
                // Rescan; their row is now visible and we take the next one.
                continue;
            } catch (QueryException $e) {
                if (! $this->isDuplicateReference($e)) {
                    throw $e;
                }

                continue;
            }
        }

        throw new RuntimeException(
            "Could not allocate an order reference for base '{$base}' after "
            .self::MAX_ATTEMPTS.' attempts. This means sustained contention on one '
            .'vehicle and one day; no reference was issued.'
        );
    }

    /**
     * The reference a vehicle-day starts from — plate without spaces or
     * dashes, then the local date as `ymd`. Unchanged since the first order
     * this system ever wrote.
     */
    public function base(string $licensePlate, ?CarbonInterface $on = null): string
    {
        $cleaned = str_replace([' ', '-'], '', $licensePlate);

        return $cleaned.($on ?? now())->format('ymd');
    }

    /**
     * Every reference already issued for a base, in order.
     *
     * Public because the Partner API and the portal both want "which orders
     * belong to this vehicle-day" without re-deriving the suffix rule.
     *
     * @return list<string>
     */
    public function issuedFor(string $base): array
    {
        $sequences = $this->takenSequences($base);
        sort($sequences);

        return array_map(fn (int $sequence) => $this->compose($base, $sequence), $sequences);
    }

    public function compose(string $base, int $sequence): string
    {
        if ($sequence <= self::FIRST_SEQUENCE) {
            return $base;
        }

        return $base.'-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }

    private function nextFreeSequence(string $base): int
    {
        $taken = $this->takenSequences($base);

        if ($taken === []) {
            return self::FIRST_SEQUENCE;
        }

        return max($taken) + 1;
    }

    /**
     * Both halves are needed. `order_number_reservations` covers everything
     * issued since this generator existed; `leasyback_orders` covers every
     * order written before it, which has a reference but no reservation row.
     *
     * @return list<int>
     */
    private function takenSequences(string $base): array
    {
        $sequences = DB::table('order_number_reservations')
            ->where('reference_base', $base)
            ->pluck('sequence')
            ->map(fn ($sequence) => (int) $sequence)
            ->all();

        $existing = DB::table('leasyback_orders')
            ->where(function ($query) use ($base) {
                $query->where('auftragsnummer', $base)
                    ->orWhere('auftragsnummer', 'like', $this->likePrefix($base).'-%');
            })
            ->pluck('auftragsnummer')
            ->all();

        foreach ($existing as $reference) {
            $sequences[] = $this->sequenceOf($base, (string) $reference);
        }

        return array_values(array_unique(array_filter($sequences, fn (int $sequence) => $sequence > 0)));
    }

    /**
     * `-02` back to 2; the bare base back to 1. Anything that does not parse —
     * a reference some other system wrote by hand — is reported as 0 and
     * filtered out rather than crashing allocation.
     */
    private function sequenceOf(string $base, string $reference): int
    {
        if ($reference === $base) {
            return self::FIRST_SEQUENCE;
        }

        $suffix = substr($reference, strlen($base) + 1);

        return ctype_digit($suffix) ? (int) $suffix : 0;
    }

    /**
     * Plates are alphanumeric, but the base is still user-derived data going
     * into a LIKE pattern; escaping keeps a `%` from turning the prefix scan
     * into a table scan.
     */
    private function likePrefix(string $base): string
    {
        return addcslashes($base, '%_\\');
    }

    /**
     * Laravel raises UniqueConstraintViolationException on the drivers that
     * report one recognisably; this covers the rest by SQLSTATE.
     */
    private function isDuplicateReference(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true)
            && str_contains($e->getMessage(), 'reference');
    }
}
