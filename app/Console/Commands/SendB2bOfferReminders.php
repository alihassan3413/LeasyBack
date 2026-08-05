<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Modules\UserProfile\Order\Services\B2bOfferService;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Notifications\NotificationPayload;
use App\Services\Mail\OrderMailer;
use App\Services\Notifier;
use Illuminate\Console\Command;

/**
 * b2b.txt §18: a reminder while customer action is required, at most every
 * 24 h, stopping immediately on acceptance, rejection, cancellation or expiry.
 *
 * All four stop conditions live in B2bOfferService::offersDueForReminder() as
 * exclusions, so this command cannot send to an offer that is no longer
 * actionable. Spacing is enforced by stamping `last_reminder_sent_at`
 * immediately after each send, which is also what makes a second run inside
 * the same window a no-op.
 *
 * B2B only: reminders are driven by `b2b_offer_presentations`, and a B2C offer
 * has no row there.
 */
class SendB2bOfferReminders extends Command
{
    protected $signature = 'b2b:send-offer-reminders';

    protected $description = 'Send 24h reminders for B2B repair offers still awaiting a customer decision';

    public function handle(
        B2bOfferService $b2bOfferService,
        OrderMailer $orderMailer,
        Notifier $notifier,
        VehicleScopeService $vehicleScope,
    ): int {
        $due = $b2bOfferService->offersDueForReminder();

        if ($due === []) {
            $this->info('No B2B offers are due a reminder.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($due as $offer) {
            $vehicle = $offer->order?->vehicle;

            if ($vehicle === null) {
                continue;
            }

            $orderMailer->offerApprovalReminder($offer);

            $notifier->send(
                $vehicleScope->resolveOwnerUsers($vehicle),
                NotificationPayload::make(
                    NotificationType::CustomerActionRequired,
                    'Freigabe erforderlich',
                    sprintf('Für %s wartet ein Reparaturangebot auf Ihre Freigabe.', $vehicle->license_plate),
                    '/dashboard',
                    ['auftragsnummer' => $offer->auftragsnummer, 'offer_id' => $offer->offer_id],
                ),
            );

            $b2bOfferService->markReminderSent($offer);
            $sent++;
        }

        $this->info(sprintf('Sent %d B2B offer reminder(s).', $sent));

        return self::SUCCESS;
    }
}
