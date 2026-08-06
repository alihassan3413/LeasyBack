<?php

namespace App\Modules\PartnerApi\Services;

use App\Modules\PartnerApi\Data\IdempotencyResult;
use App\Modules\PartnerApi\Enums\PartnerIdempotencyState;
use App\Modules\PartnerApi\Models\PartnerIdempotencyKey;
use App\Modules\PartnerApi\Models\PartnerIntegrationClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Claim, replay and release Idempotency-Keys.
 *
 * The claim is a database insert against a unique index, not a
 * read-then-write: two retries arriving at the same millisecond must not both
 * see "no record yet" and both create an order. The loser of that insert
 * re-reads the row and is answered "in progress" or replayed, exactly as if
 * it had arrived a second later.
 */
class PartnerIdempotencyService
{
    /**
     * Try to claim a key for this request.
     *
     * `$requestHash` must cover everything that makes the request distinct.
     * Conflict is deliberately loud: a partner reusing a key with a changed
     * payload has a bug, and quietly replaying the first response would hide
     * it behind data that never got written.
     */
    public function claim(
        PartnerIntegrationClient $client,
        string $key,
        string $endpoint,
        string $requestHash,
    ): IdempotencyResult {
        $this->pruneExpired($client);

        $existing = $this->find($client, $key);

        if ($existing !== null) {
            return $this->evaluate($existing, $endpoint, $requestHash);
        }

        $record = new PartnerIdempotencyKey([
            'idempotency_key' => $key,
            'endpoint' => $endpoint,
            'request_hash' => $requestHash,
            'status' => PartnerIdempotencyKey::STATUS_IN_PROGRESS,
            'locked_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addHours((int) config('partner_api.idempotency.ttl_hours')),
        ]);

        $record->partner_integration_client_id = $client->id;

        try {
            $record->save();
        } catch (QueryException $e) {
            // Lost the race to a concurrent retry: whatever that one wrote is
            // now the truth for this key.
            $winner = $this->find($client, $key);

            if ($winner === null) {
                throw $e;
            }

            return $this->evaluate($winner, $endpoint, $requestHash);
        }

        return new IdempotencyResult(PartnerIdempotencyState::Fresh, $record);
    }

    /**
     * Store the response so a later retry can be served it verbatim.
     *
     * @param  array<string, mixed>|null  $body
     */
    public function complete(PartnerIdempotencyKey $record, int $status, ?array $body): void
    {
        $record->forceFill([
            'status' => PartnerIdempotencyKey::STATUS_COMPLETED,
            'response_status' => $status,
            'response_body' => $body,
            'completed_at' => Carbon::now(),
            'locked_at' => null,
        ])->save();
    }

    /**
     * Drop a claim that produced no durable result.
     *
     * A failed request must leave the key reusable: the partner's retry is
     * the whole point, and a key stuck on a 500 would block it for the full
     * TTL.
     */
    public function release(PartnerIdempotencyKey $record): void
    {
        $record->delete();
    }

    private function find(PartnerIntegrationClient $client, string $key): ?PartnerIdempotencyKey
    {
        return PartnerIdempotencyKey::query()
            ->where('partner_integration_client_id', $client->id)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function evaluate(PartnerIdempotencyKey $record, string $endpoint, string $requestHash): IdempotencyResult
    {
        if ($record->endpoint !== $endpoint) {
            return new IdempotencyResult(
                PartnerIdempotencyState::Conflict,
                $record,
                'This Idempotency-Key was already used for a different endpoint.',
            );
        }

        if (! hash_equals($record->request_hash, $requestHash)) {
            return new IdempotencyResult(
                PartnerIdempotencyState::Conflict,
                $record,
                'This Idempotency-Key was already used with a different request payload.',
            );
        }

        if ($record->isCompleted()) {
            return new IdempotencyResult(PartnerIdempotencyState::Replay, $record);
        }

        if ($record->isLocked()) {
            return new IdempotencyResult(
                PartnerIdempotencyState::InProgress,
                $record,
                'The original request for this Idempotency-Key is still being processed.',
            );
        }

        // The original request died without finishing and its lock has aged
        // out. Take the key over rather than leaving the partner stuck.
        $record->forceFill(['locked_at' => Carbon::now()])->save();

        return new IdempotencyResult(PartnerIdempotencyState::Fresh, $record);
    }

    /**
     * Housekeeping on the claiming path, scoped to the one client doing the
     * work — cheap, indexed, and it keeps the table from needing a scheduled
     * sweeper that would be one more thing to forget to deploy.
     */
    private function pruneExpired(PartnerIntegrationClient $client): void
    {
        PartnerIdempotencyKey::query()
            ->where('partner_integration_client_id', $client->id)
            ->where('expires_at', '<', Carbon::now())
            ->delete();
    }
}
