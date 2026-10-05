<?php

namespace App\Mail\Orders;

/**
 * Base for the Unfallschaden (accident damage) customer mails. Same template
 * and layout as every other order mail; only the wording differs.
 */
abstract class AccidentDamageEventMail extends OrderEventMail
{
    public function eyebrow(): string
    {
        return 'Unfallschaden';
    }
}