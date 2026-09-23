<?php

namespace App\Modules\PartnerApi\Http\Middleware;

use App\Modules\PartnerApi\Enums\PartnerAbility;
use App\Modules\PartnerApi\Services\PartnerContext;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route on a token scope: `->middleware('partner.ability:vehicles.read')`.
 *
 * All listed abilities are required, not any of them — a route that needs two
 * scopes needs both, and the alternative (any-of) is expressible by not
 * listing the second one.
 *
 * An unknown ability string fails closed. A typo in a route definition must
 * lock the endpoint, never open it.
 */
class EnsurePartnerAbility
{
    public function __construct(private readonly PartnerContext $context) {}

    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        if (! $this->context->isEstablished()) {
            // Only reachable if this middleware is registered without
            // AuthenticatePartner in front of it.
            return PartnerApiResponse::error(
                PartnerApiResponse::TYPE_AUTHENTICATION,
                'missing_token',
                'This endpoint requires an Authorization: Bearer <token> header.',
                401,
                headers: ['WWW-Authenticate' => 'Bearer'],
            );
        }

        foreach ($abilities as $ability) {
            $required = PartnerAbility::tryFrom($ability);

            if ($required === null || ! $this->context->can($required)) {
                return PartnerApiResponse::error(
                    PartnerApiResponse::TYPE_AUTHORIZATION,
                    'insufficient_scope',
                    'This API token does not carry the scope required for this endpoint.',
                    403,
                    details: [
                        'required_ability' => $ability,
                        'granted_abilities' => $this->context->abilities(),
                    ],
                );
            }
        }

        return $next($request);
    }
}
