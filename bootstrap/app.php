<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerifyTuvsudApiKey;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'tuvsud.webhook' => VerifyTuvsudApiKey::class,
        ]);

        // The standalone leasyback_web SPA authenticates with Sanctum bearer
        // tokens. Do not apply cookie/CSRF middleware to its API requests.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Standardized {ok,data,message} JSON error contract for the legacy
        // auth API — scoped to auth/* and api/auth/* only, so it never
        // changes error behavior for other modules (UserProfile, DekraProcess)
        // that haven't been reviewed under this contract yet. Applies to any
        // exception this handler would otherwise have rendered directly,
        // including ones Laravel throws itself (AuthenticationException on a
        // missing/invalid Sanctum token, ValidationException, 404s, 429
        // throttling) and any genuinely unexpected error — the catch-all
        // never leaks the real exception message or a stack trace,
        // regardless of APP_DEBUG.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('auth/*', 'api/auth/*')) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => response()->json([
                    'ok' => false,
                    'data' => null,
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                ], 422),
                $e instanceof AuthenticationException => response()->json([
                    'ok' => false,
                    'data' => null,
                    'message' => 'Unauthenticated.',
                ], 401),
                $e instanceof HttpExceptionInterface => response()->json([
                    'ok' => false,
                    'data' => null,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
                ], $e->getStatusCode()),
                default => response()->json([
                    'ok' => false,
                    'data' => null,
                    'message' => 'Something went wrong. Please try again later.',
                ], 500),
            };
        });
    })->create();
