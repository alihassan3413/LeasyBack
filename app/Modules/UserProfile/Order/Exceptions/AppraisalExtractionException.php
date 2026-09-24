<?php

namespace App\Modules\UserProfile\Order\Exceptions;

use App\Modules\UserProfile\Order\Enums\AppraisalExtractionStatus;
use RuntimeException;
use Throwable;

class AppraisalExtractionException extends RuntimeException
{
    public const DOCUMENT_MISSING = 'document_missing';

    public const DOCUMENT_UNREADABLE = 'document_unreadable';

    public const UNSUPPORTED_DOCUMENT = 'unsupported_document';

    public const EXTRACTOR_FAILED = 'extractor_failed';

    public const NO_EXTRACTOR_AVAILABLE = 'no_extractor_available';

    public const INVALID_PROPOSAL = 'invalid_proposal';

    public const INVALID_TRANSITION = 'invalid_transition';

    public const UNEXPECTED_ERROR = 'unexpected_error';

    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function documentMissing(): self
    {
        return new self('The Gutachten document for this extraction no longer exists.', self::DOCUMENT_MISSING);
    }

    public static function documentUnreadable(string $path): self
    {
        return new self("The Gutachten file could not be read from storage: {$path}", self::DOCUMENT_UNREADABLE);
    }

    public static function unsupportedDocument(string $reason): self
    {
        return new self($reason, self::UNSUPPORTED_DOCUMENT);
    }

    public static function extractorFailed(string $message, ?Throwable $previous = null): self
    {
        return new self($message, self::EXTRACTOR_FAILED, [], $previous);
    }

    public static function noExtractorAvailable(array $details = []): self
    {
        return new self('No extractor could produce a proposal for this Gutachten.', self::NO_EXTRACTOR_AVAILABLE, $details);
    }

    public static function invalidProposal(array $errors): self
    {
        return new self('The extracted proposal failed validation.', self::INVALID_PROPOSAL, ['errors' => $errors]);
    }

    public static function invalidTransition(AppraisalExtractionStatus $from, AppraisalExtractionStatus $to): self
    {
        return new self("An extraction cannot move from {$from->value} to {$to->value}.", self::INVALID_TRANSITION);
    }
}
