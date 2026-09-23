<?php

namespace App\Modules\PartnerApi\Jobs;

use App\Modules\PartnerApi\Enums\PartnerWebhookDeliveryStatus;
use App\Modules\PartnerApi\Models\PartnerWebhookDelivery;
use App\Modules\PartnerApi\Services\PartnerWebhookDeliverer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One attempt at one delivery.
 *
 * `tries = 1` on purpose. The retry schedule is ours — persisted on the
 * delivery row, visible to the partner over `/deliveries`, and quotable as a
 * fixed table — and letting the queue retry as well would produce two
 * overlapping schedules and an attempt count that does not match what happened.
 * A job that throws is a bug in this module; a partner endpoint that fails is
 * an outcome the deliverer records and reschedules itself.
 */
class DeliverPartnerWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $deliveryId) {}

    public function handle(PartnerWebhookDeliverer $deliverer): void
    {
        $delivery = PartnerWebhookDelivery::find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        // A delivery that has already succeeded is not re-sent, whatever put
        // this job back on the queue — a duplicated dispatch, a sweeper, an
        // at-least-once queue driver.
        if ($delivery->status === PartnerWebhookDeliveryStatus::Succeeded) {
            return;
        }

        $deliverer->deliver($delivery);
    }
}
