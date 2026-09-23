<?php

/**
 * LeasyBack Partner API — webhook signature verification (PHP).
 *
 * Framework-agnostic and dependency-free. Copy this file into your project, or
 * copy the function body into wherever your webhook route lives.
 *
 * This exact file is executed by LeasyBack's test suite against a body built by
 * the real deliverer, so what you are reading is what verifies our traffic —
 * not an illustration of it.
 *
 * The four steps, in order. Step 1 is the one people skip, and it is the one
 * that makes a captured request unreplayable.
 *
 *   1. reject a timestamp further than the tolerance from your clock;
 *   2. HMAC-SHA256 over "{timestamp}.{raw body}" with your signing secret;
 *   3. constant-time compare against the v1= value;
 *   4. drop event ids you have already processed.
 *
 * The body must be the RAW bytes as received. Re-serialising the parsed JSON
 * will not match: key order and unicode escaping are ours, not yours.
 */

/**
 * @param  string  $secret  the subscription's signing secret (whsec_…)
 * @param  string  $signatureHeader  X-LeasyBack-Signature, e.g. "v1=ab12…"
 * @param  string  $timestampHeader  X-LeasyBack-Timestamp, Unix seconds
 * @param  string  $rawBody  the raw request body, unparsed
 * @param  int  $toleranceSeconds  how far out of date a request may be
 * @param  int|null  $now  override the clock; leave null in production
 */
function leasyback_verify_webhook(
    string $secret,
    string $signatureHeader,
    string $timestampHeader,
    string $rawBody,
    int $toleranceSeconds = 300,
    ?int $now = null,
): bool {
    // 1. Replay window. The timestamp is inside the signed material, so an
    //    attacker cannot move it forward without breaking the signature —
    //    which is what makes this check worth doing.
    if ($timestampHeader === '' || ! ctype_digit($timestampHeader)) {
        return false;
    }

    $now ??= time();

    if (abs($now - (int) $timestampHeader) > $toleranceSeconds) {
        return false;
    }

    // 2. The signed payload is the timestamp, a literal dot, and the raw body.
    $expected = hash_hmac('sha256', $timestampHeader.'.'.$rawBody, $secret);

    // 3. Pull the v1 value out of the header. Splitting on "," and matching the
    //    scheme means a future second scheme sent alongside v1 does not break
    //    this code.
    foreach (explode(',', $signatureHeader) as $part) {
        $part = trim($part);

        if (! str_starts_with($part, 'v1=')) {
            continue;
        }

        if (hash_equals($expected, substr($part, 3))) {
            return true;
        }
    }

    return false;
}

/*
|--------------------------------------------------------------------------
| Usage — plain PHP
|--------------------------------------------------------------------------
|
| $raw = file_get_contents('php://input');
|
| $ok = leasyback_verify_webhook(
|     getenv('LEASYBACK_WEBHOOK_SECRET'),
|     $_SERVER['HTTP_X_LEASYBACK_SIGNATURE'] ?? '',
|     $_SERVER['HTTP_X_LEASYBACK_TIMESTAMP'] ?? '',
|     $raw,
| );
|
| if (! $ok) {
|     http_response_code(400);
|     exit;
| }
|
| $event = json_decode($raw, true);
|
| // 4. Deduplicate. The event id is stable across every retry and replay, so
| //    this is the only thing standing between you and processing the same
| //    event six times when your endpoint times out.
| if (already_processed($event['id'])) {
|     http_response_code(200);
|     exit;
| }
|
| handle($event);
| http_response_code(200);
|
|--------------------------------------------------------------------------
| Usage — Laravel
|--------------------------------------------------------------------------
|
| public function __invoke(Request $request): Response
| {
|     $ok = leasyback_verify_webhook(
|         config('services.leasyback.webhook_secret'),
|         $request->header('X-LeasyBack-Signature', ''),
|         $request->header('X-LeasyBack-Timestamp', ''),
|         $request->getContent(),
|     );
|
|     abort_unless($ok, 400);
|
|     // Answer fast, work later. We allow 10 seconds and retry on anything
|     // that is not a 2xx.
|     ProcessLeasybackEvent::dispatch($request->json()->all());
|
|     return response()->noContent();
| }
|
|--------------------------------------------------------------------------
| During a secret rotation
|--------------------------------------------------------------------------
|
| Rotating a secret keeps the previous one verifying for a grace window
| (`previous_secret_expires_at` on the subscription). Accept either while you
| deploy:
|
| $ok = leasyback_verify_webhook($newSecret, $sig, $ts, $raw)
|     || leasyback_verify_webhook($oldSecret, $sig, $ts, $raw);
|
*/
