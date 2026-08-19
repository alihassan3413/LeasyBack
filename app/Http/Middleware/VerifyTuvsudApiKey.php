<?php

namespace App\Http\Middleware;

use App\Support\PartnerLifecyclePermissions;
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

        // Who the presented secret proves this caller to be. The controller
        // reads the provider from here rather than assuming it, so the
        // lifecycle allow-list is keyed on an authenticated identity and never
        // on anything the request carried.
        $request->attributes->set(PartnerLifecyclePermissions::REQUEST_ATTRIBUTE, PartnerLifecyclePermissions::TUV_SUD);

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
