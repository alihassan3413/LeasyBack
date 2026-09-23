<?php

namespace App\Modules\PartnerApi\Http\Resources;

use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;

/**
 * A customer offer as a partner sees it.
 *
 * The positions and totals come from the presentation **snapshot**, not from
 * the live appraisal positions — see PartnerOfferCatalog. An accepted offer
 * therefore keeps reporting what was accepted, whatever happens to the
 * underlying positions afterwards.
 *
 * Absent by design, one allow-listed field at a time:
 * - `workshop_quotation_id`, and with it any route to the workshop comparison;
 * - `appraisal_position_id` on a line — an internal key with no partner use
 *   that would invite lookups against endpoints that do not exist;
 * - `damage_image_document_ids` — inspection material, not offer content;
 * - every `*_gross` column (§9: the B2B process is net-only).
 *
 * Statuses are the internal machine codes (`published`, `selected`,
 * `rejected`) rather than a partner-specific vocabulary. `selected` is our
 * word for accepted, and `is_accepted` is exposed alongside it so a partner
 * branches on a boolean instead of memorising that.
 */
final class PartnerOfferResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(
        LeasybackOffer $offer,
        B2bOfferPresentation $presentation,
        LeasybackOrder $order,
    ): array {
        $status = (string) $offer->offer_status;

        return [
            'id' => $offer->offer_id,
            'order' => [
                'id' => $order->id,
                'reference' => $order->auftragsnummer,
            ],
            'sequence' => $offer->offer_sequence,
            'status' => $status,
            'is_accepted' => $status === 'selected',
            'is_rejected' => $status === 'rejected',
            // Validity is a whole-day window: an offer is good through the end
            // of `valid_until`, which is why this is the model's own answer
            // rather than a comparison against a timestamp.
            'is_expired' => $presentation->isExpired(),
            'valid_until' => $presentation->valid_until?->toDateString(),
            'currency' => 'EUR',
            'totals' => [
                'appraisal_total_net' => (string) $presentation->appraisal_total_net,
                'repair_total_net' => (string) $presentation->repair_total_net,
                'saving_net' => (string) $presentation->saving_net,
            ],
            'positions' => self::positions($presentation),
            'customer_note' => $presentation->customer_note,
            'customer_comment' => $presentation->customer_comment,
            'published_at' => $offer->published_at?->toIso8601String(),
            'accepted_at' => $offer->selected_at?->toIso8601String(),
            'rejected_at' => $presentation->rejected_at?->toIso8601String(),
            // When the snapshot was frozen. Null only for an offer that
            // somehow reached a customer-facing status without going through
            // publication.
            'presented_at' => $presentation->presented_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function positions(B2bOfferPresentation $presentation): array
    {
        return array_values(array_map(fn (array $line) => [
            'component' => $line['component'] ?? null,
            'damage_description' => $line['damage_description'] ?? null,
            'appraisal_amount_net' => self::amount($line['appraisal_amount_net'] ?? null),
            'repair_amount_net' => self::amount($line['repair_amount_net'] ?? null),
            'saving_net' => self::amount($line['saving_net'] ?? null),
            'repair_method' => $line['repair_method'] ?? null,
            // The workshop declared this position beyond repair. Carried so
            // the partner sees the position at all — it is priced at nothing
            // and would otherwise look like an omission.
            'not_repairable' => (bool) ($line['not_repairable'] ?? false),
        ], $presentation->lines ?? []));
    }

    private static function amount(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
