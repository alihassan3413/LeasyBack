<?php

namespace App\Modules\UserProfile\Order\Actions;

use App\Enums\DocumentType;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Models\LeasybackOffer;
use App\Models\OfferAuditLog;
use App\Models\Vehicle;
use App\Modules\PartnerApi\Services\PartnerWebhookEvents;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderBilling;
use App\Modules\UserProfile\Order\Models\OrderLogistics;
use App\Modules\UserProfile\Order\Models\OrderStatusUpdate;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Payment\Enums\PaymentPurpose;
use App\Modules\UserProfile\Payment\Jobs\IssueRepairInvoice;
use App\Modules\UserProfile\Payment\Models\OrderPayment;
use App\Modules\UserProfile\Payment\Services\RepairPaymentService;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Notifications\NotificationPayload;
use App\Services\Mail\OrderMailer;
use App\Services\Notifier;
use App\Support\OrderStatusLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single place `leasyback_orders.order_status` is allowed to change.
 * Replaces the reference system's (and this app's own pre-Checkpoint-6)
 * pattern of an unconditional `UPDATE ... SET order_status = ?` with no
 * guard on the current value — see docs/B2C_ADMIN_STATUS_MATRIX.md §1 for
 * the transition table this enforces. The workshop cycle is closed:
 * `reinspection` ↔ `reworkshop` loops until a re-inspection passes and the
 * order moves to `delivered`.
 *
 * Every call writes exactly one row to `leasyback_order_status_updates`,
 * per §6 of the same doc. Requesting the order's current status again is a
 * no-op (not an error) rather than a rejected transition, so a redelivered
 * webhook call doesn't fail loudly for doing nothing.
 *
 * This is also the single place a "your order status changed" customer
 * email goes out (Checkpoint 12) — every real transition flows through
 * here regardless of caller (webhook, Admin, TransitionOrderStatus's
 * various controller call sites), so centralizing the notification here
 * is the only way to guarantee one email per real transition rather than
 * duplicating send calls at every call site and risking one being missed.
 * The email is sent after the DB transaction commits, not inside it —
 * network I/O has no business holding a row lock open — and is strictly
 * best-effort (a failed send never rolls back a valid status change).
 */
class TransitionOrderStatus
{
    public function __construct(
        private readonly VehicleScopeService $vehicleScope,
        private readonly Notifier $notifier,
        private readonly OrderMailer $orderMailer,
        private readonly PartnerWebhookEvents $webhooks,
        private readonly RepairPaymentService $repairPayments,
    ) {}

    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'order_requested' => ['order_placed', 'discarded', 'cancelled'],
        'order_placed' => ['confirmed', 'cancelled'],
        'confirmed' => ['inspected', 'completed', 'cancelled'],

        /*
         * Two ways out of `inspected`, and they are not alternatives so much as
         * a normal path and a shortcut.
         *
         * The normal one goes through `workshop_commissioned`: the customer has
         * accepted a quotation-backed offer, and LeasyBack has now actually
         * instructed the workshop that quoted it. Acceptance and commissioning
         * are separate business events — the customer choosing an offer is not
         * the same as anyone being told to start work — so they are separate
         * statuses rather than one implying the other.
         *
         * `inspected → workshop` stays for the cases that never went through an
         * offer at all: a price agreed off-system, a manually created offer with
         * no workshop behind it, and every order written before commissioning
         * existed. WorkshopCommissionService refuses to let it become a way of
         * skipping the notification when there *is* a workshop to notify.
         */
        'inspected' => ['workshop_commissioned', 'workshop', 'completed', 'cancelled'],
        'workshop_commissioned' => ['workshop', 'completed', 'cancelled'],

        'workshop' => ['reinspection', 'completed', 'cancelled'],

        /*
         * `reinspection` means the follow-up inspection has been *performed* —
         * FinalInspectionCompletedMail says so to the customer, and the
         * provider callback's own event name is `reinspection_completed`. What
         * it deliberately does not say is the outcome: that is the branch taken
         * from here. Back to `reworkshop` means the repair did not hold, and
         * `delivered` means it did. The finding itself lives where findings
         * live — the Nachgutachten in `vehicle_report_documents` — so no status
         * carries report data.
         */
        'reinspection' => ['reworkshop', 'delivered', 'cancelled'],

        /*
         * The loop back to `reinspection`, and the reason this map changed:
         * `reworkshop` used to offer only `cancelled`, so a car that failed its
         * follow-up inspection could never be finished — the single way out of
         * a second repair was to cancel a case the customer had already paid a
         * workshop for. The cycle is deliberately unbounded: how many times a
         * repair has to be redone is a fact about the car, not a number this
         * table should cap.
         */
        'reworkshop' => ['reinspection', 'cancelled'],

        /*
         * `delivered` is "ready for collection", not "finished" — it is what
         * sends VehicleReadyForPickupMail. The car is still at the workshop and
         * the case is still open, so it is not terminal and not closed; see
         * OrderStatus::closedValues(). Collection is what closes a B2C case.
         */
        'delivered' => ['completed', 'cancelled'],

        'completed' => [],
        'cancelled' => [],
        'discarded' => [],
    ];

    /**
     * The B2B return process. `confirmed` is where the two channels fork —
     * a B2C order goes straight to `inspected` at the station, a B2B vehicle
     * is collected first — which is why the graph cannot be a single map
     * keyed by from-status alone.
     *
     * @var array<string, list<string>>
     */
    private const B2B_ALLOWED_TRANSITIONS = [
        'order_requested' => ['order_placed', 'discarded', 'cancelled'],
        'order_placed' => ['confirmed', 'cancelled'],
        'confirmed' => ['vehicle_collected', 'cancelled'],
        'vehicle_collected' => ['inspected', 'cancelled'],
        'inspected' => ['workshop_commissioned', 'cancelled'],
        'workshop_commissioned' => ['workshop', 'cancelled'],
        'workshop' => ['repair_completed', 'cancelled'],
        'repair_completed' => ['reinspection', 'cancelled'],
        'reinspection' => ['vehicle_returned', 'cancelled'],
        'vehicle_returned' => ['invoice_processed', 'cancelled'],
        'invoice_processed' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'discarded' => [],
    ];

    /**
     * @param  array<string, mixed>  $additionalAttributes  Extra columns to persist on the order in the same update (e.g. sent_at, response_status).
     */
    public function __invoke(
        LeasybackOrder $order,
        string $toStatus,
        string $authSource,
        string $updatedByLabel,
        ?int $changedByUserId = null,
        ?string $callerIp = null,
        ?string $bewertungId = null,
        array $additionalAttributes = [],
    ): LeasybackOrder {
        $realTransition = false;

        $result = DB::transaction(function () use ($order, $toStatus, $authSource, $updatedByLabel, $changedByUserId, $callerIp, $bewertungId, $additionalAttributes, &$realTransition) {
            /** @var LeasybackOrder $locked */
            $locked = LeasybackOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $fromStatus = $locked->order_status;
            $isB2b = self::isB2bOrder($locked);

            $this->guardChannel($toStatus, $isB2b);
            $this->guardBillingBeforeCompletion($locked, $toStatus, $isB2b);
            $this->guardPaymentBeforeCompletion($locked, $toStatus, $isB2b);

            if ($fromStatus === $toStatus) {
                if ($additionalAttributes !== []) {
                    $locked->update($additionalAttributes);
                }

                return $locked->fresh();
            }

            $allowed = self::transitionsFor($isB2b)[$fromStatus] ?? [];
            if (! in_array($toStatus, $allowed, true)) {
                throw ValidationException::withMessages([
                    'order_status' => "Cannot transition order from '{$fromStatus}' to '{$toStatus}'.",
                ]);
            }

            if ($isB2b) {
                $this->guardB2bPrerequisites($locked, $toStatus);
            }

            $locked->update([...$additionalAttributes, 'order_status' => $toStatus]);

            if (in_array($toStatus, [OrderStatus::Cancelled->value, OrderStatus::Discarded->value], true)) {
                $this->closeOpenWork($locked, $changedByUserId, $updatedByLabel);
            }

            OrderStatusUpdate::create([
                'auftragsnummer' => $locked->auftragsnummer,
                'bewertung_id' => $bewertungId,
                'old_status' => $fromStatus,
                'new_status' => $toStatus,
                'updated_by_user_id' => $changedByUserId,
                'updated_by' => $updatedByLabel,
                'auth_source' => $authSource,
                'caller_ip' => $callerIp,
            ]);

            $realTransition = true;

            $updated = $locked->fresh();

            // Inside the transaction, deliberately. The event row has to share
            // the fate of the status change: a transition that rolls back —
            // because the billing gate raised, or a caller further out failed —
            // must not leave a partner told it happened. Only the *delivery* is
            // deferred; PartnerWebhookEmitter dispatches after commit and
            // swallows its own failures, so no partner endpoint and no bug in
            // that module can hold this lock open or fail this transition.
            $this->webhooks->orderStatusChanged($updated, $fromStatus);

            return $updated;
        });

        if ($realTransition) {
            // Deferred to the commit of the *outermost* transaction. Callers
            // such as WorkshopCommissionService and OrderCollectionService
            // transition inside a transaction of their own; sending straight
            // away told the customer about a change that could still roll back
            // with them. Outside any transaction this runs immediately.
            DB::afterCommit(function () use ($result, $toStatus) {
                // Runs before the status mail so it can claim ownership of the
                // "ready for pickup" message: a B2C car is only collectable once
                // its repair is paid for, and exactly one sender may say so.
                $paymentOwnsPickupMail = $toStatus === OrderStatus::Delivered->value
                    && $this->repairPayments->startForDeliveredOrder($result, self::isB2bOrder($result));

                if ($toStatus === OrderStatus::Delivered->value && ! self::isB2bOrder($result)) {
                    IssueRepairInvoice::dispatch($result->id);
                }

                $this->notifyStatusChange($result, $paymentOwnsPickupMail);
            });
        }

        return $result;
    }

    /**
     * Best-effort, never throws — a failed send must never surface as a
     * failed status transition (the DB write already committed by the
     * time this runs).
     */
    private function notifyStatusChange(LeasybackOrder $order, bool $suppressStatusMail = false): void
    {
        $vehicle = $order->vehicle;
        if ($vehicle === null) {
            return;
        }

        // The presented wording, not the raw status: an order that reaches
        // `delivered` owing money is not collectable, and telling the customer
        // "Abholbereit" while the portal shows them a pay-now banner is the
        // contradiction RepairPaymentPresentation exists to prevent. The
        // charge is opened before this runs, so the stage is already knowable.
        $label = OrderStatusLabel::presented(
            $order->order_status,
            $this->repairPayments->presentedStage($order, self::isB2bOrder($order)),
        );

        $this->notifier->send(
            $this->vehicleScope->resolveOwnerUsers($vehicle),
            NotificationPayload::make(
                NotificationType::OrderStatusChanged,
                'Status aktualisiert',
                sprintf('%s: %s', $vehicle->license_plate, $label),
                '/dashboard',
                [
                    'auftragsnummer' => $order->auftragsnummer,
                    'order_id' => $order->id,
                    'vehicle_id' => $vehicle->vehicle_id,
                    'status' => $order->order_status,
                ],
            ),
        );

        if ($suppressStatusMail) {
            return;
        }

        $this->orderMailer->statusUpdated($order, $vehicle);
    }

    /**
     * The channel is read from the persisted order's own vehicle inside the
     * locked transaction, never from a caller-supplied flag, so no request
     * payload can talk a B2C order onto the B2B graph.
     */
    public static function isB2bOrder(LeasybackOrder $order): bool
    {
        return Vehicle::where('vehicle_id', $order->vehicle_id)->value('vehicle_belongs') === 'B2B';
    }

    /**
     * b2b.txt §21: an order must not be marked complete before its mandatory
     * billing step has been processed. Enforced here, inside the locked
     * transaction, because this action is the only writer of order_status —
     * so no controller, task action or future caller can route around it.
     *
     * Reads the billing record directly, matching how isB2bOrder() resolves
     * the vehicle, rather than taking a constructor dependency.
     *
     * B2C is untouched: `completed` is a B2B-only status, so guardChannel()
     * has already rejected it for a B2C order before this runs.
     */
    private function guardBillingBeforeCompletion(LeasybackOrder $order, string $toStatus, bool $isB2b): void
    {
        if (! $isB2b || $toStatus !== OrderStatus::Completed->value) {
            return;
        }

        $billing = OrderBilling::where('order_id', $order->id)->first();

        if ($billing?->isProcessed() === true) {
            return;
        }

        throw ValidationException::withMessages([
            'order_status' => 'Der Auftrag kann nicht abgeschlossen werden, solange die Abrechnung nicht als verarbeitet markiert ist.',
        ]);
    }

    /**
     * The B2C counterpart of the billing gate: a car is not collectable until
     * its repair has been paid for.
     *
     * Inside the locked transaction, in the sole writer of `order_status`, so
     * no controller or task action can route around it.
     *
     * A missing payment row does not open the gate by itself — an order that
     * reached `delivered` under this code always has one, so absence is only
     * safe when nothing was owed. That is re-derived from the accepted offer
     * rather than assumed, which keeps a hand-inserted or legacy order from
     * completing an unpaid repair.
     */
    private function guardPaymentBeforeCompletion(LeasybackOrder $order, string $toStatus, bool $isB2b): void
    {
        if ($isB2b || $toStatus !== OrderStatus::Completed->value) {
            return;
        }

        // The gate exists to hold a repaired car until its repair is paid for,
        // so it applies to the release itself. A case closed from earlier in
        // the flow never reached a repair and has no car to withhold.
        if ($order->order_status !== OrderStatus::Delivered->value) {
            return;
        }

        $payment = OrderPayment::where('order_id', $order->id)
            ->where('purpose', PaymentPurpose::Repair->value)
            ->first();

        if ($payment === null) {
            $owed = $this->repairPayments->grossCents($this->repairPayments->selectedOffer($order)) > 0;

            if (! $owed) {
                return;
            }
        } elseif ($payment->status->satisfiesReleaseGate()) {
            return;
        }

        throw ValidationException::withMessages([
            'order_status' => 'Das Fahrzeug kann erst nach Zahlungseingang der Reparaturkosten abgeholt werden.',
        ]);
    }

    /**
     * The B2B graph is linear, but a status is a claim about the world — "the
     * vehicle was collected", "the customer approved the repair" — and the
     * generic status endpoint must not be able to make that claim before the
     * fact behind it exists. Each check names the one record that proves the
     * step happened; the task tree (OrderTaskResolver) asks for exactly the
     * same records, so the two cannot disagree about what is due.
     *
     * Checked inside the locked transaction, in the single writer of
     * `order_status`, so no controller or task action can route around it.
     */
    private function guardB2bPrerequisites(LeasybackOrder $order, string $toStatus): void
    {
        $message = self::unmetB2bPrerequisite($order, $toStatus);

        if ($message !== null) {
            throw ValidationException::withMessages(['order_status' => $message]);
        }
    }

    /**
     * Why a B2B order cannot move to `$toStatus` yet, or null when the fact
     * behind that status exists. Public so the admin status menu can leave out
     * transitions the guard would refuse, instead of offering a button that
     * only ever produces an error.
     */
    public static function unmetB2bPrerequisite(LeasybackOrder $order, string $toStatus): ?string
    {
        return match ($toStatus) {
            OrderStatus::VehicleCollected->value => OrderLogistics::where('auftragsnummer', $order->auftragsnummer)
                ->whereNotNull('confirmed_collection_date')->exists()
                ? null
                : 'Das Fahrzeug kann erst als abgeholt erfasst werden, wenn ein Abholtermin bestätigt ist.',
            OrderStatus::Inspected->value => DB::table('vehicle_report_documents')
                ->where('auftragsnummer', $order->auftragsnummer)
                ->where('document_type', DocumentType::Gutachten->value)
                ->exists()
                ? null
                : 'Bitte laden Sie zuerst das Erstgutachten hoch, bevor Sie die Begutachtung abschließen.',
            OrderStatus::WorkshopCommissioned->value => LeasybackOffer::where('order_id', $order->id)
                ->where('offer_status', 'selected')->exists()
                ? null
                : 'Die Werkstatt kann erst beauftragt werden, wenn der Kunde ein Angebot freigegeben hat.',
            OrderStatus::Workshop->value => OrderLogistics::where('auftragsnummer', $order->auftragsnummer)
                ->whereNotNull('confirmed_repair_start_date')->exists()
                ? null
                : 'Bitte tragen Sie zuerst den bestätigten Reparaturtermin ein.',
            OrderStatus::InvoiceProcessed->value => OrderBilling::where('order_id', $order->id)->first()?->isProcessed() === true
                ? null
                : 'Der Status „Rechnung verarbeitet" kann erst gesetzt werden, wenn die Abrechnung als verarbeitet markiert ist.',
            default => null,
        };
    }

    /**
     * §6: "Cancellation must close all open tasks and prevent outdated
     * reminders." The tasks are derived and close on their own; what is not
     * derived is the work still waiting on someone outside LeasyBack — an
     * offer the customer could still accept (acceptance is a repair
     * authorisation) and workshop links that still accept a quotation. Both
     * are withdrawn in the same transaction as the status change.
     *
     * Decided offers (selected, rejected, closed) are history and stay as
     * they are; so do submitted quotations.
     */
    private function closeOpenWork(LeasybackOrder $order, ?int $changedByUserId, string $updatedByLabel): void
    {
        $openOffers = LeasybackOffer::where('order_id', $order->id)
            ->whereIn('offer_status', ['draft', 'published'])
            ->get();

        foreach ($openOffers as $offer) {
            $oldStatus = $offer->offer_status;

            $offer->update([
                'offer_status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $changedByUserId,
                'cancellation_reason' => 'Auftrag storniert',
            ]);

            OfferAuditLog::create([
                'auftragsnummer' => $offer->auftragsnummer,
                'offer_id' => $offer->offer_id,
                'order_id' => $offer->order_id,
                'action' => 'cancelled_with_order',
                'old_values' => ['offer_status' => $oldStatus],
                'new_values' => ['offer_status' => 'cancelled', 'by' => $updatedByLabel],
                'changed_by_user_id' => $changedByUserId,
            ]);
        }

        WorkshopQuotation::where('order_id', $order->id)
            ->whereNull('submitted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => $changedByUserId]);
    }

    private function guardChannel(string $toStatus, bool $isB2b): void
    {
        $forbidden = $isB2b ? OrderStatus::b2cOnlyValues() : OrderStatus::b2bOnlyValues();

        if (in_array($toStatus, $forbidden, true)) {
            throw ValidationException::withMessages([
                'order_status' => sprintf(
                    "Status '%s' is not available for %s orders.",
                    $toStatus,
                    $isB2b ? 'B2B' : 'B2C',
                ),
            ]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private static function transitionsFor(bool $isB2b): array
    {
        return $isB2b ? self::B2B_ALLOWED_TRANSITIONS : self::ALLOWED_TRANSITIONS;
    }

    /**
     * @return list<string>
     */
    public static function allowedNextStatuses(string $fromStatus, bool $isB2b = false): array
    {
        return self::transitionsFor($isB2b)[$fromStatus] ?? [];
    }
}
