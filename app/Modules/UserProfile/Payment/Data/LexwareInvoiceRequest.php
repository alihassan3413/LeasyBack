<?php

namespace App\Modules\UserProfile\Payment\Data;

use App\Support\PortalTimestamp;
use Carbon\CarbonImmutable;

final readonly class LexwareInvoiceRequest
{
    /**
     * @param  list<LexwareInvoiceLine>  $lineItems
     */
    public function __construct(
        public string $contactId,
        public array $lineItems,
        public string $voucherDate,
        public string $performanceDate,
        public string $introduction = '',
        public string $remark = '',
        public string $title = 'Rechnung',
        public string $currency = 'EUR',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'voucherDate' => self::businessDay($this->voucherDate),
            'address' => ['contactId' => $this->contactId],
            'lineItems' => array_map(
                fn (LexwareInvoiceLine $line) => $line->toPayload($this->currency),
                $this->lineItems,
            ),
            'totalPrice' => ['currency' => $this->currency],
            'taxConditions' => ['taxType' => 'net'],
            'shippingConditions' => [
                'shippingDate' => self::businessDay($this->performanceDate),
                'shippingType' => 'service',
            ],
            'title' => $this->title,
            'introduction' => $this->introduction,
            'remark' => $this->remark,
        ];
    }

    private static function businessDay(string $date): string
    {
        return CarbonImmutable::parse($date, PortalTimestamp::TIME_ZONE)
            ->setTime(12, 0)
            ->format('Y-m-d\TH:i:s.vP');
    }
}
