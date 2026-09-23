<?php

namespace App\Mail\Orders;

class RepairQuotationAvailableMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Ihr Reparaturangebot liegt vor – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Angebot verfügbar';
    }

    public function heading(): string
    {
        return 'Ihr Reparaturangebot liegt zur Freigabe bereit';
    }

    public function paragraphs(): array
    {
        return [
            'für '.$this->vehicleReference().' liegt ein neues Reparaturangebot vor.',
            'Bitte prüfen Sie das Angebot im Portal und erteilen Sie dort Ihre Freigabe. Erst nach Ihrer Freigabe beauftragen wir die Werkstatt.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Angebot prüfen und freigeben';
    }
}
