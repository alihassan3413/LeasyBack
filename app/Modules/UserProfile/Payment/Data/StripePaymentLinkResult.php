<?php

namespace App\Modules\UserProfile\Payment\Data;

final readonly class StripePaymentLinkResult
{
    public function __construct(
        public string $id,
        public string $url,
        public bool $active = true,
    ) {}
}
