<?php

namespace App\Modules\PartnerApi\Http\Middleware;

use App\Modules\PartnerApi\Services\PartnerContext;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-token rate limiting.
 *
 * Keyed on the token rather than the client or the IP: a partner rotating a
 * credential or running from a NAT pool must not inherit the previous
 * secret's consumed budget, and two partners behind the same egress IP must
 * not throttle each other. Unauthenticated requests fall back to the IP, so a
 * flood of invalid tokens is still bounded.
 *
 * Not Laravel's `throttle` middleware, because the limit is per-client
 * configurable (`partner_integration_clients.rate_limit_per_minute`) — a value
 * that only exists once the token has been resolved, which a named limiter
 * registered at boot cannot see.
 */
class ThrottlePartnerRequests
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly PartnerContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        [$key, $maxAttempts] = $this->resolveLimit($request);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);

            return PartnerApiResponse::error(
                PartnerApiResponse::TYPE_RATE_LIMIT,
                'rate_limit_exceeded',
                'Too many requests. Slow down and retry after the interval given in Retry-After.',
                429,
                details: ['retry_after_seconds' => $retryAfter],
                headers: [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $maxAttempts,
                    'X-RateLimit-Remaining' => '0',
                ],
            );
        }

        $this->limiter->hit($key, 60);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set(
            'X-RateLimit-Remaining',
            (string) max(0, $this->limiter->remaining($key, $maxAttempts)),
        );

        return $response;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveLimit(Request $request): array
    {
        if ($this->context->isEstablished()) {
            return [
                'partner-api:token:'.$this->context->token()->getKey(),
                $this->context->client()->rateLimitPerMinute(),
            ];
        }

        return [
            'partner-api:ip:'.sha1((string) $request->ip()),
            (int) config('partner_api.rate_limit.per_minute'),
        ];
    }
}
