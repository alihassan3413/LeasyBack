<?php

namespace App\Providers;

use App\Modules\PartnerApi\Services\PartnerContext;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\Order\Contracts\AppraisalAiExtractor;
use App\Modules\UserProfile\Order\Contracts\AppraisalDocumentParser;
use App\Modules\UserProfile\Order\Contracts\PdfTextExtractor;
use App\Modules\UserProfile\Order\Services\DisabledAppraisalAiExtractor;
use App\Modules\UserProfile\Order\Services\Extraction\PdfGutachtenParser;
use App\Modules\UserProfile\Order\Services\Extraction\PdftotextExtractor;
use App\Modules\UserProfile\Payment\Contracts\LexwareGateway;
use App\Modules\UserProfile\Payment\Contracts\StripeGateway;
use App\Modules\UserProfile\Payment\Services\LexwareClient;
use App\Modules\UserProfile\Payment\Services\StripeClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not a plain binding: which company a user is acting as and
        // what they may do in it is asked by middleware, controllers,
        // policies and the Inertia share on the same request. Resolving it
        // once per request keeps that to a single pair of queries, and keeps
        // every consumer looking at the same answer.
        $this->app->scoped(B2bContext::class);

        // Same reasoning, for the Partner API: the middleware establishes the
        // calling integration's identity once, and every controller, service
        // and error response on that request reads the same instance. A
        // non-scoped binding would let a controller resolve a fresh, empty
        // context and silently run unscoped.
        $this->app->scoped(PartnerContext::class);

        /*
         * The single seam between this application and Stripe. Bound rather
         * than instantiated at call sites so the entire payment suite can swap
         * in a fake and never touch the network — a payment test that reaches
         * Stripe fails when Stripe is slow and passes when a test card happens
         * to behave, which makes it worse than no test at all.
         *
         * Not scoped: StripeClient holds no per-request state, and binding it
         * lazily means an environment with no STRIPE_SECRET only fails when
         * something actually tries to charge, rather than on every request.
         */
        $this->app->bind(StripeGateway::class, StripeClient::class);

        $this->app->bind(LexwareGateway::class, LexwareClient::class);

        $this->app->bind(AppraisalDocumentParser::class, PdfGutachtenParser::class);

        $this->app->bind(AppraisalAiExtractor::class, DisabledAppraisalAiExtractor::class);

        $this->app->bind(PdfTextExtractor::class, PdftotextExtractor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Password-reset links, verification links, etc. must always be
        // https in production, even if the app sits behind a TLS-terminating
        // proxy/load balancer that talks plain HTTP to this app internally.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        $this->registerWorkshopRateLimiters();
        $this->registerMfaRateLimiters();
    }

    /**
     * One limiter per public workshop route (§9).
     *
     * Laravel's inline `throttle:n,m` keys every route on sha1(domain|ip) and
     * only varies the ceiling it compares against, so all four workshop routes
     * drew on one counter: opening a quotation and loading its damage photos
     * spent the submission's budget, and the workshop got a 429 on the one
     * request that mattered. A named limiter puts its own name in the key, so
     * these budgets are genuinely separate.
     *
     * Keyed on the caller's IP, never on the token in the URL. The token is
     * user input — keying on it would let anyone mint a fresh budget by
     * changing one character. `by()` is also required rather than optional: a
     * named limiter with no key falls back to '', which would make one bucket
     * shared by every caller on the internet.
     *
     * The ceilings are the ones these routes already carried; only the buckets
     * are new.
     */
    /**
     * Guessing a six-digit code has to be expensive from outside as well as
     * inside. The challenge itself already dies after five wrong codes, but
     * that counter lives on one challenge — without a limiter, an attacker
     * could keep minting fresh challenges and spend five guesses on each.
     *
     * Keyed on the caller, because the ticket is attacker-chosen and keying on
     * it would hand out a fresh budget per guess.
     */
    private function registerMfaRateLimiters(): void
    {
        RateLimiter::for('mfa-verify', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('mfa-send', fn (Request $request) => Limit::perMinute(6)->by((string) $request->ip()));
    }

    private function registerWorkshopRateLimiters(): void
    {
        RateLimiter::for('workshop-page', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));
        // Sized for how the gallery actually behaves, not for an ideal one. The
        // image route answers `Cache-Control: no-store` on purpose — a
        // customer's damage photos must not survive in the browser cache once
        // the link dies — so every render refetches every thumbnail. A
        // Gutachten with 40 photos therefore costs 40 requests per view, and
        // 120 ran out partway through the third view, which is what the
        // workshop saw as broken images. 600 covers repeated browsing of even
        // a 60-photo appraisal while still bounding a single caller.
        RateLimiter::for('workshop-images', fn (Request $request) => Limit::perMinute(600)->by((string) $request->ip()));
        RateLimiter::for('workshop-pdf', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));
        RateLimiter::for('workshop-submit', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
    }
}
