<?php

namespace App\Modules\UserProfile\Payment\Data;

final readonly class LexwareInvoiceLine
{
    public function __construct(
        public string $name,
        public int $netAmountCents,
        public int $taxRatePercentage,
        public float $quantity = 1.0,
        public string $description = '',
        public string $unitName = 'Stück',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(string $currency): array
    {
        return [
            'type' => 'custom',
            'name' => $this->name,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unitName' => $this->unitName,
            'unitPrice' => [
                'currency' => $currency,
                'netAmount' => $this->netAmountCents / 100,
                'taxRatePercentage' => $this->taxRatePercentage,
            ],
            'discountPercentage' => 0,
        ];
    }
}
