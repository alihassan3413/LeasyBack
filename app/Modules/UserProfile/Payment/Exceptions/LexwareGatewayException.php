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
        /** Set when the failure has a meaning the caller must act on, rather than just an HTTP status. */
        public readonly ?string $reason = null,
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

    /**
     * The voucher is still a draft. Lexware refuses to render a document for
     * one (HTTP 406) and offers no API call to finalize it — only its own UI
     * does that — so this is guidance for the caller, not a fault.
     *
     * @param  array<string, mixed>  $errorBody
     */
    public static function notFinalized(string $message, array $errorBody = []): self
    {
        return new self($message, 406, $errorBody, null, 'draft');
    }

    public function isNotFinalized(): bool
    {
        return $this->reason === 'draft';
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
