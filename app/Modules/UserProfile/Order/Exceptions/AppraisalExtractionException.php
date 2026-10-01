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

    /*
     * The reasons a Gutachten cannot be read. They used to share
     * UNSUPPORTED_DOCUMENT, which left the admin card unable to say whether a
     * run hit a scan, a broken PDF or an unknown layout -- the three need
     * different answers, so each carries its own code.
     */
    public const NOT_A_PDF = 'not_a_pdf';

    public const PDF_TOO_LARGE = 'pdf_too_large';

    public const NO_TEXT_LAYER = 'no_text_layer';

    public const PDF_UNREADABLE = 'pdf_unreadable';

    public const NO_POSITIONS_FOUND = 'no_positions_found';

    public const EXTRACTOR_FAILED = 'extractor_failed';

    public const NO_EXTRACTOR_AVAILABLE = 'no_extractor_available';

    public const INVALID_PROPOSAL = 'invalid_proposal';

    public const INVALID_TRANSITION = 'invalid_transition';

    public const UNEXPECTED_ERROR = 'unexpected_error';

    public function __construct(
        string $message,
        public readonly string $errorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function documentMissing(): self
    {
        return new self('The Gutachten document for this extraction no longer exists.', self::DOCUMENT_MISSING);
    }

    public static function documentUnreadable(): self
    {
        return new self('The Gutachten file could not be read from storage.', self::DOCUMENT_UNREADABLE);
    }

    public static function unsupportedDocument(string $reason, string $errorCode = self::UNSUPPORTED_DOCUMENT): self
    {
        return new self($reason, $errorCode);
    }

    public static function extractorFailed(string $message, ?Throwable $previous = null): self
    {
        return new self($message, self::EXTRACTOR_FAILED, $previous);
    }

    public static function noExtractorAvailable(): self
    {
        return new self('No extractor could produce a proposal for this Gutachten.', self::NO_EXTRACTOR_AVAILABLE);
    }

    public static function invalidProposal(): self
    {
        return new self('The extracted proposal failed validation.', self::INVALID_PROPOSAL);
    }

    public static function invalidTransition(AppraisalExtractionStatus $from, AppraisalExtractionStatus $to): self
    {
        return new self("An extraction cannot move from {$from->value} to {$to->value}.", self::INVALID_TRANSITION);
    }
}
