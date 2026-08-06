<?php

/*
|--------------------------------------------------------------------------
| Partner API
|--------------------------------------------------------------------------
|
| Configuration for the versioned partner integration API served under
| /api/v1/partner. Every value here is generic: nothing in this file names
| a specific partner, because a partner is a database row (an integration
| client), not a code branch.
|
*/

return [

    /*
    | The plaintext token format. Tokens are shown once at provisioning time
    | and stored only as a SHA-256 hash, so this prefix exists purely so a
    | leaked credential is recognisable in a log or a secret scanner.
    */
    'token' => [
        'prefix' => env('PARTNER_API_TOKEN_PREFIX', 'lbp'),

        // Bytes of randomness behind the prefix. 32 bytes -> 64 hex chars.
        'bytes' => 32,

        // Default lifetime for a newly issued token, in days. null = no
        // expiry, which is the intended default: these are long-lived
        // machine credentials rotated on demand, not session tokens.
        'default_expiry_days' => env('PARTNER_API_TOKEN_EXPIRY_DAYS'),

        // Grace period applied by `partner:token:rotate` before the previous
        // token is revoked, so a partner can deploy the new secret without a
        // hard cutover. 0 revokes immediately.
        'rotation_grace_minutes' => (int) env('PARTNER_API_TOKEN_ROTATION_GRACE_MINUTES', 0),
    ],

    /*
    | Requests per minute, per token. A client row may override this with its
    | own `rate_limit_per_minute`; this is the fallback.
    */
    'rate_limit' => [
        'per_minute' => (int) env('PARTNER_API_RATE_LIMIT_PER_MINUTE', 120),

        // Failed authentications tolerated per minute, per source IP, before
        // the source is refused outright. Separate from the per-token budget:
        // an unauthenticated request has no token to bill, and a credential
        // guessing attempt must be bounded without capping a legitimate
        // partner whose client override is higher than the default.
        'auth_failures_per_minute' => (int) env('PARTNER_API_AUTH_FAILURES_PER_MINUTE', 20),
    ],

    /*
    | Idempotency-Key support for future POST endpoints. A completed record is
    | replayed for this long; after that the same key is treated as new.
    */
    'idempotency' => [
        'header' => 'Idempotency-Key',
        'ttl_hours' => (int) env('PARTNER_API_IDEMPOTENCY_TTL_HOURS', 24),
        'max_key_length' => 255,

        // How long an in-flight record may stay locked before a retry is
        // allowed to take it over — protects against a crashed request
        // wedging a key forever.
        'lock_seconds' => 60,
    ],

    /*
    | Correlation id. An inbound value is echoed back so a partner can match
    | their log line to ours; anything malformed is replaced rather than
    | rejected, because a bad correlation id must never fail a real request.
    */
    'request_id' => [
        'header' => 'X-Request-ID',
        'max_length' => 128,
    ],

    /*
    | Ownership is never a request input on this API: the company and the
    | acting user come from the token, always. Any request carrying one of
    | these keys is refused outright rather than silently ignored, so a
    | partner learns immediately that the field is not honoured instead of
    | assuming it was applied.
    */
    'rejected_input_keys' => [
        'b2b_id',
        'b2c_user_id',
        'company_id',
        'created_by_user_id',
        'owner_id',
        'user_id',
    ],

];
