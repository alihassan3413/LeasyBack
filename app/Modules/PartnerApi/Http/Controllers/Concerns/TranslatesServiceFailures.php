<?php

namespace App\Modules\PartnerApi\Http\Controllers\Concerns;

use App\Modules\PartnerApi\Exceptions\PartnerApiException;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Turns the shared services' refusals into Partner API errors.
 *
 * VehicleService and OrderService signal a refusal by throwing
 * HttpResponseException carrying a ready-made `{"error": "..."}` response —
 * the legacy Sanctum API's shape, which both the portal controllers and the
 * older API expect. Laravel returns that response verbatim, which would put a
 * bare string and no error code in front of a partner, and
 * PartnerApiExceptionRenderer cannot help: an HttpResponseException reaches it
 * as an unrecognised throwable and renders as the 500 catch-all.
 *
 * So the refusal is intercepted at the one place that knows what it means —
 * the controller that called the service — and re-thrown with the documented
 * code for that endpoint. The services keep their existing contract, and no
 * caller outside this API sees any change.
 */
trait TranslatesServiceFailures
{
    /**
     * @param  array<int, string>  $codes  HTTP status the service fails with => partner error code
     */
    protected function translatingServiceFailures(array $codes, Closure $work): mixed
    {
        try {
            return $work();
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
            $status = $response->getStatusCode();
            $decoded = json_decode((string) $response->getContent(), true);

            throw new PartnerApiException(
                $this->partnerErrorType($status),
                $codes[$status] ?? 'request_failed',
                is_array($decoded) && is_string($decoded['error'] ?? null)
                    ? $decoded['error']
                    : 'The request could not be completed.',
                $status,
            );
        }
    }

    private function partnerErrorType(int $status): string
    {
        return match (true) {
            $status === 403 => PartnerApiResponse::TYPE_AUTHORIZATION,
            $status === 404 => PartnerApiResponse::TYPE_NOT_FOUND,
            $status === 409 => PartnerApiResponse::TYPE_CONFLICT,
            $status === 422 => PartnerApiResponse::TYPE_VALIDATION,
            $status >= 500 => PartnerApiResponse::TYPE_SERVER,
            default => PartnerApiResponse::TYPE_INVALID_REQUEST,
        };
    }
}
