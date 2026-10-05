<?php

namespace App\Mail\Orders;

class AppraisalScheduledMail extends AppraisalEventMail
{
    public function subjectLine(): string
    {
        return $this->orderReference().': Termin bestätigt';
    }

    public function heading(): string
    {
        return 'Gutachten terminiert';
    }

    /**
     * @return list<string>
     */
    public function paragraphs(): array
    {
        return [
            'der Termin für '.$this->orderName().' ist bestätigt — einschließlich eines gewünschten Transports.',
            'Datum, Zeitfenster und Prüfstelle finden Sie im Portal.',
        ];
    }
}
