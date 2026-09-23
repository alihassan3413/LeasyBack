<?php

namespace App\Mail\Orders;

class OrderCreatedAdminMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Neuer Auftrag eingegangen – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Interne Benachrichtigung';
    }

    public function heading(): string
    {
        return 'Neuer Auftrag eingegangen';
    }

    public function paragraphs(): array
    {
        return [
            'im LeasyBack-Portal wurde ein neuer Auftrag angelegt. Die wichtigsten Eckdaten sind unten zusammengefasst.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Auftrag im Admin-Bereich öffnen';
    }
}
