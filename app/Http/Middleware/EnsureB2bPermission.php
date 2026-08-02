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
 * Applies only to Firmenkunde accounts. Every other account type — Privatkunde,
 * Werkstatt, Admin — passes straight through, because several of these routes
 * are shared and company permissions are meaningless outside a company; their
 * access is decided by the route's own policies exactly as before.
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

        // Company permissions are a Firmenkunde concept. Every other account
        // type passes straight through, leaving the route's own policies to
        // decide — several of these routes are shared with Privatkunde, and
        // refusing them here would lock B2C out of its own dashboard.
        if ($user->user_type !== UserType::Firmenkunde) {
            return $next($request);
        }

        $membership = $this->context->activeMembership($user);

        if ($membership === null) {
            // Belongs to no company yet — send them to register one rather
            // than showing a dead end.
            return redirect()->route('onboarding.b2b.show');
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
