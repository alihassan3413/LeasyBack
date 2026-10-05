<?php

namespace App\Mail\Orders;

class AppraisalCompletedMail extends AppraisalEventMail
{
    public function subjectLine(): string
    {
        return $this->orderReference().' ist abgeschlossen';
    }

    public function heading(): string
    {
        return 'Gutachten abgeschlossen';
    }

    /**
     * @return list<string>
     */
    public function paragraphs(): array
    {
        return [
            'das Gutachten zu '.$this->orderName().' ist abgeschlossen.',
            'Den Zustandsbericht finden Sie im Portal in Ihrem Auftrag.',
        ];
    }
}
