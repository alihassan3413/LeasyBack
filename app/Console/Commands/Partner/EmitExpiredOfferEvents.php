<?php

namespace App\Console\Commands\Partner;

use App\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Services\B2bOfferService;
use Illuminate\Console\Command;

/**
 * The writer that `offer.expired` would otherwise not have.
 *
 * Every other offer outcome is somebody pressing a button, so it has an obvious
 * emit point. Expiry is a date passing with nothing happening: §10.1 already
 * refuses to accept an offer past `valid_until`, but it decides that at read
 * time, so there is no write to hang an event on.
 *
 * This command is that write. It stamps `expired_notified_at` — the one and
 * only purpose of that column — which is what makes the event exactly-once: an
 * offer already stamped is not a candidate on the next run. Nothing else reads
 * the column, so how expiry itself is determined is unchanged everywhere.
 *
 * Only offers still sitting at `published` are considered. One that was
 * accepted, rejected, withdrawn or superseded already got the event that
 * describes what really happened to it, and a later expiry notice would
 * contradict it.
 */
class EmitExpiredOfferEvents extends Command
{
    protected $signature = 'partner:webhooks:emit-expired-offers
                            {--limit=500 : Maximum offers to process in one run}';

    protected $description = 'Emit offer.expired for presented offers whose validity date has passed';

    public function handle(B2bOfferService $offers): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $presentations = B2bOfferPresentation::query()
            ->whereNotNull('presented_at')
            ->whereNull('expired_notified_at')
            ->whereNull('rejected_at')
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->orderBy('valid_until')
            ->limit($limit)
            ->get();

        $emitted = 0;

        foreach ($presentations as $presentation) {
            $offer = LeasybackOffer::where('offer_id', $presentation->offer_id)->first();

            // Stamped either way. An offer that has moved on is not a candidate
            // again on every subsequent run, and the stamp is the only record
            // that this command has considered it.
            $presentation->forceFill(['expired_notified_at' => now()])->save();

            if ($offer === null || $offer->offer_status !== 'published') {
                continue;
            }

            $offers->announceOffer('expired', $offer);
            $emitted++;
        }

        $this->info(sprintf(
            'Considered %d expired presentation(s); emitted %d offer.expired event(s).',
            $presentations->count(),
            $emitted,
        ));

        return self::SUCCESS;
    }
}
