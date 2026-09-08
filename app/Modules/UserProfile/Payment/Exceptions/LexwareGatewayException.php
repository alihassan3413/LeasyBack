<?php

namespace App\Modules\UserProfile\Payment\Exceptions;

use RuntimeException;
use Throwable;

class LexwareGatewayException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errorBody
     */
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly array $errorBody = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(string $message): self
    {
        return new self($message);
    }

    /**
     * @param  array<string, mixed>  $errorBody
     */
    public static function apiError(string $message, ?int $httpStatus = null, array $errorBody = [], ?Throwable $previous = null): self
    {
        return new self($message, $httpStatus, $errorBody, $previous);
    }

    public static function transportError(string $message, ?Throwable $previous = null): self
    {
        return new self($message, null, [], $previous);
    }

    public function isConfigurationError(): bool
    {
        return $this->httpStatus === null && $this->getPrevious() === null;
    }

    public function isTransportFailure(): bool
    {
        return $this->httpStatus === null && $this->getPrevious() !== null;
    }
}
