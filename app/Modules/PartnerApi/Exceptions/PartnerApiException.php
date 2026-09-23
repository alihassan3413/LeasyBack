<?php

namespace App\Modules\PartnerApi\Exceptions;

use App\Modules\PartnerApi\Support\PartnerApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A partner-facing failure with an explicit machine-readable code.
 *
 * Renders itself, so a service in a later phase can throw
 * `PartnerApiException::notFound('vehicle_not_found', '…')` from three layers
 * deep and get the documented envelope without the controller catching
 * anything.
 */
class PartnerApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $type,
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function invalidRequest(string $code, string $message, array $details = []): self
    {
        return new self(PartnerApiResponse::TYPE_INVALID_REQUEST, $code, $message, 400, $details);
    }

    public static function unauthenticated(string $code, string $message): self
    {
        return new self(PartnerApiResponse::TYPE_AUTHENTICATION, $code, $message, 401);
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self(PartnerApiResponse::TYPE_AUTHORIZATION, $code, $message, 403);
    }

    public static function notFound(string $code, string $message): self
    {
        return new self(PartnerApiResponse::TYPE_NOT_FOUND, $code, $message, 404);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self(PartnerApiResponse::TYPE_CONFLICT, $code, $message, 409, $details);
    }

    public function render(Request $request): JsonResponse
    {
        return PartnerApiResponse::error(
            $this->type,
            $this->errorCode,
            $this->getMessage(),
            $this->status,
            $this->details,
        );
    }
}
