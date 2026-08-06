<?php

namespace App\Http\Middleware;

use App\Enums\B2bPermission;
use App\Enums\UserType;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route on a B2B company permission: `->middleware('b2b.can:vehicles.create')`.
 *
 * Applies to anyone currently acting as a company. Accounts acting outside a
 * company — Privatkunde on their private side, Werkstatt, Admin — pass
 * straight through, because several of these routes are shared and company
 * permissions are meaningless outside a company; their access is decided by
 * the route's own policies exactly as before.
 *
 * The gate is the *active membership*, not `user_type`: a Privatkunde who
 * accepted a B2B invitation is a real company member while acting as that
 * company and must be held to the permissions they were granted.
 *
 * This is the route-level half of the check. Anything that reads or writes a
 * specific vehicle still goes through VehicleScopeService/the policies, so a
 * member who is allowed to create vehicles but only sees their own cannot use
 * a permitted route to reach someone else's record.
 */
class EnsureB2bPermission
{
    public function __construct(private readonly B2bContext $context) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $membership = $this->context->activeMembership($user);

        if ($membership === null) {
            // Not acting as a company. A Firmenkunde in that state belongs to
            // no company yet and is sent to register one rather than shown a
            // dead end; everyone else passes straight through, leaving the
            // route's own policies to decide — several of these routes are
            // shared with Privatkunde, and refusing them here would lock B2C
            // out of its own dashboard.
            return $user->user_type === UserType::Firmenkunde
                ? redirect()->route('onboarding.b2b.show')
                : $next($request);
        }

        foreach ($permissions as $permission) {
            $required = B2bPermission::tryFrom($permission);

            if ($required === null || ! $membership->can($required)) {
                abort(403, 'Ihnen fehlt die Berechtigung für diesen Bereich.');
            }
        }

        return $next($request);
    }
}
