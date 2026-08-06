<?php

use App\Modules\PartnerApi\Http\Controllers\HealthController;
use App\Modules\PartnerApi\Http\Controllers\MeController;
use App\Modules\PartnerApi\Http\Controllers\OrderController;
use App\Modules\PartnerApi\Http\Controllers\VehicleController;
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
| Every feature route carries two gates, in this order:
|   partner.ability:…    — did we sell this partner that endpoint (token scope)
|   partner.company-can:… — may the integration account do it in its company
| A mis-scoped token therefore cannot exceed what the company itself may do,
| and neither gate alone is sufficient. Creates add `partner.idempotent:required`
| last, so an unscoped call is refused before it consumes an Idempotency-Key.
|
| Ids are constrained with whereUuid rather than resolved by route model
| binding: binding fetches by primary key first and authorises second, and
| every lookup in this API must *be* the authorisation.
|
*/

Route::prefix('v1/partner')
    ->name('partner.v1.')
    ->middleware(['partner.request-id', 'partner.auth', 'partner.throttle', 'partner.no-ownership'])
    ->group(function () {
        Route::get('health', HealthController::class)->name('health');
        Route::get('me', MeController::class)->name('me');

        /*
         * Vehicles.
         */
        Route::get('vehicles', [VehicleController::class, 'index'])
            ->middleware(['partner.ability:vehicles.read', 'partner.company-can:vehicles.view'])
            ->name('vehicles.index');

        Route::post('vehicles', [VehicleController::class, 'store'])
            ->middleware([
                'partner.ability:vehicles.write',
                'partner.company-can:vehicles.create',
                'partner.idempotent:required',
            ])
            ->name('vehicles.store');

        Route::get('vehicles/{vehicle}', [VehicleController::class, 'show'])
            ->whereUuid('vehicle')
            ->middleware(['partner.ability:vehicles.read', 'partner.company-can:vehicles.view'])
            ->name('vehicles.show');

        Route::patch('vehicles/{vehicle}', [VehicleController::class, 'update'])
            ->whereUuid('vehicle')
            ->middleware([
                'partner.ability:vehicles.write',
                'partner.company-can:vehicles.update',
                'partner.idempotent',
            ])
            ->name('vehicles.update');

        /*
         * Orders. The nested create reads as what it is — a return order is
         * always *for a vehicle*, never a standalone record with a vehicle
         * field, which is also why there is no POST /orders.
         */
        Route::get('orders', [OrderController::class, 'index'])
            ->middleware(['partner.ability:orders.read', 'partner.company-can:vehicles.view'])
            ->name('orders.index');

        Route::get('orders/{order}', [OrderController::class, 'show'])
            ->whereUuid('order')
            ->middleware(['partner.ability:orders.read', 'partner.company-can:vehicles.view'])
            ->name('orders.show');

        Route::get('vehicles/{vehicle}/orders', [OrderController::class, 'forVehicle'])
            ->whereUuid('vehicle')
            ->middleware(['partner.ability:orders.read', 'partner.company-can:vehicles.view'])
            ->name('vehicles.orders.index');

        Route::post('vehicles/{vehicle}/orders', [OrderController::class, 'store'])
            ->whereUuid('vehicle')
            ->middleware([
                'partner.ability:orders.write',
                'partner.company-can:orders.create',
                'partner.idempotent:required',
            ])
            ->name('vehicles.orders.store');
    });
