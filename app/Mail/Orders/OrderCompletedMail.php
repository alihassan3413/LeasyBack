<?php

namespace App\Mail\Orders;

class OrderCompletedMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Ihr Auftrag ist abgeschlossen – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Auftrag abgeschlossen';
    }

    public function heading(): string
    {
        return 'Der Rückgabeprozess ist abgeschlossen';
    }

    public function paragraphs(): array
    {
        return [
            'der Rückgabeprozess für '.$this->vehicleReference().' ist abgeschlossen.',
            'Alle Unterlagen und der vollständige Verlauf stehen Ihnen weiterhin im Portal zur Verfügung.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Auftrag ansehen';
    }
}
