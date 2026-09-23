<?php

namespace App\Modules\UserProfile\Payment\Jobs;

use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Services\RepairBillingWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IssueRepairInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $orderId) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        if (! in_array((string) config('services.lexware.mode'), ['test', 'live'], true)) {
            return;
        }

        $order = LeasybackOrder::find($this->orderId);

        if ($order === null) {
            return;
        }

        app(RepairBillingWorkflow::class)->issueFor($order, TransitionOrderStatus::isB2bOrder($order));
    }
}
