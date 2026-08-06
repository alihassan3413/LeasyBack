<?php

namespace App\Modules\PartnerApi\Enums;

/**
 * What an Idempotency-Key turned out to be.
 */
enum PartnerIdempotencyState: string
{
    /** First time this key has been seen — run the request. */
    case Fresh = 'fresh';

    /** Same key, same request: serve the stored response, do not run again. */
    case Replay = 'replay';

    /** Same key, different request: the caller has a bug, refuse it. */
    case Conflict = 'conflict';

    /** The original request is still running; a retry must wait. */
    case InProgress = 'in_progress';
}
