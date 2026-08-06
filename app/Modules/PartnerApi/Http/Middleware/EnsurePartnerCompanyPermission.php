<?php

namespace App\Modules\PartnerApi\Http\Middleware;

use App\Enums\B2bPermission;
use App\Modules\PartnerApi\Services\PartnerContext;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second half of the Partner API's defence in depth: the *company*
 * permission behind the token's scope.
 *
 * `partner.ability:vehicles.write` answers "did we sell this partner that
 * endpoint". This answers "may the integration account actually do it inside
 * its company" — the same question EnsureB2bPermission asks for a human in the
 * portal, decided by the same B2bPermissionSet, so there is one definition of
 * what `vehicles.create` means and this middleware only adapts the answer.
 *
 * It exists alongside `b2b.can` rather than reusing it because that middleware
 * speaks to a browser: it *redirects* an account with no membership to the
 * onboarding page and aborts with a German prose message, which reaches a
 * partner as a `request_failed` catch-all. A machine client needs a specific
 * code, so the refusal is rendered in the documented envelope instead.
 */
class EnsurePartnerCompanyPermission
{
    public function __construct(
        private readonly PartnerContext $context,
        private readonly B2bContext $b2bContext,
    ) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $membership = $this->b2bContext->activeMembership($this->context->user());

        foreach ($permissions as $permission) {
            $required = B2bPermission::tryFrom($permission);

            // An unknown permission string fails closed, for the same reason
            // EnsurePartnerAbility does: a typo in a route definition must
            // lock the endpoint, never open it.
            if ($membership === null || $required === null || ! $membership->can($required)) {
                return PartnerApiResponse::error(
                    PartnerApiResponse::TYPE_AUTHORIZATION,
                    'insufficient_company_permission',
                    'The integration account for this client is not permitted to perform that '
                    .'action inside its company.',
                    403,
                    details: ['required_permission' => $permission],
                );
            }
        }

        return $next($request);
    }
}
