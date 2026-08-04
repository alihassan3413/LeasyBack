<?php

namespace App\Mail\Orders;

class OrderCreatedCustomerMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Ihr Auftrag wurde angelegt – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Auftrag angelegt';
    }

    public function heading(): string
    {
        return 'Ihr Auftrag wurde angelegt';
    }

    public function paragraphs(): array
    {
        return [
            'wir haben Ihren Auftrag zu '.$this->vehicleReference().' erfolgreich angelegt.',
            'Alle weiteren Schritte – von der Terminbestätigung bis zur Abholung – können Sie jederzeit im Portal verfolgen. Wir informieren Sie automatisch, sobald es ein neues Update gibt.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Auftrag ansehen';
    }
}
