<?php

namespace App\Mail\Orders;

class AppraisalRequestedMail extends AppraisalEventMail
{
    public function subjectLine(): string
    {
        return $this->orderReference().' ist eingegangen';
    }

    public function heading(): string
    {
        return 'Gutachten angefragt';
    }

    /**
     * @return list<string>
     */
    public function paragraphs(): array
    {
        return [
            'vielen Dank für Ihren Auftrag. '.$this->orderReference().' ist bei uns eingegangen.',
            'Wir prüfen Ihren Wunschtermin, koordinieren die Prüfstelle und — falls gewünscht — den Transport und melden uns mit einer Terminbestätigung. Den aktuellen Stand sehen Sie jederzeit im Portal.',
        ];
    }
}
