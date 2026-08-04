<?php

namespace App\Mail\Orders;

class OrderStatusUpdatedMail extends OrderEventMail
{
    public function subjectLine(): string
    {
        return 'Neues Update zu Ihrem Auftrag – '.$this->reference();
    }

    public function eyebrow(): string
    {
        return 'Statusupdate';
    }

    public function heading(): string
    {
        return 'Es gibt ein neues Update zu Ihrem Auftrag';
    }

    public function paragraphs(): array
    {
        $status = $this->data->statusLabel;

        return array_values(array_filter([
            'der Status zu '.$this->vehicleReference().' hat sich geändert.',
            $status !== null ? 'Aktueller Status: '.$status.'.' : null,
            'Alle Details zu Ihrem Auftrag finden Sie jederzeit im Portal.',
        ]));
    }

    public function ctaLabel(): string
    {
        return 'Status ansehen';
    }
}
