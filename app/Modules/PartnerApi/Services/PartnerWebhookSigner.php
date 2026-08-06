<?php

namespace App\Modules\PartnerApi\Services;

use Illuminate\Support\Str;

/**
 * How a partner knows a request really came from us.
 *
 * The signed string is `{timestamp}.{raw body}`, HMAC-SHA256 with the
 * subscription's secret, hex encoded, sent as `v1=<hex>`.
 *
 * The timestamp is inside the signed material on purpose. Signing the body
 * alone would let anyone who captured one request replay it forever; because
 * the timestamp is covered, a replayer cannot move it forward without
 * invalidating the signature, and a partner who rejects timestamps older than
 * the tolerance window has a real replay defence rather than a decorative one.
 *
 * The `v1=` prefix is a version marker. If the scheme ever changes we send both
 * during the transition, and a partner splitting on `,` and matching on the
 * prefix does not need a coordinated deploy.
 *
 * Verification recipe, published to partners verbatim:
 *
 *   1. read X-LeasyBack-Timestamp; reject if it is more than
 *      `replay_tolerance_seconds` away from your clock
 *   2. compute hmac_sha256(secret, timestamp + "." + raw_request_body)
 *   3. compare, in constant time, against the `v1=` value in
 *      X-LeasyBack-Signature
 *   4. use X-LeasyBack-Event-ID to discard events you have already processed
 *
 * Step 2 says *raw* body. Re-serialising the parsed JSON will not match: key
 * order and unicode escaping are ours, not the partner's.
 */
class PartnerWebhookSigner
{
    public const HEADER_EVENT_ID = 'X-LeasyBack-Event-ID';

    public const HEADER_TIMESTAMP = 'X-LeasyBack-Timestamp';

    public const HEADER_SIGNATURE = 'X-LeasyBack-Signature';

    private const SCHEME = 'v1';

    private const ALGORITHM = 'sha256';

    /**
     * @return non-empty-string a 64-character secret, prefixed so a leaked one
     *                          is recognisable in a log or a secret scanner
     */
    public function generateSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(32));
    }

    public function sign(string $secret, string $timestamp, string $body): string
    {
        return self::SCHEME.'='.hash_hmac(self::ALGORITHM, $timestamp.'.'.$body, $secret);
    }

    /**
     * The three headers a signed request carries, ready to hand to the client.
     *
     * @return array<string, string>
     */
    public function headers(string $secret, string $eventId, string $timestamp, string $body): array
    {
        return [
            self::HEADER_EVENT_ID => $eventId,
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_SIGNATURE => $this->sign($secret, $timestamp, $body),
        ];
    }

    /**
     * Our own side of the recipe above, used by the tests and available to
     * anyone debugging a partner's failing check.
     *
     * @param  list<string>  $secrets  every secret currently valid for the
     *                                 subscription, so a rotation grace window verifies too
     */
    public function verify(array $secrets, string $signature, string $timestamp, string $body): bool
    {
        foreach ($secrets as $secret) {
            if (hash_equals($this->sign($secret, $timestamp, $body), $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `evt_` + 32 hex. Generated once, at emit, and carried unchanged through
     * every subscription, retry and replay — it is the partner's deduplication
     * key, so anything that varied per attempt would make it useless.
     */
    public function generateEventId(): string
    {
        return 'evt_'.Str::lower(bin2hex(random_bytes(16)));
    }
}
