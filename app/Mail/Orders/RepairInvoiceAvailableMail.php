<?php

namespace App\Mail\Orders;

class RepairInvoiceAvailableMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Ihre Rechnung zur Reparatur – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Rechnung';
    }

    public function heading(): string
    {
        return 'Ihr Fahrzeug ist fertig – Rechnung liegt vor';
    }

    public function paragraphs(): array
    {
        return [
            'die Arbeiten an '.$this->vehicleReference().' sind abgeschlossen. Ihre Rechnung finden Sie in Ihrem Portal.',
            'Sobald der Zahlungseingang bestätigt ist, können Sie Ihr Fahrzeug abholen. Über den Button unten bezahlen Sie die Rechnung direkt und sicher.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Rechnung jetzt bezahlen';
    }

    public function secondaryLabel(): ?string
    {
        return $this->data->documentUrl === null ? null : 'Rechnung im Portal ansehen';
    }

    public function secondaryUrl(): ?string
    {
        return $this->data->documentUrl;
    }
}
