<?php

namespace App\Mail\Orders;

class RepairPaymentReceivedMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Zahlung erhalten – Ihr Fahrzeug kann abgeholt werden – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Zahlung erhalten';
    }

    public function heading(): string
    {
        return 'Zahlung erhalten – Ihr Fahrzeug kann abgeholt werden';
    }

    public function paragraphs(): array
    {
        return [
            'vielen Dank – Ihre Zahlung für die Reparatur an '.$this->vehicleReference().' ist bei uns eingegangen.',
            'Ihr Fahrzeug kann jetzt abgeholt werden. Bitte bringen Sie Ihre Fahrzeugpapiere und einen gültigen Ausweis mit.',
        ];
    }

    public function ctaLabel(): string
    {
        return 'Abholung ansehen';
    }
}
