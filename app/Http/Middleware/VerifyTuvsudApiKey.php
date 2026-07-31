<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the public (no Sanctum) TÜV SÜD webhook routes. Fails closed: if
 * the expected key isn't configured at all, every request is rejected —
 * it never falls back to a key baked into source control. Comparison uses
 * hash_equals() to avoid leaking the key via a timing side channel.
 */
class VerifyTuvsudApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = config('services.tuvsud.api_key');

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
