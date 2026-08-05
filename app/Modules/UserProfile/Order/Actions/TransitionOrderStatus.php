<?php

namespace App\Modules\UserProfile\Order\Actions;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderStatusUpdate;
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
 * the transition table this enforces and the open product questions it
 * deliberately does not resolve (e.g. whether `reworkshop` loops back to
 * `reinspection` — not implemented here until that's confirmed).
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
    ) {}

    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'order_requested' => ['order_placed', 'discarded', 'cancelled'],
        'order_placed' => ['confirmed', 'cancelled'],
        'confirmed' => ['inspected', 'cancelled'],
        'inspected' => ['workshop', 'cancelled'],
        'workshop' => ['reinspection', 'cancelled'],
        'reinspection' => ['reworkshop', 'delivered', 'cancelled'],
        'reworkshop' => ['cancelled'],
        'delivered' => [],
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

            $locked->update([...$additionalAttributes, 'order_status' => $toStatus]);

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

            return $locked->fresh();
        });

        if ($realTransition) {
            $this->notifyStatusChange($result);
        }

        return $result;
    }

    /**
     * Best-effort, never throws — a failed send must never surface as a
     * failed status transition (the DB write already committed by the
     * time this runs).
     */
    private function notifyStatusChange(LeasybackOrder $order): void
    {
        $vehicle = $order->vehicle;
        if ($vehicle === null) {
            return;
        }

        $this->notifier->send(
            $this->vehicleScope->resolveOwnerUsers($vehicle),
            NotificationPayload::make(
                NotificationType::OrderStatusChanged,
                'Status aktualisiert',
                sprintf('%s: %s', $vehicle->license_plate, OrderStatusLabel::for($order->order_status)),
                '/dashboard',
                ['auftragsnummer' => $order->auftragsnummer, 'status' => $order->order_status],
            ),
        );

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
