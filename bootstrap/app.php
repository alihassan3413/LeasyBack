<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Compatibility routes for the unchanged leasyback_web client,
            // which calls /auth, /profile, and /dekra without the /api prefix.
            Route::middleware('api')
                ->name('frontend.')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The standalone leasyback_web SPA authenticates with Sanctum bearer
        // tokens. Do not apply cookie/CSRF middleware to its API requests.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
