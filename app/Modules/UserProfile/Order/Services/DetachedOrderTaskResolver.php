<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Enums\OrderStatus;
use App\Support\PortalTimestamp;
use DateTimeInterface;
use Illuminate\Support\Collection;

class DetachedOrderTaskResolver
{
    public const CALL_CUSTOMER_ABOUT_PENDING_OFFER = 'call_customer_about_pending_offer';

    public const CALL_CUSTOMER_ABOUT_PENDING_PAYMENT = 'call_customer_about_pending_payment';

    public const OFFER_FOLLOW_UP_HOURS = 48;

    public const PAYMENT_FOLLOW_UP_HOURS = 48;

    public function __construct(private readonly OrderTaskPriorityResolver $priority) {}

    /**
     * @param  array<string, mixed>  $order  One AdminQueryService::orderDetail() result.
     * @return array<int, array<string, mixed>>
     */
    public function forOrderDetail(array $order, ?DateTimeInterface $now = null): array
    {
        if (($order['vehicle_belongs'] ?? null) === 'B2B') {
            return [];
        }

        if (in_array((string) ($order['order_status'] ?? ''), OrderStatus::closedValues(), true)) {
            return [];
        }

        $now = $now ?? PortalTimestamp::now();

        return array_values(array_filter([
            $this->pendingOfferFollowUp($order, $now),
            $this->pendingPaymentFollowUp($order, $now),
        ]));
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>|null
     */
    private function pendingOfferFollowUp(array $order, DateTimeInterface $now): ?array
    {
        $offers = $this->rows($order['offers'] ?? []);

        if ($offers->contains(fn (array $offer) => in_array($offer['offer_status'] ?? null, ['selected', 'closed'], true))) {
            return null;
        }

        $offer = $offers
            ->filter(fn (array $offer) => ($offer['offer_status'] ?? null) === 'published')
            ->reject(fn (array $offer) => (bool) ($offer['presentation']['is_expired'] ?? false))
            ->sortBy(fn (array $offer) => (string) ($offer['published_at'] ?? ''))
            ->last();

        if ($offer === null) {
            return null;
        }

        $publishedAt = PortalTimestamp::instant($offer['published_at'] ?? null);

        if ($publishedAt === null) {
            return null;
        }

        if ($now->getTimestamp() < $publishedAt->addHours(self::OFFER_FOLLOW_UP_HOURS)->getTimestamp()) {
            return null;
        }

        $contactedAt = PortalTimestamp::instant($order['last_customer_contact_at'] ?? null);

        if ($contactedAt !== null && $contactedAt->getTimestamp() >= $publishedAt->getTimestamp()) {
            return null;
        }

        return $this->task(
            key: self::CALL_CUSTOMER_ABOUT_PENDING_OFFER,
            title: 'Kunden zum offenen Angebot anrufen',
            description: sprintf(
                'Das Angebot ist seit über %d Stunden veröffentlicht und der Kunde hat weder angenommen noch abgelehnt. Rufen Sie ihn an.',
                self::OFFER_FOLLOW_UP_HOURS,
            ),
            section: OrderTaskResolver::SECTION_OFFERS,
            date: (string) $offer['published_at'],
            dateLabel: 'Angebot veröffentlicht am',
            now: $now,
        );
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>|null
     */
    private function pendingPaymentFollowUp(array $order, DateTimeInterface $now): ?array
    {
        $payment = (array) ($order['repair_payment'] ?? []);

        if (! ($payment['blocks_pickup'] ?? false)) {
            return null;
        }

        $requestedAt = PortalTimestamp::instant($payment['payment_link_created_at'] ?? null);

        if ($requestedAt === null) {
            return null;
        }

        if ($now->getTimestamp() < $requestedAt->addHours(self::PAYMENT_FOLLOW_UP_HOURS)->getTimestamp()) {
            return null;
        }

        return $this->task(
            key: self::CALL_CUSTOMER_ABOUT_PENDING_PAYMENT,
            title: 'Kunden zur offenen Zahlung anrufen',
            description: sprintf(
                'Die Rechnung ist seit über %d Stunden versendet und die Reparaturkosten sind noch nicht bezahlt. Rufen Sie den Kunden an.',
                self::PAYMENT_FOLLOW_UP_HOURS,
            ),
            section: OrderTaskResolver::SECTION_STATUS,
            date: (string) $payment['payment_link_created_at'],
            dateLabel: 'Zahlungsaufforderung versendet am',
            now: $now,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function task(
        string $key,
        string $title,
        string $description,
        string $section,
        string $date,
        string $dateLabel,
        DateTimeInterface $now,
    ): array {
        $task = [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'state' => 'open',
            'actor' => OrderTaskResolver::ACTOR_ADMIN,
            'date' => $date,
            'date_label' => $dateLabel,
            'priority_date' => $date,
            'section' => $section,
            'action' => null,
        ];

        return [
            ...$task,
            'priority' => $this->priority->forTask($task, false, $now)->value,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(mixed $rows): Collection
    {
        return collect(is_iterable($rows) ? $rows : [])->map(fn (mixed $row) => (array) $row)->values();
    }
}
