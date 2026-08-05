<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Enums\DocumentType;
use Illuminate\Support\Collection;

/**
 * Derives the Admin work queue for one B2B order from data that already
 * exists — the order status, the collection appointment row, the published
 * report documents and the offer states. Nothing is persisted: there is no
 * task table and no task state, so a task can never go stale relative to the
 * order it describes.
 *
 * The definitions form one ordered decision tree. Walking it yields exactly
 * one emphasised open action (`next`) plus the already-satisfied steps as
 * compact `history`; steps that are neither satisfied nor currently due are
 * simply absent, which is what keeps duplicates and stale entries impossible.
 *
 * B2C orders resolve to null — the whole result is only ever attached to an
 * Admin page, never to a customer payload.
 */
class OrderTaskResolver
{
    public const SECTION_COLLECTION = 'abholung';

    public const SECTION_OFFERS = 'angebote';

    public const SECTION_DOCUMENTS = 'dokumente';

    public const SECTION_STATUS = 'status';

    /**
     * The B2B status graph as a linear rank, so "has the order already moved
     * past this phase" is a single comparison. Mirrors
     * TransitionOrderStatus::B2B_ALLOWED_TRANSITIONS.
     */
    private const STATUS_RANK = [
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

    private const TERMINAL_STATUSES = ['cancelled', 'discarded'];

    /**
     * @param  array<string, mixed>  $order  One AdminQueryService::orderDetail() result.
     * @return array{next: array<string, mixed>|null, history: array<int, array<string, mixed>>, is_closed: bool, closed_status: string|null}|null
     */
    public function forOrderDetail(array $order): ?array
    {
        if (($order['vehicle_belongs'] ?? null) !== 'B2B') {
            return null;
        }

        $context = $this->context($order);
        $history = [];
        $next = null;

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
        $status = (string) ($order['order_status'] ?? '');
        $isCancelled = in_array($status, self::TERMINAL_STATUSES, true);
        $statusDates = $this->statusDates($order);
        $effectiveStatus = $isCancelled ? $this->lastActiveStatus($order) : $status;

        $offers = $this->rows($order['offers'] ?? [])
            ->reject(fn (array $offer) => ($offer['offer_status'] ?? null) === 'cancelled');

        return [
            'order_id' => (string) ($order['id'] ?? ''),
            'status' => $status,
            'is_closed' => $isCancelled || $status === 'completed',
            'rank' => self::STATUS_RANK[$effectiveStatus] ?? 0,
            'status_dates' => $statusDates,
            'created_at' => $order['created_at'] ?? null,
            'requested_date' => $order['collection']['requested_collection_date'] ?? null,
            'confirmed_date' => $order['collection']['confirmed_collection_date'] ?? null,
            'has_offer' => $offers->isNotEmpty(),
            'published_offer' => $offers->first(fn (array $offer) => ($offer['offer_status'] ?? null) === 'published'),
            'selected_offer' => $offers->first(fn (array $offer) => in_array($offer['offer_status'] ?? null, ['selected', 'closed'], true)),
            'draft_offer' => $offers->first(fn (array $offer) => ($offer['offer_status'] ?? null) === 'draft'),
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
                action: $this->action('patch', 'admin.orders.collection', $orderId, label: 'Abholung öffnen'),
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
                action: null,
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
                title: 'Werkstattangebote einholen',
                description: 'Das Erstgutachten ist abgeschlossen. Holen Sie die Werkstattangebote ein und erfassen Sie sie als Angebotsentwurf.',
                section: self::SECTION_OFFERS,
                done: $context['has_offer'] || $rank >= 5,
                open: $rank === 4 && ! $context['has_offer'],
                date: $context['gutachten']['created_at'] ?? $dates['inspected'] ?? null,
                dateLabel: 'Begutachtung abgeschlossen',
                action: null,
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
                action: null,
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
                title: 'Reparaturbeginn erfassen',
                description: 'Die Werkstatt ist beauftragt. Erfassen Sie den Reparaturbeginn, sobald das Fahrzeug in der Werkstatt ist.',
                section: self::SECTION_STATUS,
                done: $rank >= 6,
                open: $rank === 5,
                date: $dates['workshop'] ?? $dates['workshop_commissioned'] ?? null,
                dateLabel: 'Beauftragt am',
                action: $this->statusAction($orderId, 'workshop', 'Reparatur gestartet'),
            ),
            $this->definition(
                key: 'monitor_repair',
                title: 'Reparatur überwachen',
                description: 'Das Fahrzeug ist in Reparatur. Erfassen Sie den Abschluss, sobald die Werkstatt fertig gemeldet hat.',
                section: self::SECTION_STATUS,
                done: $rank >= 7,
                open: $rank === 6,
                date: $dates['workshop'] ?? null,
                dateLabel: 'In Reparatur seit',
                state: 'waiting',
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
                action: null,
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
            $this->definition(
                key: 'prepare_invoice',
                title: 'Rechnung erstellen',
                description: 'Das Fahrzeug ist zurückgegeben. Laden Sie die Rechnung hoch und veröffentlichen Sie sie für den Kunden.',
                section: self::SECTION_DOCUMENTS,
                done: $context['rechnung'] !== null || $rank >= 10,
                open: $rank === 9 && $context['rechnung'] === null,
                date: $context['rechnung']['created_at'] ?? $dates['vehicle_returned'] ?? null,
                dateLabel: 'Zurückgegeben am',
                action: null,
            ),
            $this->definition(
                key: 'mark_invoice_processed',
                title: 'Abrechnung abschließen',
                description: 'Die Rechnung liegt vor. Schließen Sie die Abrechnung ab.',
                section: self::SECTION_STATUS,
                done: $rank >= 10,
                open: $rank === 9 && $context['rechnung'] !== null,
                date: $dates['invoice_processed'] ?? $context['rechnung']['created_at'] ?? null,
                dateLabel: 'Rechnung vom',
                action: $this->statusAction($orderId, 'invoice_processed', 'Abrechnung abschließen'),
            ),
            $this->definition(
                key: 'complete_order',
                title: 'Auftrag abschließen',
                description: 'Die Abrechnung ist verarbeitet. Schließen Sie den Auftrag ab.',
                section: self::SECTION_STATUS,
                done: $rank >= 11,
                open: $rank === 10,
                date: $dates['completed'] ?? $dates['invoice_processed'] ?? null,
                dateLabel: 'Abrechnung abgeschlossen am',
                action: $this->statusAction($orderId, 'completed', 'Auftrag abschließen'),
            ),
        ];
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
            'method' => $method,
            'url' => route($routeName, $orderId),
            'payload' => $payload,
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
     */
    private function lastActiveStatus(array $order): string
    {
        $status = $this->rows($order['status_updates'] ?? [])
            ->sortByDesc('created_at')
            ->map(fn (array $update) => (string) ($update['old_status'] ?? ''))
            ->first(fn (string $value) => isset(self::STATUS_RANK[$value]));

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
