<?php

namespace App\Modules\PartnerApi\Http\Middleware;

use App\Modules\PartnerApi\Services\PartnerContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for every partner request.
 *
 * Runs before authentication, so even a 401 comes back with an id the partner
 * can quote in a support mail. An inbound `X-Request-ID` is honoured when it
 * is safely shaped and replaced otherwise — a malformed correlation id must
 * never be the reason a real request fails, and echoing arbitrary bytes back
 * in a header is a response-splitting risk not worth taking for a value that
 * only exists to join two log lines.
 *
 * The id is pushed into Laravel's Context so every log line written during the
 * request carries it, including from queued work dispatched by the request.
 */
class AssignPartnerRequestId
{
    /** Alphanumerics plus the separators UUIDs and trace ids actually use. */
    private const SAFE_PATTERN = '/^[A-Za-z0-9._:\-]+$/';

    public function __construct(private readonly PartnerContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('partner_api.request_id.header', 'X-Request-ID');

        $requestId = $this->normalise($request->header($header));

        $this->context->setRequestId($requestId);
        $request->attributes->set('partner_request_id', $requestId);
        Context::add('partner_request_id', $requestId);

        $response = $next($request);
        $response->headers->set($header, $requestId);

        return $response;
    }

    private function normalise(?string $candidate): string
    {
        $maxLength = (int) config('partner_api.request_id.max_length', 128);
        $candidate = $candidate === null ? '' : trim($candidate);

        if ($candidate === ''
            || Str::length($candidate) > $maxLength
            || preg_match(self::SAFE_PATTERN, $candidate) !== 1) {
            return (string) Str::uuid();
        }

        return $candidate;
    }
}
