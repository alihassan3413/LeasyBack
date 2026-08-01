<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-group-level gate for the whole /admin web section — the Inertia
 * counterpart of AdminPolicy's per-endpoint Sanctum abilities (see
 * app/Policies/AdminPolicy.php). A single coarse "is this user an Admin at
 * all" check is the right granularity here, the same way ['auth','active']
 * already gates the whole authenticated web section; any page-level
 * distinction future Admin pages need layers on top via their own
 * controller/Policy checks, same as VehicleController does today.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return $next($request);
    }
}
