<?php

namespace App\Modules\UserProfile\Order\Data;

final readonly class AppraisalExtractionProposal
{
    public function __construct(
        public array $lines,
        public ?string $appraisalNumber = null,
        public ?string $appraisalDate = null,
        public ?string $vin = null,
        public ?string $currency = null,
        public ?string $totalNet = null,
    ) {}

    public static function fromArray(array $proposal): self
    {
        return new self(
            lines: array_map(
                fn (mixed $line) => AppraisalProposalLine::fromArray(is_array($line) ? $line : []),
                array_values(is_array($proposal['lines'] ?? null) ? $proposal['lines'] : []),
            ),
            appraisalNumber: isset($proposal['appraisal_number']) ? (string) $proposal['appraisal_number'] : null,
            appraisalDate: isset($proposal['appraisal_date']) ? (string) $proposal['appraisal_date'] : null,
            vin: isset($proposal['vin']) ? (string) $proposal['vin'] : null,
            currency: isset($proposal['currency']) ? (string) $proposal['currency'] : null,
            totalNet: isset($proposal['total_net']) ? (string) $proposal['total_net'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'appraisal_number' => $this->appraisalNumber,
            'appraisal_date' => $this->appraisalDate,
            'vin' => $this->vin,
            'currency' => $this->currency,
            'total_net' => $this->totalNet,
            'lines' => array_map(fn (AppraisalProposalLine $line) => $line->toArray(), $this->lines),
        ];
    }
}
