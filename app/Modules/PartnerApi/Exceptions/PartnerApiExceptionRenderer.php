<?php

namespace App\Modules\PartnerApi\Exceptions;

use App\Modules\PartnerApi\Support\PartnerApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Turns anything thrown under /api/v1/partner into the documented Partner API
 * error envelope.
 *
 * Scoped by path, so registering it changes nothing for the web app, the
 * legacy auth API or the other modules. Returning null hands the exception
 * back to the handlers registered after it.
 *
 * The catch-all never surfaces the real exception message or a stack trace,
 * whatever APP_DEBUG says: this is a third-party-facing surface, and a leaked
 * SQL string or file path there is a disclosure, not a debugging aid. The
 * exception still reaches the logs — with the request id, so a partner
 * quoting theirs is enough to find it.
 */
class PartnerApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/v1/partner/*')) {
            return null;
        }

        // Domain errors render themselves — they already carry a code.
        if ($e instanceof PartnerApiException) {
            return $e->render($request);
        }

        return match (true) {
            $e instanceof ValidationException => PartnerApiResponse::error(
                PartnerApiResponse::TYPE_VALIDATION,
                'validation_failed',
                'The request payload failed validation.',
                422,
                details: ['fields' => $e->errors()],
            ),
            $e instanceof AuthenticationException => PartnerApiResponse::error(
                PartnerApiResponse::TYPE_AUTHENTICATION,
                'missing_token',
                'This endpoint requires an Authorization: Bearer <token> header.',
                401,
                headers: ['WWW-Authenticate' => 'Bearer'],
            ),
            $e instanceof AuthorizationException => PartnerApiResponse::error(
                PartnerApiResponse::TYPE_AUTHORIZATION,
                'forbidden',
                'This token is not permitted to perform that action.',
                403,
            ),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => PartnerApiResponse::error(
                PartnerApiResponse::TYPE_NOT_FOUND,
                'resource_not_found',
                'The requested resource does not exist, or is not visible to this token.',
                404,
            ),
            $e instanceof MethodNotAllowedHttpException => PartnerApiResponse::error(
                PartnerApiResponse::TYPE_INVALID_REQUEST,
                'method_not_allowed',
                'That HTTP method is not supported for this endpoint.',
                405,
            ),
            $e instanceof TooManyRequestsHttpException => PartnerApiResponse::error(
                PartnerApiResponse::TYPE_RATE_LIMIT,
                'rate_limit_exceeded',
                'Too many requests. Slow down and retry after the interval given in Retry-After.',
                429,
                headers: $this->retryAfter($e),
            ),
            $e instanceof HttpExceptionInterface => PartnerApiResponse::error(
                $this->typeForStatus($e->getStatusCode()),
                'request_failed',
                'The request could not be completed.',
                $e->getStatusCode(),
            ),
            default => PartnerApiResponse::error(
                PartnerApiResponse::TYPE_SERVER,
                'internal_error',
                'Something went wrong on our side. Retry later, quoting request_id if it persists.',
                500,
            ),
        };
    }

    /**
     * @return array<string, string>
     */
    private function retryAfter(HttpExceptionInterface $e): array
    {
        $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

        return $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter];
    }

    private function typeForStatus(int $status): string
    {
        return match (true) {
            $status === 401 => PartnerApiResponse::TYPE_AUTHENTICATION,
            $status === 403 => PartnerApiResponse::TYPE_AUTHORIZATION,
            $status === 404 => PartnerApiResponse::TYPE_NOT_FOUND,
            $status === 409 => PartnerApiResponse::TYPE_CONFLICT,
            $status >= 500 => PartnerApiResponse::TYPE_SERVER,
            default => PartnerApiResponse::TYPE_INVALID_REQUEST,
        };
    }
}
