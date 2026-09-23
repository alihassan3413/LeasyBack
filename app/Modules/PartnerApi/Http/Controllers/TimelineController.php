<?php

namespace App\Modules\PartnerApi\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PartnerApi\Services\PartnerExternalReferenceRegistry;
use App\Modules\PartnerApi\Services\PartnerResourceLocator;
use App\Modules\PartnerApi\Services\PartnerTimelineBuilder;
use App\Modules\PartnerApi\Support\PartnerApiResponse;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response;

/**
 * Where an order stands, and how it got there.
 *
 * Two endpoints rather than one, because they answer different questions at
 * very different costs. `/status` is what a poll loop wants — the current
 * status and stage, cheap enough to ask every minute. `/timeline` is the whole
 * fifteen-stage board plus the transition history, which is what a dashboard
 * renders once.
 *
 * Both are strictly read-only, and there is no companion write endpoint: an
 * order advances by what happens to the vehicle, and every legal edge belongs
 * to TransitionOrderStatus (§12.12.4 decision 1).
 */
#[Group(name: 'Partner API')]
class TimelineController extends Controller
{
    public function __construct(
        private readonly PartnerResourceLocator $locator,
        private readonly PartnerTimelineBuilder $timeline,
    ) {}

    /**
     * The current status of an order.
     */
    #[Endpoint(
        title: 'Get order status',
        description: 'The current status and timeline stage of one order — the cheap call to poll. '
            .'`status.code` is the machine status, `stage` the timeline stage it corresponds to, and '
            .'`stage_sequence` its position in the fifteen-stage flow. A cancelled order reports '
            .'`is_cancelled: true` and no stage: it stopped where it stopped, and `GET /timeline` '
            .'shows where.'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'order' => [
                    'id' => '4b6e0a52-9c3d-4f77-8f2a-77a1c0f9b3d2',
                    'external_id' => 'PO-2026-0042',
                    'reference' => 'BXY123260806',
                ],
                'status' => [
                    'code' => 'vehicle_collected',
                    'label' => 'Fahrzeug abgeholt',
                    'is_open' => true,
                    'is_cancelled' => false,
                    'changed_at' => '2026-08-06T09:14:02+00:00',
                    'stage' => 'vehicle_collected',
                    'stage_sequence' => 4,
                    'stage_count' => 15,
                ],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function status(Request $request, string $order): JsonResponse
    {
        $found = $this->locator->findOrderOrFail($order);

        return PartnerApiResponse::success([
            'order' => $this->orderStub($found),
            'status' => $this->timeline->forOrder($found)['status'],
        ]);
    }

    /**
     * The fifteen-stage timeline of an order.
     */
    #[Endpoint(
        title: 'Get order timeline',
        description: 'The full return-process timeline: fifteen stages in fixed order, each with a '
            .'stable `code`, its position, whether it is done, and when it happened. `stages[].state` '
            .'is one of `completed`, `current`, `upcoming` or `cancelled`. `history` is the raw '
            .'status trail behind it, newest first. '
            .'Stage codes are part of the API contract — labels and descriptions are German prose for '
            .'display and may be reworded; branch on `code`.'
    )]
    #[Response(
        status: 200,
        content: [
            'data' => [
                'order' => [
                    'id' => '4b6e0a52-9c3d-4f77-8f2a-77a1c0f9b3d2',
                    'external_id' => 'PO-2026-0042',
                    'reference' => 'BXY123260806',
                ],
                'current_stage' => 'vehicle_collected',
                'is_cancelled' => false,
                'stages' => [[
                    'code' => 'order_received',
                    'label' => 'Auftrag eingegangen',
                    'description' => 'Ihre Rückgabeanfrage ist bei Leasyback eingegangen.',
                    'sequence' => 1,
                    'state' => 'completed',
                    'completed' => true,
                    'is_current' => false,
                    'occurred_at' => '2026-08-01T08:00:00+00:00',
                ]],
                'history' => [[
                    'status' => 'vehicle_collected',
                    'status_label' => 'Fahrzeug abgeholt',
                    'previous_status' => 'confirmed',
                    'occurred_at' => '2026-08-06T09:14:02+00:00',
                ]],
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    #[Response(
        status: 404,
        content: [
            'error' => [
                'type' => 'not_found',
                'code' => 'order_not_found',
                'message' => 'No order with that id exists in the company this token belongs to.',
            ],
            'request_id' => '9f1c2e4a-4c1e-4a9b-9f0e-2b1d5a7c3e11',
        ]
    )]
    public function timeline(Request $request, string $order): JsonResponse
    {
        $found = $this->locator->findOrderOrFail($order);
        $built = $this->timeline->forOrder($found);

        return PartnerApiResponse::success([
            'order' => $this->orderStub($found),
            'current_stage' => $built['current_stage'],
            'is_cancelled' => $built['is_cancelled'],
            'stages' => $built['stages'],
            'history' => $built['history'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderStub(LeasybackOrder $order): array
    {
        return [
            'id' => $order->id,
            'external_id' => $this->locator->externalIdFor(
                PartnerExternalReferenceRegistry::TYPE_ORDER,
                $order->id,
            ),
            'reference' => $order->auftragsnummer,
        ];
    }
}
