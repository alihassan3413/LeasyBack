<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Mail\Workshop\WorkshopCommissionedMail;
use App\Models\LeasybackOffer;
use App\Models\OrderAuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Models\B2bOfferPresentation;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderLogistics;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Commissioning the workshop that won the order.
 *
 * Acceptance and commissioning are two events, not one. A customer accepting an
 * offer says which workshop they chose; it does not say anybody has been told to
 * start work. Auto-commissioning on acceptance would collapse the two and put an
 * order into "workshop instructed" at a moment when no workshop has heard
 * anything — so the customer's decision leaves `order_status` alone (see
 * OfferService) and an admin performs this deliberately afterwards.
 *
 * The workshop is never chosen here. It is *resolved*: order → the one selected
 * offer → its presentation → the workshop snapshotted on it. There is no input
 * for an admin to name a different one, which is the point — the accepted offer
 * is a binding commitment to a specific workshop's price, and commissioning
 * anyone else would silently break it.
 */
class WorkshopCommissionService
{
    public const AUDIT_ACTION = 'WORKSHOP_COMMISSIONED';

    public const AUDIT_ACTION_RENOTIFIED = 'WORKSHOP_COMMISSION_RESENT';

    /** The selected offer was typed by hand, so no workshop can be derived from it. */
    public const BLOCKED_MANUAL = 'manual_offer';

    /** A quotation-backed offer, but the snapshot has no address to write to. */
    public const BLOCKED_NO_CONTACT = 'no_workshop_contact';

    public const BLOCKED_NO_SELECTED_OFFER = 'no_selected_offer';

    public const BLOCKED_LIFECYCLE = 'wrong_status';

    /** The statuses in which the workshop's repair instruction is still live. */
    public const RESENDABLE_STATUSES = ['workshop_commissioned', 'workshop', 'reworkshop'];

    public function __construct(
        private readonly TransitionOrderStatus $transitionOrderStatus,
    ) {}

    /**
     * The winning workshop and everything the confirmation dialog and the email
     * need, or null when this order has nothing to commission.
     *
     * @return array{offer: LeasybackOffer, presentation: B2bOfferPresentation, workshop: array<string, mixed>}|null
     */
    public function resolve(LeasybackOrder $order): ?array
    {
        $offer = LeasybackOffer::where('order_id', $order->id)
            ->where('offer_status', 'selected')
            ->first();

        if ($offer === null) {
            return null;
        }

        $presentation = B2bOfferPresentation::where('offer_id', $offer->offer_id)->first();
        $workshop = $presentation?->workshop;

        if ($presentation === null || ! is_array($workshop) || $workshop === []) {
            return null;
        }

        return ['offer' => $offer, 'presentation' => $presentation, 'workshop' => $workshop];
    }

    /**
     * Whether this order's workshop has to be instructed through commission()
     * rather than by setting the status: there is an accepted, quotation-backed
     * offer with an address to send the repair order to. Without one — a
     * manual offer, or a workshop with no email — setting the status directly
     * is the only way forward.
     */
    public function requiresCommissionAction(LeasybackOrder $order): bool
    {
        $target = $this->resolve($order);

        return $target !== null && ! blank($target['workshop']['contact_email'] ?? null);
    }

    /**
     * Everything Admin needs to render the step: whether it can be done, why
     * not if not, and what has already happened. Computed rather than stored —
     * there is no commissioning state column to fall out of step with the order.
     *
     * @return array<string, mixed>
     */
    public function state(LeasybackOrder $order): array
    {
        return $this->compose(
            $order,
            $this->resolve($order),
            $this->commissionAudit($order),
            LeasybackOffer::where('order_id', $order->id)->where('offer_status', 'selected')->exists(),
            null,
        );
    }

    /**
     * The same state for many orders, in a fixed number of queries.
     *
     * Exists for the admin task dashboard, which needs this for every active
     * order at once — asking per order cost three or four round trips each.
     * Composition is shared with state(), so a batch answer and a single
     * answer can never differ; only the loading is different.
     *
     * @param  iterable<int, LeasybackOrder>  $orders
     * @return array<string, array<string, mixed>>
     */
    public function statesFor(iterable $orders): array
    {
        $orders = collect($orders);

        if ($orders->isEmpty()) {
            return [];
        }

        $orderIds = $orders->pluck('id')->all();

        $selectedOffers = LeasybackOffer::whereIn('order_id', $orderIds)
            ->where('offer_status', 'selected')
            ->get()
            ->keyBy('order_id');

        $presentations = B2bOfferPresentation::whereIn('offer_id', $selectedOffers->pluck('offer_id')->all())
            ->get()
            ->keyBy('offer_id');

        // Resolved here in one query because TransitionOrderStatus::isB2bOrder()
        // looks the vehicle up per order — the last per-order query in the
        // batch path, and the one that kept it scaling with N.
        $channels = Vehicle::whereIn('vehicle_id', $orders->pluck('vehicle_id')->all())
            ->pluck('vehicle_belongs', 'vehicle_id');

        $audits = OrderAuditLog::whereIn('order_id', $orderIds)
            ->where('action', self::AUDIT_ACTION)
            ->orderBy('changed_at')
            ->get()
            ->groupBy('order_id');

        $states = [];

        foreach ($orders as $order) {
            $offer = $selectedOffers->get($order->id);
            $presentation = $offer === null ? null : $presentations->get($offer->offer_id);
            $workshop = $presentation?->workshop;

            $target = $offer !== null && $presentation !== null && is_array($workshop) && $workshop !== []
                ? ['offer' => $offer, 'presentation' => $presentation, 'workshop' => $workshop]
                : null;

            $states[$order->id] = $this->compose(
                $order,
                $target,
                $audits->get($order->id)?->first(),
                $offer !== null,
                $channels->get($order->vehicle_id) === 'B2B',
            );
        }

        return $states;
    }

    /**
     * @param  array<string, mixed>|null  $target
     * @return array<string, mixed>
     */
    private function compose(
        LeasybackOrder $order,
        ?array $target,
        ?OrderAuditLog $auditRow,
        bool $hasSelectedOffer,
        ?bool $isB2b,
    ): array {
        $commissioned = $auditRow !== null;
        $audited = $auditRow?->new_values ?? [];
        $reason = $commissioned ? null : $this->blockingReason($order, $target, $hasSelectedOffer, $isB2b);

        return [
            'is_commissioned' => $commissioned,
            'commissioned_at' => $auditRow?->changed_at?->toISOString(),
            // The live resolution where there is one, otherwise what was
            // recorded at the time — an order stays able to say who was
            // commissioned even if the offer behind it is later disturbed.
            'workshop' => $target['workshop'] ?? ($audited['workshop'] ?? null),
            'offer_id' => $target['offer']->offer_id ?? ($audited['offer_id'] ?? null),
            // A B2B offer is net-only: its gross columns are placeholders and
            // rendered as "0,00 €" when passed through.
            'offer_total_gross' => $target === null || ($isB2b ?? TransitionOrderStatus::isB2bOrder($order)) ? null : (string) $target['offer']->final_total_gross,
            'offer_total_net' => $target === null ? null : (string) $target['offer']->final_total_net,
            'can_resend' => $commissioned && in_array($order->order_status, self::RESENDABLE_STATUSES, true),
            // Read from the presentation the commission was sent for, which the
            // audit row names — the currently selected offer may no longer be it.
            'notified_at' => $this->notifiedAt($target, $audited),
            'can_commission' => ! $commissioned && $target !== null && $reason === null,
            'blocked_reason' => $reason,
        ];
    }

    /**
     * Commission the winning workshop. Idempotent: the second and every later
     * call returns the established result and writes nothing.
     *
     * The audit row is what makes it so, rather than the order's status. A
     * status check would stop being a truthful answer the moment the order moved
     * on to `workshop` or beyond, and a retry arriving then would look like a
     * fresh commission. "Has this order been commissioned" is a question about
     * something that happened once, so it is answered from the record of it
     * happening.
     *
     * @return array{already_commissioned: bool, workshop: array<string, mixed>, notified: bool}
     */
    public function commission(LeasybackOrder $order, User $user): array
    {
        $result = DB::transaction(function () use ($order, $user) {
            /** @var LeasybackOrder $locked */
            $locked = LeasybackOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $existing = $this->commissionAudit($locked);

            if ($existing !== null) {
                return [
                    'already_commissioned' => true,
                    'workshop' => $existing->new_values['workshop'] ?? [],
                    'presentation_id' => $existing->new_values['presentation_id'] ?? null,
                ];
            }

            $target = $this->resolve($locked);
            $reason = $this->blockingReason(
                $locked,
                $target,
                LeasybackOffer::where('order_id', $locked->id)->where('offer_status', 'selected')->exists(),
            );

            if ($reason !== null) {
                $this->fail(422, $this->message($reason));
            }

            $this->transitionOrderStatus->__invoke(
                $locked,
                'workshop_commissioned',
                'admin',
                $user->name ?? $user->email,
                $user->id,
                request()?->ip(),
            );

            OrderAuditLog::create([
                'order_id' => $locked->id,
                'vehicle_id' => $locked->vehicle_id,
                'action' => self::AUDIT_ACTION,
                'old_values' => null,
                'new_values' => [
                    'offer_id' => $target['offer']->offer_id,
                    'workshop_quotation_id' => $target['presentation']->workshop_quotation_id,
                    'presentation_id' => $target['presentation']->id,
                    // The snapshot, not a reference to it: this row has to keep
                    // answering "who was commissioned" after the quotation is
                    // gone. No token or secret is ever part of it.
                    'workshop' => $target['workshop'],
                ],
                'changed_by_user_id' => $user->id,
            ]);

            return [
                'already_commissioned' => false,
                'workshop' => $target['workshop'],
                'presentation_id' => $target['presentation']->id,
            ];
        });

        // After the commit, and only for the call that actually commissioned:
        // a retry must not put a second repair order in the workshop's inbox.
        $notified = false;

        if (! $result['already_commissioned']) {
            $notified = $this->notify($order->fresh(), $result['presentation_id']);
        }

        return [
            'already_commissioned' => $result['already_commissioned'],
            'workshop' => $result['workshop'],
            'notified' => $notified,
        ];
    }

    /**
     * Send the commissioning email again. Exists because a transport failure
     * must not undo a real commissioning: the order stays commissioned, the
     * failure is logged and left visible as "not notified", and this is how an
     * admin closes the gap without touching the lifecycle.
     */
    public function resendNotification(LeasybackOrder $order, User $user): bool
    {
        $audit = $this->commissionAudit($order);

        if ($audit === null) {
            $this->fail(422, 'Für diesen Auftrag wurde noch keine Werkstatt beauftragt.');
        }

        // A repeat of the repair instruction only makes sense while that
        // instruction is still live. Resent after cancellation it told a
        // workshop to repair a car for an order that no longer exists; after
        // the repair it asks for work that is already done.
        $status = LeasybackOrder::whereKey($order->id)->value('order_status');

        if (! in_array($status, self::RESENDABLE_STATUSES, true)) {
            $this->fail(422, 'Die Beauftragung kann in diesem Auftragsstatus nicht erneut gesendet werden.');
        }

        $sent = $this->notify($order, $audit->new_values['presentation_id'] ?? null);

        OrderAuditLog::create([
            'order_id' => $order->id,
            'vehicle_id' => $order->vehicle_id,
            'action' => self::AUDIT_ACTION_RENOTIFIED,
            'old_values' => null,
            'new_values' => ['delivered' => $sent],
            'changed_by_user_id' => $user->id,
        ]);

        return $sent;
    }

    /**
     * Why this order cannot be commissioned, or null when it can.
     *
     * @param  array{offer: LeasybackOffer, presentation: B2bOfferPresentation, workshop: array<string, mixed>}|null  $target
     */
    /**
     * `$hasSelectedOffer` is passed in rather than queried: the caller has
     * already loaded the selected offer (singly or in bulk), and asking again
     * here is what made the batch path a query per order.
     */
    private function blockingReason(LeasybackOrder $order, ?array $target, bool $hasSelectedOffer, ?bool $isB2b = null): ?string
    {
        if (! in_array('workshop_commissioned', TransitionOrderStatus::allowedNextStatuses(
            $order->order_status,
            $isB2b ?? TransitionOrderStatus::isB2bOrder($order),
        ), true)) {
            return self::BLOCKED_LIFECYCLE;
        }

        if ($target === null) {
            return $hasSelectedOffer
                ? self::BLOCKED_MANUAL
                : self::BLOCKED_NO_SELECTED_OFFER;
        }

        return blank($target['workshop']['contact_email'] ?? null) ? self::BLOCKED_NO_CONTACT : null;
    }

    private function message(string $reason): string
    {
        return match ($reason) {
            self::BLOCKED_NO_SELECTED_OFFER => 'Für diesen Auftrag wurde noch kein Angebot angenommen.',
            self::BLOCKED_MANUAL => 'Das angenommene Angebot wurde manuell erstellt und hat keine hinterlegte Werkstatt. Die Werkstatt muss manuell beauftragt werden.',
            self::BLOCKED_NO_CONTACT => 'Für die gewählte Werkstatt ist keine E-Mail-Adresse hinterlegt.',
            default => 'In diesem Auftragsstatus kann keine Werkstatt beauftragt werden.',
        };
    }

    /**
     * @param  array<string, mixed>|null  $target
     * @param  array<string, mixed>  $audited
     */
    private function notifiedAt(?array $target, array $audited): ?string
    {
        $presentationId = $audited['presentation_id'] ?? null;

        if ($target !== null && ($presentationId === null || $target['presentation']->id === $presentationId)) {
            return $target['presentation']->workshop_notified_at?->toISOString();
        }

        if ($presentationId === null) {
            return null;
        }

        return B2bOfferPresentation::whereKey($presentationId)->first()?->workshop_notified_at?->toISOString();
    }

    private function commissionAudit(LeasybackOrder $order): ?OrderAuditLog
    {
        return OrderAuditLog::where('order_id', $order->id)
            ->where('action', self::AUDIT_ACTION)
            ->orderBy('changed_at')
            ->first();
    }

    /**
     * Best-effort by design. A failed send is logged and reported as not
     * notified; it never throws, because the commissioning it follows is
     * already committed and is not in question.
     */
    private function notify(LeasybackOrder $order, ?string $presentationId): bool
    {
        $presentation = $presentationId === null ? null : B2bOfferPresentation::find($presentationId);
        $workshop = $presentation?->workshop ?? [];
        $recipient = $workshop['contact_email'] ?? null;

        if ($presentation === null || blank($recipient)) {
            Log::warning('Workshop commissioning email skipped — no recipient', [
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
            ]);

            return false;
        }

        try {
            Mail::to($recipient)->send($this->mailFor($order, $presentation, $workshop));
        } catch (\Throwable $e) {
            Log::error('Workshop commissioning email failed', [
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $presentation->forceFill(['workshop_notified_at' => now()])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $workshop
     */
    private function mailFor(LeasybackOrder $order, B2bOfferPresentation $presentation, array $workshop): WorkshopCommissionedMail
    {
        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->first();
        $logistics = OrderLogistics::where('auftragsnummer', $order->auftragsnummer)->first();

        return new WorkshopCommissionedMail(
            workshopName: (string) ($workshop['company_name'] ?? $workshop['label'] ?? 'Werkstatt'),
            orderReference: (string) $order->auftragsnummer,
            vehicleLabel: trim(($vehicle->make ?? '').' '.($vehicle->model ?? '')) ?: 'Fahrzeug',
            licensePlate: $vehicle?->license_plate,
            vin: $vehicle?->vin,
            positions: $this->approvedPositions($presentation),
            totalNet: (string) $presentation->repair_total_net,
            earliestRepairStart: $this->germanDate($workshop['earliest_repair_start'] ?? null),
            processingDays: $workshop['processing_days'] ?? null,
            confirmedRepairStart: $this->germanDate($logistics?->confirmed_repair_start_date?->toDateString()),
        );
    }

    /**
     * The positions the customer approved, carrying the workshop's own prices.
     *
     * Read from the frozen snapshot rather than the live positions, so the
     * repair order and the accepted offer can never describe different work.
     * `appraisal_amount_net` is deliberately dropped: what the lessor would have
     * charged is the customer's business, not the repairer's.
     *
     * @return array<int, array{component: string, repair_method: string|null, amount_net: string|null, not_repairable: bool}>
     */
    private function approvedPositions(B2bOfferPresentation $presentation): array
    {
        return array_map(fn (array $line) => [
            'component' => (string) ($line['component'] ?? ''),
            'repair_method' => $line['repair_method'] ?? null,
            'amount_net' => $line['repair_amount_net'] ?? null,
            'not_repairable' => (bool) ($line['not_repairable'] ?? false),
        ], $presentation->lines ?? []);
    }

    private function germanDate(?string $date): ?string
    {
        if (blank($date)) {
            return null;
        }

        return CarbonImmutable::parse($date)->format('d.m.Y');
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }
}
