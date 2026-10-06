<?php

namespace App\Mail\Orders;

/**
 * Base for the Vehicle Condition Appraisal (Gutachten) customer mails. An
 * appraisal order can cover several vehicles, so the wording refers to the
 * order number rather than to a single licence plate.
 */
abstract class AppraisalEventMail extends OrderEventMail
{
    public function eyebrow(): string
    {
        return 'Gutachten';
    }

    /** "Gutachtenauftrag AUF-123" — for use inside a sentence. */
    protected function orderName(): string
    {
        return $this->data->orderNumber !== null
            ? 'Gutachtenauftrag '.$this->data->orderNumber
            : 'Ihren Gutachtenauftrag';
    }

    protected function orderReference(): string
    {
        return $this->data->orderNumber !== null
            ? 'Ihr Gutachtenauftrag '.$this->data->orderNumber
            : 'Ihr Gutachtenauftrag';
    }
}
