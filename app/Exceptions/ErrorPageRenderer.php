<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Swaps Laravel's built-in HTML error views for the branded Inertia error
 * page. Only the final response is inspected — never the exception itself —
 * so nothing about the underlying failure (message, trace, route, driver)
 * can reach the browser.
 */
class ErrorPageRenderer
{
    /**
     * Headers worth carrying over from the response Laravel built: they are
     * part of the HTTP contract for 405/429/503 and contain no internals.
     *
     * @var list<string>
     */
    private const FORWARDED_HEADERS = ['Allow', 'Retry-After'];

    /**
     * The thrown exception is deliberately ignored — only the status code
     * Laravel already derived from it reaches the page.
     */
    public function __invoke(Response $response, Throwable $e, Request $request): Response
    {
        if (! $this->shouldRender($response, $request)) {
            return $response;
        }

        $status = $response->getStatusCode();

        $errorPage = self::page($status, $request)->setStatusCode($status);

        foreach (self::FORWARDED_HEADERS as $header) {
            if ($response->headers->has($header)) {
                $errorPage->headers->set($header, (string) $response->headers->get($header));
            }
        }

        return $errorPage;
    }

    /**
     * The rendered error page for a status code, without the status code
     * applied — also used by the local preview routes.
     */
    public static function page(int $status, Request $request): Response
    {
        return Inertia::render('Errors/Error', [
            'status' => $status,
            'maintenanceMessage' => self::maintenanceMessage($status),
        ])->toResponse($request);
    }

    private function shouldRender(Response $response, Request $request): bool
    {
        $status = $response->getStatusCode();

        if ($status < 400 || $status > 599) {
            return false;
        }

        // Local debugging keeps Laravel's stack-trace page for actual faults.
        // A client error carries no trace worth reading, so those show the
        // branded page in development too — same as production.
        if (config('app.debug') && $status >= 500) {
            return false;
        }

        // The JSON APIs (including the legacy auth contract in bootstrap/app.php)
        // and the health check must keep returning machine-readable responses.
        if ($request->is('api/*', 'auth/*', 'up') || $response instanceof JsonResponse) {
            return false;
        }

        // An expired session on an Inertia XHR stays a non-Inertia response
        // on purpose: that is what fires the client's `invalid` event, which
        // SessionGuard.vue turns into the logout overlay and a redirect to
        // the login screen. Full page loads still get the 419 page below.
        if ($request->inertia()) {
            return $status !== 419;
        }

        return $request->acceptsHtml() && ! $request->expectsJson();
    }

    /**
     * Operator-authored downtime notice, shown on the 503 page when set.
     */
    private static function maintenanceMessage(int $status): ?string
    {
        if ($status !== 503) {
            return null;
        }

        $message = config('app.maintenance.message');

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }
}
