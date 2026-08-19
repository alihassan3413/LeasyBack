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
     * Everything Admin needs to render the step: whether it can be done, why
     * not if not, and what has already happened. Computed rather than stored —
     * there is no commissioning state column to fall out of step with the order.
     *
     * @return array<string, mixed>
     */
    public function state(LeasybackOrder $order): array
    {
        $target = $this->resolve($order);
        $auditRow = $this->commissionAudit($order);
        $commissioned = $auditRow !== null;
        $audited = $auditRow?->new_values ?? [];
        $reason = $commissioned ? null : $this->blockingReason($order, $target);

        return [
            'is_commissioned' => $commissioned,
            'commissioned_at' => $auditRow?->changed_at?->toISOString(),
            // The live resolution where there is one, otherwise what was
            // recorded at the time — an order stays able to say who was
            // commissioned even if the offer behind it is later disturbed.
            'workshop' => $target['workshop'] ?? ($audited['workshop'] ?? null),
            'offer_id' => $target['offer']->offer_id ?? ($audited['offer_id'] ?? null),
            'offer_total_gross' => $target === null ? null : (string) $target['offer']->final_total_gross,
            'offer_total_net' => $target === null ? null : (string) $target['offer']->final_total_net,
            'notified_at' => $target === null ? null : $target['presentation']->workshop_notified_at?->toISOString(),
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
            $reason = $this->blockingReason($locked, $target);

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
    private function blockingReason(LeasybackOrder $order, ?array $target): ?string
    {
        if (! in_array('workshop_commissioned', TransitionOrderStatus::allowedNextStatuses(
            $order->order_status,
            TransitionOrderStatus::isB2bOrder($order),
        ), true)) {
            return self::BLOCKED_LIFECYCLE;
        }

        if ($target === null) {
            return LeasybackOffer::where('order_id', $order->id)->where('offer_status', 'selected')->exists()
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
