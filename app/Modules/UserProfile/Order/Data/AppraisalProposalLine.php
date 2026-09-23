<?php

namespace App\Modules\UserProfile\Order\Data;

final readonly class AppraisalProposalLine
{
    public function __construct(
        public string $component,
        public string $originalAmountNet,
        public ?string $chargeableAmountNet = null,
        public ?string $damageDescription = null,
        public ?string $repairMethod = null,
        public ?int $pageNumber = null,
        public ?string $sourceText = null,
        public ?float $confidence = null,
        public ?int $damageNumber = null,
    ) {}

    public static function fromArray(array $line): self
    {
        return new self(
            component: (string) ($line['component'] ?? ''),
            originalAmountNet: (string) ($line['original_amount_net'] ?? ''),
            chargeableAmountNet: isset($line['chargeable_amount_net']) ? (string) $line['chargeable_amount_net'] : null,
            damageDescription: isset($line['damage_description']) ? (string) $line['damage_description'] : null,
            repairMethod: isset($line['repair_method']) ? (string) $line['repair_method'] : null,
            pageNumber: isset($line['page_number']) ? (int) $line['page_number'] : null,
            sourceText: isset($line['source_text']) ? (string) $line['source_text'] : null,
            confidence: isset($line['confidence']) ? (float) $line['confidence'] : null,
            damageNumber: isset($line['damage_number']) ? (int) $line['damage_number'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'damage_description' => $this->damageDescription,
            'original_amount_net' => $this->originalAmountNet,
            'chargeable_amount_net' => $this->chargeableAmountNet,
            'repair_method' => $this->repairMethod,
            'page_number' => $this->pageNumber,
            'source_text' => $this->sourceText,
            'confidence' => $this->confidence,
            'damage_number' => $this->damageNumber,
        ];
    }
}
