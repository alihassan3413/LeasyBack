<?php

use App\Modules\PartnerApi\Http\Controllers\DocumentController;
use App\Modules\PartnerApi\Http\Controllers\HealthController;
use App\Modules\PartnerApi\Http\Controllers\MeController;
use App\Modules\PartnerApi\Http\Controllers\OfferController;
use App\Modules\PartnerApi\Http\Controllers\OrderController;
use App\Modules\PartnerApi\Http\Controllers\TimelineController;
use App\Modules\PartnerApi\Http\Controllers\VehicleController;
use App\Modules\PartnerApi\Http\Controllers\WebhookController;
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

        /*
         * Status and timeline. Split because they answer the same question at
         * very different costs: /status is the poll, /timeline is the board.
         * Both carry `timeline.read` rather than `orders.read` — a partner
         * that only wants progress notifications should not have to be sold
         * the order records themselves.
         */
        Route::get('orders/{order}/status', [TimelineController::class, 'status'])
            ->whereUuid('order')
            ->middleware(['partner.ability:timeline.read', 'partner.company-can:vehicles.view'])
            ->name('orders.status');

        Route::get('orders/{order}/timeline', [TimelineController::class, 'timeline'])
            ->whereUuid('order')
            ->middleware(['partner.ability:timeline.read', 'partner.company-can:vehicles.view'])
            ->name('orders.timeline');

        /*
         * Documents. `/download` mints a signed link; the bytes are served by
         * `/content`, registered outside this group below.
         */
        Route::get('orders/{order}/documents', [DocumentController::class, 'forOrder'])
            ->whereUuid('order')
            ->middleware(['partner.ability:documents.read', 'partner.company-can:vehicles.view'])
            ->name('orders.documents.index');

        Route::get('documents/{document}', [DocumentController::class, 'show'])
            ->whereUuid('document')
            ->middleware(['partner.ability:documents.read', 'partner.company-can:vehicles.view'])
            ->name('documents.show');

        Route::get('documents/{document}/download', [DocumentController::class, 'download'])
            ->whereUuid('document')
            ->middleware(['partner.ability:documents.read', 'partner.company-can:vehicles.view'])
            ->name('documents.download');

        /*
         * Offers. Read-only: there is no accept/reject endpoint, deliberately
         * (see PartnerApi\Http\Controllers\OfferController).
         */
        Route::get('orders/{order}/offers', [OfferController::class, 'forOrder'])
            ->whereUuid('order')
            ->middleware(['partner.ability:offers.read', 'partner.company-can:vehicles.view'])
            ->name('orders.offers.index');

        Route::get('offers/{offer}', [OfferController::class, 'show'])
            ->whereUuid('offer')
            ->middleware(['partner.ability:offers.read', 'partner.company-can:vehicles.view'])
            ->name('offers.show');

        /*
         * Webhooks. Reads carry `webhooks.read`, writes `webhooks.manage` —
         * the ability is what separates the two, and both halves are still
         * required, so a token scoped to manage webhooks in a company whose
         * integration account has been suspended gets nothing.
         *
         * The company gate is `company.view` rather than `company.manage`: a
         * subscription is the integration client's own configuration, not
         * company master data, and the integration account deliberately holds
         * no master-data permission (§12.6). What the gate asserts here is that
         * the account is still a live member of its company.
         *
         * `/deliveries` and `/deliveries/{id}` sit under the subscription
         * rather than at the top level so the ownership check is the same
         * lookup for both: a delivery is reachable only through the
         * subscription it belongs to, which is reachable only through this
         * token's client.
         */
        Route::get('webhooks', [WebhookController::class, 'index'])
            ->middleware(['partner.ability:webhooks.read', 'partner.company-can:company.view'])
            ->name('webhooks.index');

        Route::post('webhooks', [WebhookController::class, 'store'])
            ->middleware([
                'partner.ability:webhooks.manage',
                'partner.company-can:company.view',
                'partner.idempotent:required',
            ])
            ->name('webhooks.store');

        Route::get('webhooks/{webhook}', [WebhookController::class, 'show'])
            ->whereUuid('webhook')
            ->middleware(['partner.ability:webhooks.read', 'partner.company-can:company.view'])
            ->name('webhooks.show');

        Route::patch('webhooks/{webhook}', [WebhookController::class, 'update'])
            ->whereUuid('webhook')
            ->middleware([
                'partner.ability:webhooks.manage',
                'partner.company-can:company.view',
                'partner.idempotent',
            ])
            ->name('webhooks.update');

        Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])
            ->whereUuid('webhook')
            ->middleware(['partner.ability:webhooks.manage', 'partner.company-can:company.view'])
            ->name('webhooks.destroy');

        Route::post('webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret'])
            ->whereUuid('webhook')
            ->middleware([
                'partner.ability:webhooks.manage',
                'partner.company-can:company.view',
                'partner.idempotent',
            ])
            ->name('webhooks.rotate-secret');

        Route::post('webhooks/{webhook}/test', [WebhookController::class, 'test'])
            ->whereUuid('webhook')
            ->middleware(['partner.ability:webhooks.manage', 'partner.company-can:company.view'])
            ->name('webhooks.test');

        Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])
            ->whereUuid('webhook')
            ->middleware(['partner.ability:webhooks.read', 'partner.company-can:company.view'])
            ->name('webhooks.deliveries.index');

        Route::get('webhooks/{webhook}/deliveries/{delivery}', [WebhookController::class, 'delivery'])
            ->whereUuid('webhook')
            ->whereUuid('delivery')
            ->middleware(['partner.ability:webhooks.read', 'partner.company-can:company.view'])
            ->name('webhooks.deliveries.show');

        Route::post('webhooks/{webhook}/deliveries/{delivery}/replay', [WebhookController::class, 'replay'])
            ->whereUuid('webhook')
            ->whereUuid('delivery')
            ->middleware(['partner.ability:webhooks.manage', 'partner.company-can:company.view'])
            ->name('webhooks.deliveries.replay');
    });

/*
|--------------------------------------------------------------------------
| Signed document downloads
|--------------------------------------------------------------------------
|
| The one partner route that carries no bearer token. It exists so a download
| link can be handed to something that is not the integration itself — a
| browser, a document pipeline — without also handing over a credential that
| unlocks every other endpoint.
|
| The signature is the credential, and it is not the whole check: the link
| embeds the integration client it was minted for, and
| PartnerDocumentDownloadLink::verify() re-asks whether that client is still
| active and whether the document still belongs to a B2B vehicle in its
| company before any byte is read. A link therefore stops working the moment
| the client is deactivated, not thirty minutes later.
|
| `partner.request-id` still applies so a failed download carries a
| correlation id like every other partner response. `partner.auth` and the
| gates deliberately do not: there is nothing to authenticate.
|
*/
Route::prefix('v1/partner')
    ->name('partner.v1.')
    ->middleware(['partner.request-id'])
    ->group(function () {
        Route::get('documents/{document}/content', [DocumentController::class, 'content'])
            ->whereUuid('document')
            ->name('documents.content');
    });
