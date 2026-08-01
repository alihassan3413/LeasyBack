<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the public (no Sanctum) DEKRA `receiveTerminbestaetigung` webhook —
 * previously protected only by rate-limiting, unlike the TÜV SÜD webhooks,
 * which require an API key (docs/B2C_ADMIN_MIGRATION_AUDIT.md §5's
 * DekraProcess row). Same fail-closed shared-secret pattern as
 * VerifyTuvsudApiKey: reject every request if the expected key isn't
 * configured at all (never fall back to a key baked into source control),
 * compare with hash_equals() to avoid a timing side channel.
 */
class VerifyDekraWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = config('services.dekra.webhook_key');

        if (empty($expectedKey)) {
            abort(503, 'This endpoint is not configured.');
        }

        $providedKey = $this->extractApiKey($request);

        if ($providedKey === null || ! hash_equals($expectedKey, $providedKey)) {
            abort(401, 'Invalid API Key');
        }

        return $next($request);
    }

    private function extractApiKey(Request $request): ?string
    {
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return $request->header('X-API-Key');
    }
}
