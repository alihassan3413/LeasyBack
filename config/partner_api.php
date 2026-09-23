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
        //
        // Normalised to ?int here rather than left raw. A `.env` line reading
        // `PARTNER_API_TOKEN_EXPIRY_DAYS=` yields the *string* `''`, which is
        // not null, survives `??`, and casts to 0 — issuing tokens that expire
        // the instant they are created. Blank, non-numeric and <= 0 all mean
        // "never".
        'default_expiry_days' => is_numeric($expiryDays = env('PARTNER_API_TOKEN_EXPIRY_DAYS'))
            && (int) $expiryDays > 0
                ? (int) $expiryDays
                : null,

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

    /*
    | Outbound webhooks.
    */
    'webhooks' => [

        // Sent as `api_version` in every envelope, and pinned to the URL
        // version of the read endpoints an event points a partner at. A
        // breaking change to a payload is a new value here, not a silent edit.
        'api_version' => 'v1',

        // Queue the fan-out and delivery jobs run on. Separate from the default
        // so a backlog of retries against one dead endpoint cannot starve the
        // application's own queued work.
        'queue' => env('PARTNER_API_WEBHOOK_QUEUE', 'webhooks'),

        // Per-request budget for one delivery attempt.
        'timeout_seconds' => (int) env('PARTNER_API_WEBHOOK_TIMEOUT', 10),
        'connect_timeout_seconds' => (int) env('PARTNER_API_WEBHOOK_CONNECT_TIMEOUT', 5),

        /*
        | Retry schedule, in seconds after the *previous* attempt. Six attempts
        | over roughly seven hours: fast enough that a thirty-second blip is
        | invisible to the partner, slow enough that a deploy window or a
        | certificate renewal does not exhaust the budget. Documented rather
        | than computed so a partner can be told exactly when to expect the
        | next call. Running off the end of this list exhausts the delivery.
        */
        'backoff_seconds' => [30, 120, 600, 3600, 21600],

        // Consecutive failed *deliveries* before the subscription is suspended.
        // Counts deliveries, not attempts, so one dead endpoint is measured in
        // events missed rather than in how hard we retried.
        'auto_disable_after_failures' => (int) env('PARTNER_API_WEBHOOK_AUTO_DISABLE_AFTER', 20),

        // How long a rotated secret keeps signing alongside the new one, so a
        // partner can deploy without a hard cutover. 0 cuts over immediately.
        'secret_rotation_grace_minutes' => (int) env('PARTNER_API_WEBHOOK_ROTATION_GRACE_MINUTES', 60),

        // How far out of date a request's timestamp may be before a partner
        // should refuse it as a replay. Documented here because it is part of
        // the published verification recipe, not because we enforce it.
        'replay_tolerance_seconds' => (int) env('PARTNER_API_WEBHOOK_REPLAY_TOLERANCE', 300),

        // Characters of a partner's response body kept per attempt.
        'response_excerpt_bytes' => 512,

        /*
        | SSRF policy for partner-supplied target URLs.
        |
        | `allow_insecure` exists so a developer can point a subscription at a
        | local http:// listener. It is refused outright when the application
        | environment is production, regardless of this value — see
        | PartnerWebhookUrlGuard.
        */
        'allow_insecure' => (bool) env('PARTNER_API_WEBHOOK_ALLOW_INSECURE', false),

        // Loopback and private ranges are refused unconditionally in
        // production. Off-production, this opens them for local testing.
        'allow_private_networks' => (bool) env('PARTNER_API_WEBHOOK_ALLOW_PRIVATE', false),

        // Ports a target may use. Anything else is a strong signal the URL is
        // aimed at an internal service rather than a public HTTPS endpoint.
        'allowed_ports' => [80, 443, 8443],
    ],

];
