<?php

namespace App\Modules\UserProfile\B2B\Services;

use App\Modules\UserProfile\B2B\Models\B2B;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The per-company service fee agreement (b2b.txt §13), stored on the company
 * row as an amount and the date it takes effect.
 */
class B2bServiceFeeService
{
    /** §13: the default until a company's fee is changed. */
    public const DEFAULT_AMOUNT = '295.00';

    public function update(B2B $company, string $amount, string $effectiveFrom): void
    {
        $company->update([
            'service_fee_amount' => $amount,
            'service_fee_effective_from' => $effectiveFrom,
        ]);
    }

    /**
     * The fee that applies to this company on `$date`: the agreed amount once
     * its effective-from date has been reached, the default before that.
     */
    public function amountOn(string $b2bId, CarbonInterface $date): string
    {
        $company = DB::table('b2b')->where('b2b_id', $b2bId)->first(['service_fee_amount', 'service_fee_effective_from']);

        if ($company === null || $company->service_fee_amount === null) {
            return self::DEFAULT_AMOUNT;
        }

        $effectiveFrom = $company->service_fee_effective_from === null
            ? null
            : substr((string) $company->service_fee_effective_from, 0, 10);

        if ($effectiveFrom !== null && $effectiveFrom > $date->toDateString()) {
            return self::DEFAULT_AMOUNT;
        }

        return number_format((float) $company->service_fee_amount, 2, '.', '');
    }
}
