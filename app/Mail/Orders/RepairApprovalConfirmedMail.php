<?php

namespace App\Mail\Orders;

class RepairApprovalConfirmedMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Ihre Reparaturfreigabe ist bestätigt – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Freigabe bestätigt';
    }

    public function heading(): string
    {
        return 'Ihre Reparaturfreigabe ist bestätigt';
    }

    public function paragraphs(): array
    {
        return [
            'vielen Dank für Ihre Freigabe des Reparaturangebots zu '.$this->vehicleReference().'.',
            'Wir beauftragen nun die Werkstatt und informieren Sie, sobald die Reparatur beginnt.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Freigabe ansehen';
    }
}
