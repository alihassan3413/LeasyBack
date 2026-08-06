<?php

namespace App\Modules\PartnerApi\Http\Middleware;

use App\Modules\PartnerApi\Services\PartnerContext;
use App\Modules\PartnerApi\Services\PartnerTokenService;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a partner request from its bearer token and establishes
 * PartnerContext.
 *
 * There is no login endpoint and no session: the token *is* the identity, and
 * everything the request is allowed to touch — company, acting user,
 * environment, abilities — is derived from it here and nowhere else.
 *
 * Failures are answered with a specific code rather than a blanket 401. A
 * partner integrating against this API needs to tell a typo from an expired
 * secret from a deactivated account; all three are unauthenticated, and
 * collapsing them costs a support round-trip every time. Nothing in these
 * responses discloses anything the holder of the token does not already know:
 * an unknown token is always `invalid_token`, whoever sent it.
 */
class AuthenticatePartner
{
    public function __construct(
        private readonly PartnerTokenService $tokens,
        private readonly PartnerContext $context,
        private readonly B2bContext $b2bContext,
        private readonly RateLimiter $limiter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Credential-guessing guard. The per-token limiter downstream cannot
        // do this job: a request that fails to authenticate has no token to
        // bill, so an attacker cycling through candidates would never be
        // charged for any of them. Only *failures* count, so a legitimate
        // partner — including several sharing an egress IP — is never slowed
        // by it, and a partner with a raised per-client budget is not capped
        // to the default here.
        if ($this->limiter->tooManyAttempts($this->failureKey($request), $this->maxAuthFailures())) {
            return $this->tooManyFailures($request);
        }

        $plainText = $request->bearerToken();

        if ($plainText === null || trim($plainText) === '') {
            return $this->unauthenticated(
                $request,
                'missing_token',
                'This endpoint requires an Authorization: Bearer <token> header.'
            );
        }

        $token = $this->tokens->findByPlainText($plainText);

        if ($token === null) {
            return $this->unauthenticated($request, 'invalid_token', 'The provided API token is not valid.');
        }

        if ($token->isRevoked()) {
            return $this->unauthenticated($request, 'token_revoked', 'This API token has been revoked.');
        }

        if ($token->isExpired()) {
            return $this->unauthenticated($request, 'token_expired', 'This API token has expired.');
        }

        $client = $token->client;

        if ($client === null) {
            // The token outlived its client. Treat as invalid rather than
            // erroring: the credential genuinely no longer identifies anyone.
            return $this->unauthenticated($request, 'invalid_token', 'The provided API token is not valid.');
        }

        if (! $client->is_active) {
            return $this->forbidden(
                'client_inactive',
                'This integration client is deactivated. Contact your Leasyback representative.'
            );
        }

        $user = $client->user;
        $company = $client->company;

        if ($user === null || $company === null) {
            return $this->forbidden(
                'client_misconfigured',
                'This integration client is not fully configured. Contact your Leasyback representative.'
            );
        }

        if (! $user->is_active) {
            return $this->forbidden(
                'integration_user_inactive',
                'The integration account for this client is deactivated.'
            );
        }

        if (! $company->is_active) {
            return $this->forbidden(
                'company_inactive',
                'The company behind this integration is deactivated.'
            );
        }

        $this->context->establish($token, $client, $user, $company);
        $this->tokens->recordUsage($token, $request->ip());

        // Make the integration user the request's user without touching the
        // session guard: downstream services and policies ask $request->user(),
        // and a partner request must never establish a web session.
        $request->setUserResolver(fn () => $user);

        // Pin the company the shared B2B services will resolve for this user,
        // so a stale `active_b2b_id` cannot point a partner request at another
        // company the integration account was somehow added to. Only written
        // when it is actually wrong — the common path is a read, not a write.
        $this->b2bContext->forget($user);

        if ($this->b2bContext->activeCompanyId($user) !== $company->b2b_id
            && ! $this->b2bContext->switchTo($user, $company->b2b_id)) {
            return $this->forbidden(
                'client_misconfigured',
                'The integration account for this client is not an active member of its company.'
            );
        }

        return $next($request);
    }

    /**
     * Every 401 path goes through here, so none of them can forget to charge
     * the failure budget.
     */
    private function unauthenticated(Request $request, string $code, string $message): Response
    {
        $this->limiter->hit($this->failureKey($request), 60);

        return PartnerApiResponse::error(
            PartnerApiResponse::TYPE_AUTHENTICATION,
            $code,
            $message,
            401,
            headers: ['WWW-Authenticate' => 'Bearer'],
        );
    }

    /**
     * 403s are *not* charged: the credential was genuine, and a partner
     * polling while their account is suspended is a misconfiguration, not an
     * attack — locking them out of /me would only hide the reason from them.
     */
    private function forbidden(string $code, string $message): Response
    {
        return PartnerApiResponse::error(
            PartnerApiResponse::TYPE_AUTHORIZATION,
            $code,
            $message,
            403,
        );
    }

    private function tooManyFailures(Request $request): Response
    {
        $retryAfter = $this->limiter->availableIn($this->failureKey($request));

        return PartnerApiResponse::error(
            PartnerApiResponse::TYPE_RATE_LIMIT,
            'rate_limit_exceeded',
            'Too many failed authentication attempts. Retry after the interval given in Retry-After.',
            429,
            details: ['retry_after_seconds' => $retryAfter],
            headers: ['Retry-After' => (string) $retryAfter],
        );
    }

    private function failureKey(Request $request): string
    {
        return 'partner-api:auth-failures:'.sha1((string) $request->ip());
    }

    private function maxAuthFailures(): int
    {
        return (int) config('partner_api.rate_limit.auth_failures_per_minute');
    }
}
