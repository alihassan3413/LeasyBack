<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\LeasybackOffer;
use App\Models\User;
use App\Notifications\NotificationPayload;
use App\Services\Notifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Staff-facing fan-out for the customer's decision on an offer.
 *
 * The counterpart of PartnerOfferAnnouncer: that one tells a B2B partner's
 * integration, this one tells the humans who staff the system. Both live
 * outside the offer services for the same reason — a call site that forgets a
 * channel is a silent gap, and this one was exactly that. Accepting mailed the
 * customer and fired the partner webhook and reached Admin not at all;
 * rejecting sent nothing to anyone. The only signal either way was the order
 * page's task list, which an admin has to already be looking at.
 *
 * In-app only, deliberately: no mail until there is a decision to send one.
 *
 * `sendNow` for the same reason WorkshopQuotationService::announceSubmission()
 * uses it — the value of this notification is that it appears while the
 * decision is fresh, and the customer's request is finished and committed by
 * the time it runs.
 */
class AdminOfferDecisionAnnouncer
{
    private const COMMENT_PREVIEW = 140;

    public function __construct(private readonly Notifier $notifier) {}

    public function accepted(LeasybackOffer $offer): void
    {
        $total = $offer->final_total_gross === null
            ? null
            : number_format((float) $offer->final_total_gross, 2, ',', '.').' € brutto';

        $this->announce(
            $offer,
            NotificationType::OfferAccepted,
            'Angebot angenommen',
            sprintf(
                'Angebot %d für %s wurde vom Kunden angenommen%s.',
                $offer->offer_sequence,
                $this->subject($offer),
                $total === null ? '' : ': '.$total,
            ),
        );
    }

    public function rejected(LeasybackOffer $offer, ?string $comment = null): void
    {
        $comment = $comment === null ? '' : trim($comment);

        $this->announce(
            $offer,
            NotificationType::OfferRejected,
            'Angebot abgelehnt',
            sprintf(
                'Angebot %d für %s wurde vom Kunden abgelehnt%s.',
                $offer->offer_sequence,
                $this->subject($offer),
                $comment === '' ? '' : ': '.Str::limit($comment, self::COMMENT_PREVIEW),
            ),
        );
    }

    /**
     * What an admin scanning the bell will recognise the order by. The plate
     * first, because that is what every other admin surface leads with, and the
     * Auftragsnummer when the vehicle is gone.
     */
    private function subject(LeasybackOffer $offer): string
    {
        return $offer->order?->vehicle?->license_plate ?: (string) $offer->auftragsnummer;
    }

    private function announce(LeasybackOffer $offer, NotificationType $type, string $title, string $body): void
    {
        $order = $offer->order;

        if ($order === null) {
            return;
        }

        $this->notifier->sendNow(
            $this->admins(),
            NotificationPayload::make(
                $type,
                $title,
                $body,
                route('admin.orders.show', $order->id, false),
                [
                    'order_id' => $order->id,
                    'auftragsnummer' => $offer->auftragsnummer,
                    'offer_id' => $offer->offer_id,
                    'offer_sequence' => $offer->offer_sequence,
                    'offer_status' => $offer->offer_status,
                ],
            ),
        );
    }

    /**
     * Mirrors OrderMessageService::admins() — the same audience, resolved the
     * same way, because "which humans staff this system" is one question.
     *
     * @return Collection<int, User>
     */
    private function admins(): Collection
    {
        return User::where('user_type', UserType::Admin->value)
            ->where('is_active', true)
            ->get();
    }
}
