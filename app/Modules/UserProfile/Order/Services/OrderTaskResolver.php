<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Enums\DocumentType;
use Illuminate\Support\Collection;

/**
 * Derives the Admin work queue for one order — in either channel — from data
 * that already exists: the order status, the repair positions, the workshop
 * quotations, the offers and their presentations, the commissioning record,
 * the appointment row, the published report documents and the billing record.
 * Nothing is persisted: there is no task table and no task state, so a task
 * can never go stale relative to the order it describes.
 *
 * The definitions form one ordered decision tree. Walking it yields exactly
 * one emphasised open action (`next`) plus the already-satisfied steps as
 * compact `history`; steps that are neither satisfied nor currently due are
 * simply absent, which is what keeps duplicates and stale entries impossible.
 * That ordering *is* the priority model — the tree is walked top to bottom and
 * the first unsatisfied step wins, so there is no separate urgency number that
 * could fall out of step with it.
 *
 * The two channels share this machinery and differ in exactly two places: the
 * rank map (their status graphs are genuinely different — see
 * TransitionOrderStatus) and the ordered list of steps. Everything else —
 * context building, the walk, the result shape, the action helpers — is one
 * implementation, because "what does Admin do next" is one question.
 *
 * The result is Admin-only and is never attached to a customer payload.
 */
class OrderTaskResolver
{
    public const SECTION_COLLECTION = 'abholung';

    public const SECTION_OFFERS = 'angebote';

    public const SECTION_DOCUMENTS = 'dokumente';

    public const SECTION_STATUS = 'status';

    public const SECTION_REPAIR = 'reparatur';

    public const SECTION_BILLING = 'abrechnung';

    public const SECTION_POSITIONS = 'positionen';

    public const SECTION_COMMISSION = 'beauftragung';

    /**
     * How the card should carry out a task's primary action.
     *
     * `request` fires an HTTP call directly (the pre-existing behaviour).
     * `modal` opens an existing modal preconfigured from `payload`.
     * `inline` focuses the form already on the page, named by `key` (a section).
     * A task with no action at all is informational — someone else's move.
     */
    public const ACTION_REQUEST = 'request';

    public const ACTION_MODAL = 'modal';

    public const ACTION_INLINE = 'inline';

    /**
     * UI handler keys for `modal` actions. Values are contract, not labels:
     * the Admin page's registry maps these to components.
     */
    public const UI_UPLOAD_REPORT = 'upload_report';

    public const UI_CREATE_OFFER = 'create_offer';

    /**
     * Who the step is waiting on. `state` says whether anything is open at all;
     * `actor` says whose move it is, which is what keeps a "waiting on the
     * customer" step from reading like an Admin to-do.
     */
    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_CUSTOMER = 'customer';

    public const ACTOR_WORKSHOP = 'workshop';

    /**
     * The B2B status graph as a linear rank, so "has the order already moved
     * past this phase" is a single comparison. Mirrors
     * TransitionOrderStatus::B2B_ALLOWED_TRANSITIONS.
     */
    private const B2B_STATUS_RANK = [
        'order_requested' => 0,
        'order_placed' => 1,
        'confirmed' => 2,
        'vehicle_collected' => 3,
        'inspected' => 4,
        'workshop_commissioned' => 5,
        'workshop' => 6,
        'repair_completed' => 7,
        'reinspection' => 8,
        'vehicle_returned' => 9,
        'invoice_processed' => 10,
        'completed' => 11,
    ];

    /**
     * The same idea for B2C, mirroring TransitionOrderStatus::ALLOWED_TRANSITIONS.
     *
     * Two things differ from a naive transcription of that graph. There is no
     * `vehicle_collected` — a B2C customer brings the car themselves, so
     * `confirmed → inspected` is direct. And `reworkshop` shares the rank of
     * `workshop` rather than sitting after `reinspection`: it is the repair
     * phase entered a second time, and ranking it as such is what makes the
     * failed-reinspection loop resolve correctly — the order moves *back* to
     * the repair rank so the repair and reinspection steps genuinely re-open,
     * instead of a monotonic rank pretending the case had moved on.
     */
    private const B2C_STATUS_RANK = [
        'order_requested' => 0,
        'order_placed' => 1,
        'confirmed' => 2,
        'inspected' => 3,
        'workshop_commissioned' => 4,
        'workshop' => 5,
        'reworkshop' => 5,
        'reinspection' => 6,
        'delivered' => 7,
        'completed' => 8,
    ];

    private const TERMINAL_STATUSES = ['cancelled', 'discarded'];

    /**
     * @param  array<string, mixed>  $order  One AdminQueryService::orderDetail() result.
     * @return array{next: array<string, mixed>|null, history: array<int, array<string, mixed>>, is_closed: bool, closed_status: string|null}
     */
    public function forOrderDetail(array $order): array
    {
        $context = $this->context($order);
        $history = [];
        $next = null;

        // An outstanding fee stops the process: the case has been called off
        // and only the money is left. Returned instead of the normal tree so
        // no repair task keeps asking for work nobody is going to do.
        if ($context['fee_outstanding']) {
            return [
                'next' => [
                    'key' => 'await_cancellation_fee',
                    'title' => 'Gebühr abwarten',
                    'description' => sprintf(
                        'Der Vorgang wurde beendet%s. Die Gebühr von %s € ist noch offen; der Auftrag wird nach Zahlungseingang automatisch abgeschlossen.',
                        $context['fee_reason_label'] === null ? '' : ' — '.$context['fee_reason_label'],
                        number_format(((int) $context['fee_amount']) / 100, 2, ',', '.'),
                    ),
                    'state' => 'waiting',
                    'actor' => self::ACTOR_CUSTOMER,
                    'date' => null,
                    'date_label' => null,
                    'section' => self::SECTION_STATUS,
                    'action' => null,
                ],
                'history' => [],
                'is_closed' => false,
                'closed_status' => null,
            ];
        }

        foreach ($this->definitions($context) as $definition) {
            if ($definition['done']) {
                $history[] = [
                    'key' => $definition['key'],
                    'title' => $definition['title'],
                    'date' => $definition['date'],
                    'section' => $definition['section'],
                    'state' => 'done',
                ];

                continue;
            }

            if ($next === null && ! $context['is_closed'] && $definition['open']) {
                $next = [
                    'key' => $definition['key'],
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'state' => $definition['state'],
                    'actor' => $definition['actor'],
                    'date' => $definition['date'],
                    'date_label' => $definition['date_label'],
                    'section' => $definition['section'],
                    'action' => $definition['action'],
                ];
            }
        }

        return [
            'next' => $next,
            'history' => $history,
            'is_closed' => $context['is_closed'],
            'closed_status' => $context['is_closed'] ? $context['status'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function context(array $order): array
    {
        $isB2b = ($order['vehicle_belongs'] ?? null) === 'B2B';
        $ranks = $isB2b ? self::B2B_STATUS_RANK : self::B2C_STATUS_RANK;

        $status = (string) ($order['order_status'] ?? '');
        $isCancelled = in_array($status, self::TERMINAL_STATUSES, true);
        $statusDates = $this->statusDates($order);
        $effectiveStatus = $isCancelled ? $this->lastActiveStatus($order, $ranks) : $status;

        $offers = $this->rows($order['offers'] ?? [])
            ->reject(fn (array $offer) => ($offer['offer_status'] ?? null) === 'cancelled');

        // A rejected offer is gone as far as "does this order have an offer"
        // goes: the customer said no, and something new has to be produced.
        // `has_offer` deliberately keeps counting it, because the B2B tree uses
        // that flag to re-open its offer step rather than to close it.
        $liveOffers = $offers->reject(fn (array $offer) => ($offer['offer_status'] ?? null) === 'rejected');

        $quotations = $this->rows($order['workshop_quotations'] ?? []);
        $publishedOffer = $offers->first(fn (array $offer) => ($offer['offer_status'] ?? null) === 'published');
        $commission = (array) ($order['workshop_commission'] ?? []);

        return [
            'order_id' => (string) ($order['id'] ?? ''),
            'is_b2b' => $isB2b,
            'status' => $status,
            'is_closed' => $isCancelled || $status === 'completed',
            'rank' => $ranks[$effectiveStatus] ?? 0,
            'status_dates' => $statusDates,
            'created_at' => $order['created_at'] ?? null,
            'requested_date' => $order['collection']['requested_collection_date'] ?? null,
            'confirmed_date' => $order['collection']['confirmed_collection_date'] ?? null,
            'repair_start_date' => $order['collection']['confirmed_repair_start_date'] ?? null,
            'processing_days' => $order['collection']['estimated_processing_days'] ?? null,
            'billing_processed' => (bool) ($order['billing']['is_processed'] ?? false),
            // Absent for an order that never reached `delivered`, which is the
            // same as "nothing is outstanding" for task purposes.
            'repair_payment_blocks' => (bool) ($order['repair_payment']['blocks_pickup'] ?? false),
            'repair_payment_status' => $order['repair_payment']['status'] ?? null,
            'fee_outstanding' => ($order['cancellation_fee'] ?? null) !== null
                && ! in_array($order['cancellation_fee']['status'], ['paid', 'cancelled'], true),
            'fee_amount' => $order['cancellation_fee']['amount_cents'] ?? 0,
            'fee_reason_label' => $order['cancellation_fee']['trigger_label'] ?? null,
            'billing_processed_at' => $order['billing']['processed_at'] ?? null,
            'position_count' => count((array) ($order['appraisal_positions'] ?? [])),
            // "Still able to produce an answer": an invitation that expired or
            // was revoked is not a workshop anyone is waiting on.
            'pending_quotation_count' => $quotations
                ->filter(fn (array $quotation) => ($quotation['status'] ?? null) === 'invited')
                ->count(),
            'has_offer' => $offers->isNotEmpty(),
            'has_live_offer' => $liveOffers->isNotEmpty(),
            'has_submitted_quotation' => $quotations
                ->contains(fn (array $quotation) => ($quotation['status'] ?? null) === 'submitted'),
            'published_offer' => $publishedOffer,
            'published_offer_expired' => (bool) ($publishedOffer['presentation']['is_expired'] ?? false),
            'selected_offer' => $offers->first(fn (array $offer) => in_array($offer['offer_status'] ?? null, ['selected', 'closed'], true)),
            'draft_offer' => $liveOffers->first(fn (array $offer) => ($offer['offer_status'] ?? null) === 'draft'),
            'is_commissioned' => (bool) ($commission['is_commissioned'] ?? false),
            'can_commission' => (bool) ($commission['can_commission'] ?? false),
            'commission_blocked_reason' => $commission['blocked_reason'] ?? null,
            'commissioned_at' => $commission['commissioned_at'] ?? null,
            'gutachten' => $this->publishedDocument($order, DocumentType::Gutachten->value),
            'nachgutachten' => $this->publishedDocument($order, DocumentType::Nachgutachten->value),
            'rechnung' => $this->publishedDocument($order, DocumentType::Rechnung->value),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function definitions(array $context): array
    {
        return $context['is_b2b'] ? $this->b2bDefinitions($context) : $this->b2cDefinitions($context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function b2bDefinitions(array $context): array
    {
        $rank = $context['rank'];
        $dates = $context['status_dates'];
        $orderId = $context['order_id'];

        return [
            $this->definition(
                key: 'confirm_collection',
                title: 'Abholtermin bestätigen',
                description: 'Es ist noch kein Abholtermin bestätigt. Übernehmen Sie den Wunschtermin des Kunden oder tragen Sie einen abweichenden Termin ein.',
                section: self::SECTION_COLLECTION,
                done: $context['confirmed_date'] !== null || $rank >= 3,
                open: $rank <= 2 && $context['confirmed_date'] === null,
                date: $context['confirmed_date'] ?? $context['requested_date'],
                dateLabel: $context['confirmed_date'] !== null ? 'Bestätigter Abholtermin' : 'Wunschtermin des Kunden',
                // No action. The collection endpoint requires a date this
                // resolver cannot invent, so the button it used to carry fired
                // an empty PATCH and could only ever produce a validation
                // error. "Zum Abschnitt" is what its "Abholung öffnen" label
                // actually promised.
                action: $this->inlineAction(self::SECTION_COLLECTION, 'Abholtermin eintragen'),
            ),
            $this->definition(
                key: 'release_order',
                title: 'Auftrag freigeben',
                description: 'Der Auftrag ist angefragt und wartet auf die Freigabe durch Leasyback.',
                section: self::SECTION_STATUS,
                done: $rank >= 1,
                open: $rank === 0,
                date: $dates['order_placed'] ?? $context['created_at'],
                dateLabel: 'Auftrag eingegangen',
                action: $this->action('post', 'admin.orders.approve', $orderId, label: 'Freigeben'),
            ),
            $this->definition(
                key: 'confirm_order',
                title: 'Auftrag bestätigen',
                description: 'Der Auftrag ist freigegeben. Bestätigen Sie ihn, damit die Abholung eingeplant werden kann.',
                section: self::SECTION_STATUS,
                done: $rank >= 2,
                open: $rank === 1,
                date: $dates['confirmed'] ?? null,
                dateLabel: 'Bestätigt am',
                action: $this->statusAction($orderId, 'confirmed', 'Auftrag bestätigen'),
            ),
            $this->definition(
                key: 'mark_vehicle_collected',
                title: 'Fahrzeugabholung erfassen',
                description: 'Der Abholtermin ist bestätigt. Erfassen Sie die erfolgte Abholung, sobald das Fahrzeug übernommen wurde.',
                section: self::SECTION_STATUS,
                done: $rank >= 3,
                open: $rank === 2 && $context['confirmed_date'] !== null,
                date: $dates['vehicle_collected'] ?? $context['confirmed_date'],
                dateLabel: $rank >= 3 ? 'Abgeholt am' : 'Bestätigter Abholtermin',
                action: $this->statusAction($orderId, 'vehicle_collected', 'Als abgeholt markieren'),
            ),
            $this->definition(
                key: 'upload_initial_appraisal',
                title: 'Erstgutachten hochladen',
                description: 'Das Fahrzeug ist abgeholt. Laden Sie das Erstgutachten hoch und veröffentlichen Sie es für den Kunden.',
                section: self::SECTION_DOCUMENTS,
                done: $context['gutachten'] !== null || $rank >= 4,
                open: $rank === 3 && $context['gutachten'] === null,
                date: $context['gutachten']['created_at'] ?? $dates['vehicle_collected'] ?? null,
                dateLabel: 'Abgeholt am',
                action: $this->modalAction(self::UI_UPLOAD_REPORT, 'Erstgutachten hochladen', ['document_type' => DocumentType::Gutachten->value, 'title' => 'Erstgutachten hochladen']),
            ),
            $this->definition(
                key: 'complete_initial_appraisal',
                title: 'Erstbegutachtung abschließen',
                description: 'Das Erstgutachten liegt vor. Schließen Sie die Begutachtung ab, um die Angebotsphase zu starten.',
                section: self::SECTION_STATUS,
                done: $rank >= 4,
                open: $rank === 3 && $context['gutachten'] !== null,
                date: $dates['inspected'] ?? $context['gutachten']['created_at'] ?? null,
                dateLabel: 'Gutachten vom',
                action: $this->statusAction($orderId, 'inspected', 'Begutachtung abschließen'),
            ),
            $this->definition(
                key: 'request_workshop_quotations',
                title: 'Werkstattangebote anfordern',
                description: 'Das Erstgutachten ist abgeschlossen. Erstellen Sie Werkstattlinks und warten Sie auf mindestens ein eingegangenes Angebot.',
                section: self::SECTION_OFFERS,
                done: $context['has_submitted_quotation'] || $rank >= 5,
                open: $rank === 4 && ! $context['has_submitted_quotation'],
                date: $context['gutachten']['created_at'] ?? $dates['inspected'] ?? null,
                dateLabel: 'Begutachtung abgeschlossen',
                action: $this->inlineAction(self::SECTION_OFFERS, 'Werkstatt einladen'),
            ),
            $this->definition(
                key: 'prepare_customer_offer',
                title: 'Kundenangebot veröffentlichen',
                description: 'Es liegt ein Angebotsentwurf vor, aber noch kein veröffentlichtes Angebot. Prüfen und veröffentlichen Sie das Angebot.',
                section: self::SECTION_OFFERS,
                done: $context['published_offer'] !== null || $context['selected_offer'] !== null || $rank >= 5,
                open: $rank === 4 && $context['has_offer'] && $context['published_offer'] === null && $context['selected_offer'] === null,
                date: $context['draft_offer']['created_at'] ?? null,
                dateLabel: 'Entwurf vom',
                action: $this->modalAction(self::UI_CREATE_OFFER, 'Angebot erstellen'),
            ),
            $this->definition(
                key: 'await_customer_approval',
                title: 'Freigabe des Kunden abwarten',
                description: 'Das Angebot ist veröffentlicht. Der Kunde hat es noch nicht freigegeben — derzeit ist keine Aktion durch Leasyback erforderlich.',
                section: self::SECTION_OFFERS,
                done: $context['selected_offer'] !== null || $rank >= 5,
                open: $rank === 4 && $context['published_offer'] !== null,
                date: $context['selected_offer']['selected_at'] ?? $context['published_offer']['published_at'] ?? null,
                dateLabel: 'Veröffentlicht am',
                state: 'waiting',
                actor: self::ACTOR_CUSTOMER,
                action: null,
            ),
            $this->definition(
                key: 'commission_workshop',
                title: 'Werkstatt beauftragen',
                description: 'Der Kunde hat das Angebot freigegeben. Beauftragen Sie die Werkstatt mit der Reparatur.',
                section: self::SECTION_STATUS,
                done: $rank >= 5,
                open: $rank === 4 && $context['selected_offer'] !== null,
                date: $dates['workshop_commissioned'] ?? $context['selected_offer']['selected_at'] ?? null,
                dateLabel: $rank >= 5 ? 'Beauftragt am' : 'Freigegeben am',
                action: $this->statusAction($orderId, 'workshop_commissioned', 'Werkstatt beauftragen'),
            ),
            $this->definition(
                key: 'enter_repair_appointment',
                title: 'Reparaturtermin erfassen',
                description: 'Die Werkstatt ist beauftragt. Tragen Sie den bestätigten Reparaturbeginn und die voraussichtliche Dauer ein — damit startet die Reparaturphase.',
                section: self::SECTION_REPAIR,
                // Completed by the saved appointment itself (§11), not only by
                // the status move, so the task closes on the data that answers
                // it. Saving the appointment performs the transition anyway;
                // the rank check keeps history readable for orders that moved
                // on before this field existed.
                done: $context['repair_start_date'] !== null || $rank >= 6,
                open: $rank === 5 && $context['repair_start_date'] === null,
                date: $context['repair_start_date'] ?? $dates['workshop_commissioned'] ?? null,
                dateLabel: $context['repair_start_date'] !== null ? 'Bestätigter Reparaturbeginn' : 'Beauftragt am',
                // No action, for the same reason as confirm_collection: the
                // appointment endpoint requires a date.
                action: $this->inlineAction(self::SECTION_REPAIR, 'Termin eintragen'),
            ),
            $this->definition(
                key: 'monitor_repair',
                title: 'Reparatur überwachen',
                description: 'Das Fahrzeug ist in Reparatur. Erfassen Sie den Abschluss, sobald die Werkstatt fertig gemeldet hat.',
                section: self::SECTION_STATUS,
                done: $rank >= 7,
                open: $rank === 6,
                date: $context['repair_start_date'] ?? $dates['workshop'] ?? null,
                dateLabel: 'In Reparatur seit',
                state: 'waiting',
                actor: self::ACTOR_WORKSHOP,
                action: $this->statusAction($orderId, 'repair_completed', 'Reparatur abgeschlossen'),
            ),
            $this->definition(
                key: 'upload_final_appraisal',
                title: 'Nachgutachten hochladen',
                description: 'Die Reparatur ist abgeschlossen. Laden Sie das Nachgutachten hoch und veröffentlichen Sie es für den Kunden.',
                section: self::SECTION_DOCUMENTS,
                done: $context['nachgutachten'] !== null || $rank >= 8,
                open: $rank === 7 && $context['nachgutachten'] === null,
                date: $context['nachgutachten']['created_at'] ?? $dates['repair_completed'] ?? null,
                dateLabel: 'Reparatur abgeschlossen am',
                action: $this->modalAction(self::UI_UPLOAD_REPORT, 'Nachgutachten hochladen', ['document_type' => DocumentType::Nachgutachten->value, 'title' => 'Nachgutachten hochladen']),
            ),
            $this->definition(
                key: 'complete_final_appraisal',
                title: 'Nachbegutachtung abschließen',
                description: 'Das Nachgutachten liegt vor. Schließen Sie die Nachbegutachtung ab.',
                section: self::SECTION_STATUS,
                done: $rank >= 8,
                open: $rank === 7 && $context['nachgutachten'] !== null,
                date: $dates['reinspection'] ?? $context['nachgutachten']['created_at'] ?? null,
                dateLabel: 'Nachgutachten vom',
                action: $this->statusAction($orderId, 'reinspection', 'Nachbegutachtung abschließen'),
            ),
            $this->definition(
                key: 'confirm_vehicle_returned',
                title: 'Rückgabe an Leasinggeber bestätigen',
                description: 'Die Nachbegutachtung ist abgeschlossen. Bestätigen Sie die Übergabe des Fahrzeugs an den Leasinggeber.',
                section: self::SECTION_STATUS,
                done: $rank >= 9,
                open: $rank === 8,
                date: $dates['vehicle_returned'] ?? $dates['reinspection'] ?? null,
                dateLabel: 'Nachbegutachtung abgeschlossen am',
                action: $this->statusAction($orderId, 'vehicle_returned', 'Rückgabe bestätigen'),
            ),
            // Driven by the billing record rather than by the presence of a
            // `rechnung` document: §21 gates completion on billing actually
            // being processed, and an uploaded PDF is not that fact. `open`
            // uses `rank >= 9` so an order that somehow reached
            // invoice_processed without billing still surfaces this task
            // instead of dead-ending at a completion it cannot perform.
            $this->definition(
                key: 'prepare_invoice',
                title: 'Abrechnung vorbereiten',
                description: 'Das Fahrzeug ist zurückgegeben. Erfassen Sie Rechnungsnummer und Rechnungsdokument und markieren Sie die Abrechnung als verarbeitet.',
                section: self::SECTION_BILLING,
                done: $context['billing_processed'] || $rank >= 11,
                open: $rank >= 9 && ! $context['billing_processed'],
                date: $context['rechnung']['created_at'] ?? $dates['vehicle_returned'] ?? null,
                dateLabel: 'Zurückgegeben am',
                // No action, for the same reason as confirm_collection: the
                // billing endpoint requires the invoice data.
                action: $this->inlineAction(self::SECTION_BILLING, 'Abrechnung erfassen'),
            ),
            $this->definition(
                key: 'mark_invoice_processed',
                title: 'Abrechnung im Auftragsstatus erfassen',
                description: 'Die Abrechnung ist verarbeitet. Setzen Sie den Auftrag auf „Rechnung verarbeitet".',
                section: self::SECTION_STATUS,
                done: $rank >= 10,
                open: $rank === 9 && $context['billing_processed'],
                date: $dates['invoice_processed'] ?? $context['billing_processed_at'] ?? null,
                dateLabel: 'Abrechnung verarbeitet am',
                action: $this->statusAction($orderId, 'invoice_processed', 'Abrechnung abschließen'),
            ),
            $this->definition(
                key: 'complete_order',
                title: 'Auftrag abschließen',
                description: 'Die Abrechnung ist verarbeitet. Schließen Sie den Auftrag ab.',
                section: self::SECTION_STATUS,
                done: $rank >= 11,
                open: $rank === 10 && $context['billing_processed'],
                date: $dates['completed'] ?? $dates['invoice_processed'] ?? null,
                dateLabel: 'Abrechnung abgeschlossen am',
                action: $this->statusAction($orderId, 'completed', 'Auftrag abschließen'),
            ),
        ];
    }

    /**
     * The B2C repair journey. Rank 3 — `inspected` — carries the whole
     * commercial flow, so most of this tree is decided by domain data rather
     * than by status: the positions, the quotations, the offers and the
     * commissioning record all sit inside that one status, and they are what
     * actually distinguishes "nothing captured yet" from "waiting on a customer
     * decision". Status alone would collapse eight distinct situations into one.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function b2cDefinitions(array $context): array
    {
        $rank = $context['rank'];
        $dates = $context['status_dates'];
        $orderId = $context['order_id'];

        return [
            $this->definition(
                key: 'release_order',
                title: 'Auftrag freigeben',
                description: 'Der Auftrag ist angefragt und wartet auf die Freigabe durch Leasyback.',
                section: self::SECTION_STATUS,
                done: $rank >= 1,
                open: $rank === 0,
                date: $dates['order_placed'] ?? $context['created_at'],
                dateLabel: 'Auftrag eingegangen',
                action: $this->action('post', 'admin.orders.approve', $orderId, label: 'Freigeben'),
            ),
            $this->definition(
                key: 'confirm_inspection_appointment',
                title: 'Begutachtungstermin bestätigen',
                description: 'Der Auftrag ist freigegeben. Bestätigen Sie den Termin zur Erstbegutachtung, damit der Kunde sein Fahrzeug vorstellen kann.',
                section: self::SECTION_STATUS,
                done: $rank >= 2,
                open: $rank === 1,
                date: $dates['confirmed'] ?? $context['created_at'],
                dateLabel: 'Bestätigt am',
                action: $this->statusAction($orderId, 'confirmed', 'Termin bestätigen'),
            ),
            $this->definition(
                key: 'upload_initial_appraisal',
                title: 'Erstgutachten hochladen',
                description: 'Der Termin ist bestätigt. Laden Sie das Erstgutachten hoch und veröffentlichen Sie es für den Kunden.',
                section: self::SECTION_DOCUMENTS,
                done: $context['gutachten'] !== null || $rank >= 3,
                open: $rank === 2 && $context['gutachten'] === null,
                date: $context['gutachten']['created_at'] ?? $dates['confirmed'] ?? null,
                dateLabel: 'Termin bestätigt am',
                action: $this->modalAction(self::UI_UPLOAD_REPORT, 'Erstgutachten hochladen', ['document_type' => DocumentType::Gutachten->value, 'title' => 'Erstgutachten hochladen']),
            ),
            $this->definition(
                key: 'complete_initial_appraisal',
                title: 'Erstbegutachtung abschließen',
                description: 'Das Erstgutachten liegt vor. Schließen Sie die Begutachtung ab, um die Reparaturplanung zu starten.',
                section: self::SECTION_STATUS,
                done: $rank >= 3,
                open: $rank === 2 && $context['gutachten'] !== null,
                date: $dates['inspected'] ?? $context['gutachten']['created_at'] ?? null,
                dateLabel: 'Gutachten vom',
                action: $this->statusAction($orderId, 'inspected', 'Begutachtung abschließen'),
            ),
            $this->definition(
                key: 'capture_repair_positions',
                title: 'Reparaturpositionen erfassen',
                description: 'Die Begutachtung ist abgeschlossen, aber es sind noch keine Reparaturpositionen erfasst. Tragen Sie Schäden, Reparaturwege und Beträge ein — sie sind die Grundlage jeder Werkstattanfrage.',
                section: self::SECTION_POSITIONS,
                done: $context['position_count'] > 0 || $context['has_live_offer'] || $rank >= 4,
                open: $rank === 3,
                date: $dates['inspected'] ?? $context['gutachten']['created_at'] ?? null,
                dateLabel: 'Begutachtung abgeschlossen',
                action: $this->inlineAction(self::SECTION_POSITIONS, 'Positionen erfassen'),
            ),
            $this->definition(
                key: 'request_workshop_quotations',
                title: 'Werkstattangebote anfragen',
                description: 'Die Reparaturpositionen stehen. Laden Sie eine oder mehrere Werkstätten ein, ein Angebot dazu abzugeben.',
                section: self::SECTION_OFFERS,
                // An invitation that expired or was revoked without an answer
                // leaves nothing to wait for, so this step re-opens rather than
                // letting a dead link count as "asked" — unless an offer has
                // already been produced, in which case sourcing is settled and
                // asking again is noise.
                done: $context['pending_quotation_count'] > 0 || $context['has_submitted_quotation'] || $context['has_live_offer'] || $rank >= 4,
                open: $rank === 3 && $context['position_count'] > 0,
                date: $dates['inspected'] ?? null,
                dateLabel: 'Begutachtung abgeschlossen',
                action: $this->inlineAction(self::SECTION_OFFERS, 'Werkstatt einladen'),
            ),
            $this->definition(
                key: 'await_workshop_quotations',
                title: 'Werkstattangebote abwarten',
                description: 'Mindestens eine Werkstatt wurde angefragt, aber es liegt noch kein Angebot vor. Derzeit ist keine Aktion durch Leasyback erforderlich.',
                section: self::SECTION_OFFERS,
                done: $context['has_submitted_quotation'] || $context['has_live_offer'] || $rank >= 4,
                open: $rank === 3 && $context['pending_quotation_count'] > 0,
                date: null,
                dateLabel: 'Angefragt',
                state: 'waiting',
                actor: self::ACTOR_WORKSHOP,
                action: null,
            ),
            $this->definition(
                key: 'create_customer_offer',
                title: 'Kundenangebot erstellen',
                description: 'Es liegt mindestens ein Werkstattangebot vor. Übernehmen Sie das gewählte Werkstattangebot als Kundenangebot.',
                section: self::SECTION_OFFERS,
                done: $context['has_live_offer'] || $rank >= 4,
                open: $rank === 3 && $context['has_submitted_quotation'],
                date: null,
                dateLabel: 'Werkstattangebot eingegangen',
                action: $this->modalAction(self::UI_CREATE_OFFER, 'Angebot erstellen'),
            ),
            $this->definition(
                key: 'publish_customer_offer',
                title: 'Kundenangebot veröffentlichen',
                description: 'Das Kundenangebot liegt als Entwurf vor und ist für den Kunden noch nicht sichtbar. Prüfen und veröffentlichen Sie es.',
                section: self::SECTION_OFFERS,
                done: $context['published_offer'] !== null || $context['selected_offer'] !== null || $rank >= 4,
                open: $rank === 3 && $context['draft_offer'] !== null,
                date: $context['draft_offer']['created_at'] ?? null,
                dateLabel: 'Entwurf vom',
                action: $this->offerAction('patch', 'admin.orders.offers.publish', $context['draft_offer']['offer_id'] ?? null, 'Angebot veröffentlichen'),
            ),
            // Ahead of the waiting step, so an offer nobody can accept any more
            // stops presenting as "waiting for the customer" indefinitely.
            $this->definition(
                key: 'renew_expired_offer',
                title: 'Abgelaufenes Angebot erneuern',
                description: 'Die Gültigkeit des veröffentlichten Angebots ist abgelaufen — der Kunde kann es nicht mehr annehmen. Stornieren Sie es und erstellen Sie ein neues Angebot aus einem Werkstattangebot.',
                section: self::SECTION_OFFERS,
                done: $context['selected_offer'] !== null || $rank >= 4,
                open: $rank === 3 && $context['published_offer'] !== null && $context['published_offer_expired'],
                date: $context['published_offer']['presentation']['valid_until'] ?? null,
                dateLabel: 'Gültig bis',
                action: $this->offerAction('patch', 'admin.orders.offers.cancel', $context['published_offer']['offer_id'] ?? null, 'Angebot stornieren'),
            ),
            $this->definition(
                key: 'await_customer_decision',
                title: 'Entscheidung des Kunden abwarten',
                description: 'Das Angebot ist veröffentlicht und noch gültig. Der Kunde hat es weder angenommen noch abgelehnt — derzeit ist keine Aktion durch Leasyback erforderlich.',
                section: self::SECTION_OFFERS,
                done: $context['selected_offer'] !== null || $rank >= 4,
                open: $rank === 3 && $context['published_offer'] !== null,
                date: $context['published_offer']['presentation']['presented_at'] ?? $context['published_offer']['published_at'] ?? null,
                dateLabel: 'Veröffentlicht am',
                state: 'waiting',
                actor: self::ACTOR_CUSTOMER,
                action: null,
            ),
            $this->definition(
                key: 'commission_workshop',
                title: $context['can_commission'] ? 'Gewählte Werkstatt beauftragen' : 'Reparatur ohne Werkstattbeauftragung starten',
                description: $this->commissionDescription($context),
                section: self::SECTION_COMMISSION,
                // The commissioning record, not the status. "Has this workshop
                // been instructed" is a question about something that happened
                // once, and WorkshopCommissionService answers it from the
                // record of it happening rather than from where the order
                // stands now.
                done: $context['is_commissioned'] || $rank >= 4,
                open: $rank === 3 && $context['selected_offer'] !== null,
                date: $context['commissioned_at'] ?? $context['selected_offer']['selected_at'] ?? null,
                dateLabel: $context['is_commissioned'] ? 'Beauftragt am' : 'Freigegeben am',
                action: $this->commissionAction($context),
            ),
            $this->definition(
                key: 'set_repair_appointment',
                title: 'Reparaturtermin festlegen',
                description: 'Die Werkstatt ist beauftragt. Tragen Sie den bestätigten Reparaturbeginn und die voraussichtliche Dauer ein — damit startet die Reparaturphase.',
                section: self::SECTION_REPAIR,
                done: $context['repair_start_date'] !== null || $rank >= 5,
                open: $rank === 4,
                date: $context['repair_start_date'] ?? $dates['workshop_commissioned'] ?? null,
                dateLabel: $context['repair_start_date'] !== null ? 'Bestätigter Reparaturbeginn' : 'Beauftragt am',
                action: $this->inlineAction(self::SECTION_REPAIR, 'Termin eintragen'),
            ),
            $this->definition(
                key: 'await_repair',
                title: $context['status'] === 'reworkshop' ? 'Nachbesserung abwarten' : 'Reparatur abwarten',
                description: $context['status'] === 'reworkshop'
                    ? 'Das Fahrzeug ist zur Nachbesserung in der Werkstatt. Erfassen Sie die erneute Nachprüfung, sobald die Werkstatt fertig gemeldet hat.'
                    : 'Das Fahrzeug ist in Reparatur. Erfassen Sie die Nachprüfung, sobald die Werkstatt fertig gemeldet hat.',
                section: self::SECTION_STATUS,
                done: $rank >= 6,
                // Also open at rank 4: an order that is commissioned and
                // already carries its appointment has nothing left to enter,
                // and would otherwise fall through this tree with no task at
                // all.
                open: $rank >= 4 && $rank <= 5,
                date: $context['repair_start_date'] ?? $dates['workshop'] ?? null,
                dateLabel: 'In Reparatur seit',
                state: 'waiting',
                actor: self::ACTOR_WORKSHOP,
                action: $this->statusAction($orderId, 'reinspection', 'Nachprüfung erfassen'),
            ),
            $this->definition(
                key: 'upload_final_appraisal',
                title: 'Nachgutachten hochladen',
                description: 'Die Nachprüfung ist erfasst. Laden Sie das Nachgutachten hoch und veröffentlichen Sie es für den Kunden — es hält das Ergebnis fest.',
                section: self::SECTION_DOCUMENTS,
                done: $context['nachgutachten'] !== null || $rank >= 7,
                open: $rank === 6 && $context['nachgutachten'] === null,
                date: $context['nachgutachten']['created_at'] ?? $dates['reinspection'] ?? null,
                dateLabel: 'Nachprüfung erfasst am',
                action: $this->modalAction(self::UI_UPLOAD_REPORT, 'Nachgutachten hochladen', ['document_type' => DocumentType::Nachgutachten->value, 'title' => 'Nachgutachten hochladen']),
            ),
            $this->definition(
                key: 'evaluate_reinspection',
                title: 'Nachprüfung auswerten',
                description: 'Das Nachgutachten liegt vor. Entscheiden Sie: bestanden — dann ist das Fahrzeug abholbereit; nicht bestanden — dann geht es zur Nachbesserung zurück in die Werkstatt.',
                section: self::SECTION_STATUS,
                done: $rank >= 7,
                open: $rank === 6 && $context['nachgutachten'] !== null,
                date: $dates['reinspection'] ?? $context['nachgutachten']['created_at'] ?? null,
                dateLabel: 'Nachgutachten vom',
                // The pass branch only. Failing is a different judgement with a
                // different consequence, and belongs in the status card where
                // both outcomes are offered side by side.
                action: $this->statusAction($orderId, 'delivered', 'Bestanden — abholbereit melden'),
            ),
            /*
             * No action: there is nothing for Admin to click yet, and offering
             * `confirm_pickup` here would break this tree's own contract that
             * every offered action actually executes — the completion gate
             * refuses an unpaid repair.
             */
            $this->definition(
                key: 'await_repair_payment',
                title: 'Zahlungseingang abwarten',
                description: 'Die Reparaturkosten sind noch nicht bezahlt. Das Fahrzeug kann erst nach Zahlungseingang übergeben werden.',
                section: self::SECTION_STATUS,
                done: $rank >= 8 || ! $context['repair_payment_blocks'],
                open: $rank === 7 && $context['repair_payment_blocks'],
                date: $dates['delivered'] ?? null,
                dateLabel: 'Abholbereit seit',
                action: null,
                actor: self::ACTOR_CUSTOMER,
            ),
            /*
             * B2C's counterpart to the B2B billing step, and deliberately not
             * a copy of it: there is no `order_billings` row here, no invoice
             * number and no completion gate. The customer has already paid
             * through Stripe, so nothing about the money is outstanding — what
             * is outstanding is the document they are owed for it, and the
             * only thing standing between them and it was that nothing asked
             * an admin to upload one.
             *
             * Ahead of `confirm_pickup` because the tree surfaces exactly one
             * open step and goes silent once the order is closed: a step that
             * only came due at `completed` could never be shown at all. It
             * still gates nothing — the status card offers „Abholung
             * bestätigen" throughout, and TransitionOrderStatus is untouched.
             *
             * Gated on an actual settled charge rather than on `delivered`
             * alone: a repair that cost the customer nothing has no invoice to
             * hand over, and an order that predates payments has none to find.
             */
            $this->definition(
                key: 'provide_invoice',
                title: 'Rechnung bereitstellen',
                description: 'Die Reparaturkosten sind bezahlt. Laden Sie die Rechnung hoch und veröffentlichen Sie sie — der Kunde findet sie danach in seinem Vorgang.',
                section: self::SECTION_DOCUMENTS,
                done: $context['rechnung'] !== null,
                open: $rank === 7 && $context['repair_payment_status'] === 'paid',
                date: $context['rechnung']['created_at'] ?? $dates['delivered'] ?? null,
                dateLabel: $context['rechnung'] !== null ? 'Rechnung vom' : 'Abholbereit seit',
                action: $this->modalAction(self::UI_UPLOAD_REPORT, 'Rechnung hochladen', ['document_type' => DocumentType::Rechnung->value, 'title' => 'Rechnung hochladen']),
            ),
            $this->definition(
                key: 'confirm_pickup',
                title: 'Fahrzeugabholung bestätigen',
                description: 'Das Fahrzeug ist abholbereit und der Kunde ist informiert. Bestätigen Sie die erfolgte Abholung — damit ist der Vorgang abgeschlossen.',
                section: self::SECTION_STATUS,
                done: $rank >= 8,
                open: $rank === 7 && ! $context['repair_payment_blocks'],
                date: $dates['delivered'] ?? null,
                dateLabel: 'Abholbereit seit',
                action: $this->statusAction($orderId, 'completed', 'Abholung bestätigen'),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function commissionDescription(array $context): string
    {
        if ($context['can_commission']) {
            return 'Der Kunde hat das Angebot angenommen. Beauftragen Sie die Werkstatt, die dieses Angebot abgegeben hat — sie erhält den Reparaturauftrag per E-Mail.';
        }

        return match ($context['commission_blocked_reason']) {
            WorkshopCommissionService::BLOCKED_MANUAL => 'Das angenommene Angebot wurde manuell erfasst, es steht also keine Werkstatt dahinter, die beauftragt werden könnte. Stimmen Sie die Reparatur außerhalb des Systems ab und starten Sie die Reparaturphase.',
            WorkshopCommissionService::BLOCKED_NO_CONTACT => 'Das angenommene Angebot stammt aus einer Werkstattanfrage, enthält aber keine Kontaktdaten. Beauftragen Sie die Werkstatt außerhalb des Systems und starten Sie die Reparaturphase.',
            default => 'Der Kunde hat das Angebot angenommen. Die Werkstatt kann derzeit nicht automatisch beauftragt werden — prüfen Sie den Abschnitt „Werkstattbeauftragung".',
        };
    }

    /**
     * Commissioning proper where there is a workshop to instruct; otherwise the
     * honest fallback — the repair still has to start, but nobody is being sent
     * a repair order. Never a button the server would refuse.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function commissionAction(array $context): ?array
    {
        if ($context['can_commission']) {
            return $this->action('post', 'admin.orders.commission-workshop', $context['order_id'], 'Werkstatt beauftragen');
        }

        return in_array($context['commission_blocked_reason'], [WorkshopCommissionService::BLOCKED_MANUAL, WorkshopCommissionService::BLOCKED_NO_CONTACT], true)
            ? $this->statusAction($context['order_id'], 'workshop', 'Reparaturphase starten')
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(
        string $key,
        string $title,
        string $description,
        string $section,
        bool $done,
        bool $open,
        ?string $date,
        string $dateLabel,
        ?array $action,
        string $state = 'open',
        string $actor = self::ACTOR_ADMIN,
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'section' => $section,
            'done' => $done,
            'open' => $open && ! $done,
            'date' => $date,
            'date_label' => $dateLabel,
            'state' => $state,
            'actor' => $actor,
            'action' => $action,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function action(string $method, string $routeName, string $orderId, string $label, array $payload = []): ?array
    {
        if ($orderId === '') {
            return null;
        }

        return [
            'type' => self::ACTION_REQUEST,
            'key' => $routeName,
            'method' => $method,
            'url' => route($routeName, $orderId),
            'payload' => $payload,
            'label' => $label,
        ];
    }

    /**
     * Open an existing modal, already configured for this task.
     *
     * `key` names a UI handler rather than a component, so the resolver stays
     * free of frontend structure and the mapping lives in one registry on the
     * Admin page instead of a branch per task in the card.
     *
     * @param  array<string, mixed>  $preset
     * @return array<string, mixed>
     */
    private function modalAction(string $key, string $label, array $preset = []): array
    {
        return [
            'type' => self::ACTION_MODAL,
            'key' => $key,
            'method' => null,
            'url' => null,
            'payload' => $preset,
            'label' => $label,
        ];
    }

    /**
     * Take the admin to the form that already exists on this page and put the
     * cursor in it. No new workflow — the same fields, reached in one click
     * instead of a scroll and a hunt.
     *
     * @return array<string, mixed>
     */
    private function inlineAction(string $section, string $label): array
    {
        return [
            'type' => self::ACTION_INLINE,
            'key' => $section,
            'method' => null,
            'url' => null,
            'payload' => [],
            'label' => $label,
        ];
    }

    /**
     * The offer routes are keyed by the offer rather than the order, so they
     * take the id of the offer the step is actually about.
     *
     * @return array<string, mixed>|null
     */
    private function offerAction(string $method, string $routeName, ?string $offerId, string $label): ?array
    {
        if ($offerId === null || $offerId === '') {
            return null;
        }

        return [
            'type' => self::ACTION_REQUEST,
            'key' => $routeName,
            'method' => $method,
            'url' => route($routeName, $offerId),
            'payload' => [],
            'label' => $label,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function statusAction(string $orderId, string $status, string $label): ?array
    {
        return $this->action('patch', 'admin.orders.status', $orderId, $label, ['status' => $status]);
    }

    /**
     * The moment the order entered each status, keyed by that status.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, string>
     */
    private function statusDates(array $order): array
    {
        return $this->rows($order['status_updates'] ?? [])
            ->sortBy('created_at')
            ->reduce(function (array $carry, array $update) {
                $status = (string) ($update['new_status'] ?? '');

                if ($status !== '' && isset($update['created_at'])) {
                    $carry[$status] = (string) $update['created_at'];
                }

                return $carry;
            }, []);
    }

    /**
     * A cancelled order keeps the phase it reached, so its history stays
     * readable instead of collapsing to nothing.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, int>  $ranks
     */
    private function lastActiveStatus(array $order, array $ranks): string
    {
        $status = $this->rows($order['status_updates'] ?? [])
            ->sortByDesc('created_at')
            ->map(fn (array $update) => (string) ($update['old_status'] ?? ''))
            ->first(fn (string $value) => isset($ranks[$value]));

        return $status ?? 'order_requested';
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>|null
     */
    private function publishedDocument(array $order, string $type): ?array
    {
        return $this->rows($order['report_documents'] ?? [])
            ->filter(fn (array $document) => ($document['published'] ?? false) && $this->documentType($document) === $type)
            ->sortBy('created_at')
            ->last();
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function documentType(array $document): ?string
    {
        $type = strtolower(trim((string) ($document['document_type'] ?? '')));

        if (in_array($type, [DocumentType::Gutachten->value, DocumentType::Nachgutachten->value, DocumentType::Rechnung->value], true)) {
            return $type;
        }

        $title = strtolower((string) ($document['document_title'] ?? ''));

        return match (true) {
            str_contains($title, DocumentType::Nachgutachten->value) => DocumentType::Nachgutachten->value,
            str_contains($title, DocumentType::Gutachten->value) => DocumentType::Gutachten->value,
            str_contains($title, DocumentType::Rechnung->value) => DocumentType::Rechnung->value,
            default => null,
        };
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(mixed $rows): Collection
    {
        return collect(is_iterable($rows) ? $rows : [])->map(fn (mixed $row) => (array) $row)->values();
    }
}
