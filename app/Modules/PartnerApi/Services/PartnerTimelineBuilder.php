<?php

namespace App\Modules\PartnerApi\Services;

use App\Enums\OrderStatus;
use App\Modules\UserProfile\Offer\Models\LeasybackOffer;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderStatusUpdate;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use App\Support\OrderStatusLabel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The §15 fifteen-stage B2B timeline, server-side.
 *
 * The portal's timeline is `getB2bOrderFlowSteps()` in
 * resources/js/lib/customerOrderFlow.ts — TypeScript that runs in the
 * customer's browser over an already-assembled Inertia payload. A machine
 * client has no browser, so the stage *derivation* is reproduced here from the
 * same inputs (`leasyback_order_status_updates`, the published report
 * documents, the customer offer) and in the same order. Nothing new is
 * invented: the sequence, the status→stage index map and the per-stage date
 * rule below are a one-for-one transcription, and
 * `PartnerTimelineEndpointTest::test_the_stage_sequence_matches_the_documented_fifteen_stage_flow`
 * pins the sequence against §3 of B2B_IMPLEMENTATION_HANDOFF.md so a change to
 * one surface without the other is a failing test rather than a silent
 * divergence.
 *
 * What is deliberately *not* transcribed: the German subtitle lines. They
 * carry collection addresses, pickup notes and repair-window prose assembled
 * for a human reader, and none of it is stage state a partner branches on. A
 * stage here is a machine code, a label, a sequence number and a timestamp.
 * `internal_note` is not read by this class, exactly as it is not read by the
 * portal's timeline.
 */
class PartnerTimelineBuilder
{
    /**
     * Stage codes in order. Mirrors `B2B_ORDER_STAGE_SEQUENCE`.
     *
     * These are the API's public vocabulary: **changing one is a breaking
     * change** for every partner that branches on it.
     *
     * @var list<string>
     */
    public const STAGES = [
        'order_received',
        'collection_requested',
        'collection_scheduled',
        'vehicle_collected',
        'initial_appraisal',
        'quotations_preparing',
        'approval_required',
        'repair_approved',
        'workshop_commissioned',
        'vehicle_in_repair',
        'repair_completed',
        'final_appraisal',
        'vehicle_returned',
        'billing_completed',
        'order_completed',
    ];

    /** Mirrors `B2B_STAGE_SHORT_LABEL`. @var array<string, string> */
    private const LABELS = [
        'order_received' => 'Auftrag eingegangen',
        'collection_requested' => 'Abholtermin angefragt',
        'collection_scheduled' => 'Abholung terminiert',
        'vehicle_collected' => 'Fahrzeug abgeholt',
        'initial_appraisal' => 'Erstgutachten verfügbar',
        'quotations_preparing' => 'Werkstattangebote in Vorbereitung',
        'approval_required' => 'Freigabe erforderlich',
        'repair_approved' => 'Reparatur freigegeben',
        'workshop_commissioned' => 'Werkstatt beauftragt',
        'vehicle_in_repair' => 'Fahrzeug in Reparatur',
        'repair_completed' => 'Reparatur abgeschlossen',
        'final_appraisal' => 'Nachgutachten abgeschlossen',
        'vehicle_returned' => 'Fahrzeug an Leasinggeber übergeben',
        'billing_completed' => 'Abrechnung abgeschlossen',
        'order_completed' => 'Auftrag abgeschlossen',
    ];

    /** Mirrors `B2B_STAGE_TOOLTIP`. @var array<string, string> */
    private const DESCRIPTIONS = [
        'order_received' => 'Ihre Rückgabeanfrage ist bei Leasyback eingegangen.',
        'collection_requested' => 'Sie haben einen Wunschtermin für die Abholung angefragt.',
        'collection_scheduled' => 'Leasyback hat den Abholtermin bestätigt.',
        'vehicle_collected' => 'Das Fahrzeug wurde bei Ihnen abgeholt.',
        'initial_appraisal' => 'Die Erstbegutachtung wurde durchgeführt und das Gutachten steht bereit.',
        'quotations_preparing' => 'Auf Basis des Gutachtens werden Werkstattangebote eingeholt.',
        'approval_required' => 'Ein oder mehrere Angebote liegen vor und warten auf Ihre Freigabe.',
        'repair_approved' => 'Sie haben ein Angebot freigegeben. Die Reparatur wird beauftragt.',
        'workshop_commissioned' => 'Die Werkstatt wurde mit der Reparatur beauftragt.',
        'vehicle_in_repair' => 'Das Fahrzeug befindet sich aktuell in der Reparatur.',
        'repair_completed' => 'Die Reparatur wurde abgeschlossen.',
        'final_appraisal' => 'Die Nachbegutachtung wurde abgeschlossen und das Nachgutachten steht bereit.',
        'vehicle_returned' => 'Das Fahrzeug wurde an den Leasinggeber übergeben.',
        'billing_completed' => 'Die Abrechnung zu diesem Auftrag wurde erstellt.',
        'order_completed' => 'Der Rückgabeprozess ist abgeschlossen.',
    ];

    /** Mirrors `B2B_STATUS_STAGE_INDEX`. @var array<string, int> */
    private const STATUS_STAGE_INDEX = [
        'order_requested' => 1,
        'order_placed' => 1,
        'confirmed' => 2,
        'vehicle_collected' => 3,
        'workshop_commissioned' => 8,
        'workshop' => 9,
        'repair_completed' => 10,
        'reinspection' => 11,
        'vehicle_returned' => 12,
        'invoice_processed' => 13,
        'completed' => 14,
    ];

    /** Mirrors `TERMINAL_STATUSES`. @var list<string> */
    private const TERMINAL_STATUSES = ['cancelled'];

    /**
     * The timeline for one order.
     *
     * Every input is loaded here rather than passed in, so a caller cannot
     * accidentally hand this an unscoped query result: the order it receives
     * has already been resolved through PartnerResourceLocator, and everything
     * else hangs off that order's own keys.
     *
     * @return array{
     *     stages: list<array<string, mixed>>,
     *     current_stage: string|null,
     *     history: list<array<string, mixed>>,
     *     is_cancelled: bool,
     *     status: array<string, mixed>
     * }
     */
    public function forOrder(LeasybackOrder $order): array
    {
        $status = trim((string) $order->order_status);
        $history = $this->history($order);
        $offer = $this->relevantOffer($order);
        $documentDates = $this->reportDocumentDates($order);

        $terminalEntry = in_array($status, self::TERMINAL_STATUSES, true)
            ? $history->firstWhere('new_status', $status)
            : null;

        $isCancelled = $terminalEntry !== null;

        $progressIndex = $isCancelled
            ? min(
                $this->progressIndex(trim((string) ($terminalEntry->old_status ?? '')), $offer) ?? 0,
                count(self::STAGES) - 1,
            )
            : $this->progressIndex($status, $offer);

        $stages = $progressIndex === null
            ? []
            : $this->stages(
                $order,
                $history,
                $offer,
                $documentDates,
                $progressIndex,
                $isCancelled,
                $terminalEntry?->created_at,
            );

        $currentStage = $progressIndex === null || $isCancelled
            ? null
            : self::STAGES[$progressIndex];

        return [
            'stages' => $stages,
            'current_stage' => $currentStage,
            'history' => $this->publicHistory($history),
            'is_cancelled' => $isCancelled,
            'status' => [
                'code' => $status,
                'label' => OrderStatusLabel::for($status),
                'is_open' => ! in_array($status, OrderStatus::closedValues(), true),
                'is_cancelled' => $isCancelled,
                'changed_at' => $history->first()?->created_at?->toIso8601String()
                    ?? $order->created_at?->toIso8601String(),
                'stage' => $isCancelled ? null : $currentStage,
                'stage_sequence' => $progressIndex === null || $isCancelled ? null : $progressIndex + 1,
                'stage_count' => count(self::STAGES),
            ],
        ];
    }

    /**
     * Newest first, matching the order the portal's payload is built in —
     * `findHistoryDate()` takes the *first* match, i.e. the most recent
     * transition into a status, and a re-entered status must resolve the same
     * way here.
     *
     * @return Collection<int, OrderStatusUpdate>
     */
    private function history(LeasybackOrder $order): Collection
    {
        return OrderStatusUpdate::where('auftragsnummer', $order->auftragsnummer)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * The status trail, stripped to what a partner may see.
     *
     * `updated_by`, `updated_by_user_id`, `caller_ip` and `auth_source` are
     * left behind: they name the Leasyback employee (or the machine account)
     * behind a transition and where the call came from. That is audit
     * metadata, not order state.
     *
     * @param  Collection<int, OrderStatusUpdate>  $history
     * @return list<array<string, mixed>>
     */
    private function publicHistory(Collection $history): array
    {
        return $history
            ->map(fn (OrderStatusUpdate $entry) => [
                'status' => $entry->new_status,
                'status_label' => OrderStatusLabel::for($entry->new_status),
                'previous_status' => $entry->old_status,
                'occurred_at' => $entry->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Mirrors `resolveB2bProgressIndex`.
     *
     * `inspected` is the one status that does not map to a fixed stage: where
     * the order sits depends on whether a customer offer has been published
     * and whether it has been accepted.
     */
    private function progressIndex(string $status, ?LeasybackOffer $offer): ?int
    {
        if ($status === 'inspected') {
            return match ($offer?->offer_status) {
                'selected' => 7,
                'published' => 6,
                // A rejected offer sends the order back to offer preparation:
                // Leasyback has to source a new quotation. relevantOffer()
                // already ignores rejected offers, so this is the fallback.
                default => 5,
            };
        }

        return self::STATUS_STAGE_INDEX[$status] ?? null;
    }

    /**
     * Mirrors `pickRelevantOffer`: the most recently accepted offer, else the
     * most recently published one, else none. Drafts, cancelled and rejected
     * offers never drive the timeline.
     */
    private function relevantOffer(LeasybackOrder $order): ?LeasybackOffer
    {
        $offers = LeasybackOffer::where('order_id', $order->id)
            ->whereIn('offer_status', ['published', 'selected'])
            ->get();

        $selected = $offers->where('offer_status', 'selected')->sortByDesc('selected_at');

        if ($selected->isNotEmpty()) {
            return $selected->first();
        }

        return $offers->where('offer_status', 'published')->sortByDesc('published_at')->first();
    }

    /**
     * Creation dates of the latest published `gutachten` / `nachgutachten`.
     *
     * `published` is the filter the portal applies before the timeline ever
     * sees a report document, so an Admin draft can date no stage here
     * either.
     *
     * @return array<string, Carbon|null>
     */
    private function reportDocumentDates(LeasybackOrder $order): array
    {
        $documents = VehicleReportDocument::where('auftragsnummer', $order->auftragsnummer)
            ->where('vehicle_id', $order->vehicle_id)
            ->where('published', true)
            ->orderByDesc('created_at')
            ->get();

        return [
            'gutachten' => $documents->firstWhere('document_type', 'gutachten')?->created_at,
            'nachgutachten' => $documents->firstWhere('document_type', 'nachgutachten')?->created_at,
        ];
    }

    /**
     * @param  Collection<int, OrderStatusUpdate>  $history
     * @param  array<string, Carbon|null>  $documentDates
     * @return list<array<string, mixed>>
     */
    private function stages(
        LeasybackOrder $order,
        Collection $history,
        ?LeasybackOffer $offer,
        array $documentDates,
        int $progressIndex,
        bool $isCancelled,
        ?Carbon $cancelledAt,
    ): array {
        $stages = [];

        foreach (self::STAGES as $index => $stage) {
            $completed = $index < $progressIndex;
            $isCurrent = ! $isCancelled && $index === $progressIndex;
            $cancelledHere = $isCancelled && $index === $progressIndex;

            $occurredAt = match (true) {
                $completed, $isCurrent => $this->stageDate($stage, $order, $history, $offer, $documentDates),
                $cancelledHere => $cancelledAt,
                default => null,
            };

            $stages[] = [
                'code' => $stage,
                'label' => self::LABELS[$stage],
                'description' => self::DESCRIPTIONS[$stage],
                'sequence' => $index + 1,
                'state' => match (true) {
                    $cancelledHere => 'cancelled',
                    $completed => 'completed',
                    $isCurrent => 'current',
                    default => 'upcoming',
                },
                'completed' => $completed,
                'is_current' => $isCurrent,
                'occurred_at' => $occurredAt?->toIso8601String(),
            ];
        }

        return $stages;
    }

    /**
     * Mirrors `b2bStageDate`.
     *
     * @param  Collection<int, OrderStatusUpdate>  $history
     * @param  array<string, Carbon|null>  $documentDates
     */
    private function stageDate(
        string $stage,
        LeasybackOrder $order,
        Collection $history,
        ?LeasybackOffer $offer,
        array $documentDates,
    ): ?Carbon {
        return match ($stage) {
            'order_received', 'collection_requested' => $order->created_at,
            'collection_scheduled' => $this->historyDate($history, 'confirmed'),
            'vehicle_collected' => $this->historyDate($history, 'vehicle_collected'),
            'initial_appraisal' => $documentDates['gutachten'] ?? $this->historyDate($history, 'inspected'),
            'quotations_preparing' => $this->historyDate($history, 'inspected'),
            'approval_required' => $offer?->published_at,
            'repair_approved' => $offer?->selected_at,
            'workshop_commissioned' => $this->historyDate($history, 'workshop_commissioned'),
            'vehicle_in_repair' => $this->historyDate($history, 'workshop'),
            'repair_completed' => $this->historyDate($history, 'repair_completed'),
            'final_appraisal' => $documentDates['nachgutachten'] ?? $this->historyDate($history, 'reinspection'),
            'vehicle_returned' => $this->historyDate($history, 'vehicle_returned'),
            'billing_completed' => $this->historyDate($history, 'invoice_processed'),
            'order_completed' => $this->historyDate($history, 'completed'),
            default => null,
        };
    }

    /**
     * @param  Collection<int, OrderStatusUpdate>  $history
     */
    private function historyDate(Collection $history, string $status): ?Carbon
    {
        return $history->firstWhere('new_status', $status)?->created_at;
    }
}
