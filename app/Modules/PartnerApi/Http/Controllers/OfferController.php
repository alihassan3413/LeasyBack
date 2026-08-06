<?php

namespace App\Modules\PartnerApi\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PartnerApi\Http\Resources\PartnerOfferResource;
use App\Modules\PartnerApi\Services\PartnerOfferCatalog;
use App\Modules\PartnerApi\Services\PartnerResourceLocator;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

/**
 * The repair offers a company was presented with (§10).
 *
 * Read-only, and deliberately so. There is no accept and no reject endpoint in
 * this phase: acceptance moves real money and commissions a workshop, the
 * portal's own acceptance path carries an expiry check and an audit trail
 * (§10.1), and exposing a second way in before that is settled would be the
 * offer-flow equivalent of a partner-writable order status. Partners read
 * offers; a human accepts them in the portal.
 *
 * An accepted offer reports its frozen snapshot, not the live appraisal
 * positions — see PartnerOfferCatalog for why that distinction is the whole
 * point of the presentation row.
 */
#[Group(name: 'Partner API')]
class OfferController extends Controller
{
    public function __construct(
        private readonly PartnerResourceLocator $locator,
        private readonly PartnerOfferCatalog $offers,
    ) {}

    /**
     * List an order's offers.
     */
    #[Endpoint(
        title: 'List order offers',
        description: 'Every offer that has actually been presented for one order, in the sequence it '
            .'was presented. Drafts and withdrawn offers are not included — they were never shown to '
            .'anyone. All amounts are net; the B2B process has no gross figures. '
            .'An offer that has been accepted (`status: selected`) reports the positions and totals as '
            .'they stood when it was accepted, not as they stand now.'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'offers' => [[
                    'id' => 'f0b1c2d3-e4f5-4a6b-8c9d-0e1f2a3b4c5d',
                    'order' => [
                        'id' => '4b6e0a52-9c3d-4f77-8f2a-77a1c0f9b3d2',
                        'reference' => 'BXY123260806',
                    ],
                    'sequence' => 1,
                    'status' => 'published',
                    'is_accepted' => false,
                    'is_rejected' => false,
                    'is_expired' => false,
                    'valid_until' => '2026-08-20',
                    'currency' => 'EUR',
                    'totals' => [
                        'appraisal_total_net' => '1200.00',
                        'repair_total_net' => '820.00',
                        'saving_net' => '380.00',
                    ],
                    'positions' => [[
                        'component' => 'Stoßfänger vorne',
                        'damage_description' => 'Kratzer, 20 cm',
                        'appraisal_amount_net' => '400.00',
                        'repair_amount_net' => '260.00',
                        'saving_net' => '140.00',
                        'repair_method' => 'Smart Repair',
                        'not_repairable' => false,
                    ]],
                    'customer_note' => null,
                    'customer_comment' => null,
                    'published_at' => '2026-08-06T09:14:02+00:00',
                    'accepted_at' => null,
                    'rejected_at' => null,
                    'presented_at' => '2026-08-06T09:14:02+00:00',
                ]],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function forOrder(Request $request, string $order): JsonResponse
    {
        $found = $this->locator->findOrderOrFail($order);

        return PartnerApiResponse::success([
            'offers' => $this->offers->forOrder($found)
                ->map(fn (array $pair) => PartnerOfferResource::toArray(
                    $pair['offer'],
                    $pair['presentation'],
                    $found,
                ))
                ->all(),
        ]);
    }

    /**
     * Retrieve one offer.
     */
    #[Endpoint(
        title: 'Get an offer',
        description: 'An offer outside this token’s company — and equally a draft or withdrawn one, '
            .'which no customer has seen — answers 404.'
    )]
    #[Response(
        status: 404,
        content: [
            'error' => [
                'type' => 'not_found',
                'code' => 'offer_not_found',
                'message' => 'No offer with that id exists in the company this token belongs to.',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function show(Request $request, string $offer): JsonResponse
    {
        $found = $this->offers->findOrFail($offer);

        return PartnerApiResponse::success([
            'offer' => PartnerOfferResource::toArray(
                $found['offer'],
                $found['presentation'],
                $found['order'],
            ),
        ]);
    }
}
