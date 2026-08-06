<?php

use App\Modules\PartnerApi\Http\Controllers\HealthController;
use App\Modules\PartnerApi\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Partner API Routes
|--------------------------------------------------------------------------
|
| Versioned machine-to-machine API, mounted at /api/v1/partner by
| bootstrap/app.php.
|
| Registered from its own file rather than routes/api.php on purpose: that
| file is loaded twice (once at /api/*, once unprefixed as the `frontend.*`
| compatibility alias for the legacy SPA), and a partner endpoint must exist
| at exactly one URL. /v1 is in the path, not a header, so a future v2 is an
| additional file next to this one and no existing integration moves.
|
| The middleware order is load-bearing:
|   1. partner.request-id  — assigns a correlation id, so even a 401 carries one
|   2. partner.auth        — resolves the token and establishes PartnerContext
|   3. partner.throttle    — per-token limit, needs the token from step 2
|   4. partner.no-ownership — refuses company/ownership fields in any request
|
| Ability gating (`partner.ability:…`) is per route, not on the group: /health
| and /me deliberately require no scope, so a partner can verify a freshly
| issued credential before any endpoint has been enabled for them.
|
*/

Route::prefix('v1/partner')
    ->name('partner.v1.')
    ->middleware(['partner.request-id', 'partner.auth', 'partner.throttle', 'partner.no-ownership'])
    ->group(function () {
        Route::get('health', HealthController::class)->name('health');
        Route::get('me', MeController::class)->name('me');
    });
