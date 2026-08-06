<?php

namespace App\Modules\PartnerApi\Support;

use App\Modules\PartnerApi\Services\PartnerContext;
use Illuminate\Http\JsonResponse;

/**
 * The Partner API's response envelope.
 *
 * Deliberately not the app's existing `{ok, data, message}` shape. That
 * contract is the legacy auth API's and is frozen for the leasyback_web SPA;
 * a public integration API needs a stable machine-readable error *code* a
 * partner can branch on, which `message` alone cannot provide without
 * partners string-matching German prose.
 *
 * Success:  {"data": {...}, "request_id": "..."}
 * Error:    {"error": {"type": "...", "code": "...", "message": "...",
 *                      "details": {...}}, "request_id": "..."}
 *
 * `type` is the coarse class (authentication_error, authorization_error,
 * validation_error, rate_limit_error, invalid_request_error, not_found,
 * conflict, server_error) and `code` is the specific reason. Partners branch
 * on `code`; `type` lets them handle a whole class they have no specific
 * handling for yet.
 */
final class PartnerApiResponse
{
    public const TYPE_AUTHENTICATION = 'authentication_error';

    public const TYPE_AUTHORIZATION = 'authorization_error';

    public const TYPE_INVALID_REQUEST = 'invalid_request_error';

    public const TYPE_VALIDATION = 'validation_error';

    public const TYPE_NOT_FOUND = 'not_found';

    public const TYPE_CONFLICT = 'conflict';

    public const TYPE_RATE_LIMIT = 'rate_limit_error';

    public const TYPE_SERVER = 'server_error';

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public static function success(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'request_id' => self::requestId(),
        ], $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function error(
        string $type,
        string $code,
        string $message,
        int $status,
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        $error = [
            'type' => $type,
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json([
            'error' => $error,
            'request_id' => self::requestId(),
        ], $status, $headers);
    }

    /**
     * The correlation id for the current request, if one has been assigned.
     *
     * Resolved from the container rather than passed in, so every error path —
     * middleware, controller, and the exception renderer, which has no
     * PartnerContext to hand — echoes the same id without threading it
     * through every signature.
     */
    public static function requestId(): ?string
    {
        $context = app(PartnerContext::class);

        return $context->requestId();
    }
}
