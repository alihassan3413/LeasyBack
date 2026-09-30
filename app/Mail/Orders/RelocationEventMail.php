<?php

namespace App\Mail\Orders;

/**
 * Base for the Überführung (vehicle relocation) customer mails. Uses the same
 * template, data and layout as every other order mail; only the wording is
 * about moving a vehicle from A to B instead of a leasing return.
 */
abstract class RelocationEventMail extends OrderEventMail
{
    public function eyebrow(): string
    {
        return 'Überführung';
    }
}