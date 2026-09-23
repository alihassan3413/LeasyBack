<?php

namespace App\Console\Commands;

use App\Modules\UserProfile\Payment\Services\B2cFeeDeadlines;
use Illuminate\Console\Command;

class ProcessB2cFeeDeadlines extends Command
{
    protected $signature = 'b2c:process-fee-deadlines';

    protected $description = 'Trigger the B2C fee for offers with no response and repairs that never started';

    public function handle(B2cFeeDeadlines $deadlines): int
    {
        $triggered = $deadlines->process();

        $this->info(sprintf('%d B2C fee(s) triggered.', $triggered));

        return self::SUCCESS;
    }
}
