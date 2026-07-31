<?php

namespace App\Modules\UserProfile\Order\Actions;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\OrderStatusUpdate;
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
 */
class TransitionOrderStatus
{
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
        return DB::transaction(function () use ($order, $toStatus, $authSource, $updatedByLabel, $changedByUserId, $callerIp, $bewertungId, $additionalAttributes) {
            /** @var LeasybackOrder $locked */
            $locked = LeasybackOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $fromStatus = $locked->order_status;

            if ($fromStatus === $toStatus) {
                if ($additionalAttributes !== []) {
                    $locked->update($additionalAttributes);
                }

                return $locked->fresh();
            }

            $allowed = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];
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

            return $locked->fresh();
        });
    }

    /**
     * @return list<string>
     */
    public static function allowedNextStatuses(string $fromStatus): array
    {
        return self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];
    }
}
