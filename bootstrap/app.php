<?php

use App\Exceptions\ErrorPageRenderer;
use App\Http\Middleware\EnsureB2bPermission;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerifyDekraWebhookSignature;
use App\Http\Middleware\VerifyTuvsudApiKey;
use App\Modules\PartnerApi\Exceptions\PartnerApiExceptionRenderer;
use App\Modules\PartnerApi\Http\Middleware\AssignPartnerRequestId;
use App\Modules\PartnerApi\Http\Middleware\AuthenticatePartner;
use App\Modules\PartnerApi\Http\Middleware\EnforcePartnerIdempotency;
use App\Modules\PartnerApi\Http\Middleware\EnsurePartnerAbility;
use App\Modules\PartnerApi\Http\Middleware\EnsurePartnerCompanyPermission;
use App\Modules\PartnerApi\Http\Middleware\RejectOwnershipInput;
use App\Modules\PartnerApi\Http\Middleware\ThrottlePartnerRequests;
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
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Compatibility routes for the unchanged leasyback_web client,
            // which calls /auth, /profile, and /dekra without the /api prefix.
            Route::middleware('api')
                ->name('frontend.')
                ->group(base_path('routes/api.php'));

            // Partner API. Registered separately from routes/api.php so it is
            // exposed at exactly one URL — /api/v1/partner/* — and never
            // picks up the unprefixed compatibility alias above, which exists
            // only for the legacy SPA.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/partner.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureUserIsAdmin::class,
            'b2b.can' => EnsureB2bPermission::class,
            'tuvsud.webhook' => VerifyTuvsudApiKey::class,
            'dekra.webhook' => VerifyDekraWebhookSignature::class,

            // Partner API (see routes/partner.php for the required order).
            'partner.request-id' => AssignPartnerRequestId::class,
            'partner.auth' => AuthenticatePartner::class,
            'partner.throttle' => ThrottlePartnerRequests::class,
            'partner.ability' => EnsurePartnerAbility::class,
            'partner.company-can' => EnsurePartnerCompanyPermission::class,
            'partner.no-ownership' => RejectOwnershipInput::class,
            'partner.idempotent' => EnforcePartnerIdempotency::class,
        ]);

        // The standalone leasyback_web SPA authenticates with Sanctum bearer
        // tokens. Do not apply cookie/CSRF middleware to its API requests.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Partner API error contract. Registered first, and scoped to
        // api/v1/partner/* only, so no other module's error behavior changes:
        // a partner must never receive an Inertia error page or the legacy
        // {ok,data,message} envelope, and must never see an internal message.
        $exceptions->render(new PartnerApiExceptionRenderer);

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

        // Everything else that ends as a 4xx/5xx HTML response gets the
        // branded Inertia error page. Runs on the finished response, so
        // Laravel has already normalized exceptions to status codes
        // (TokenMismatch -> 419, ModelNotFound -> 404, maintenance -> 503).
        $exceptions->respond(new ErrorPageRenderer);
    })->create();
