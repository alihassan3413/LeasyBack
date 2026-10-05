<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\OrderAuditLog;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\AppraisalPosition;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Order\Models\WorkshopAdditionalPosition;
use App\Modules\UserProfile\Order\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Models\WorkshopQuotationItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin's decision on damage a workshop reported on its own.
 *
 * Accepting turns the workshop's finding into a real appraisal position — the
 * same kind of row the Gutachten produced — so it can be priced, offered and
 * invoiced like every other position. The workshop's photos travel with it:
 * they are already report documents of this order, so the new position simply
 * references them.
 *
 * Accepting is held to exactly the rules of editing positions by hand
 * (AppraisalPositionService::assertEditable): only in the appraisal/offer
 * phase, and never after the customer accepted an offer. Both decisions are
 * one-time: a second click on an already reviewed row is refused, so a double
 * submit can never create the position twice.
 */
class WorkshopAdditionalPositionReviewService
{
    public function __construct(private readonly AppraisalPositionService $appraisalPositions) {}

    /**
     * Create an appraisal position from the workshop's additional damage.
     */
    public function accept(WorkshopAdditionalPosition $position, User $user): AppraisalPosition
    {
        return DB::transaction(function () use ($position, $user) {
            $locked = WorkshopAdditionalPosition::whereKey($position->id)->lockForUpdate()->firstOrFail();

            $this->assertPending($locked);

            [$quotation, $order] = $this->context($locked);

            if (! $quotation->isSubmitted()) {
                throw ValidationException::withMessages([
                    'additional_position' => 'Das Werkstattangebot wurde noch nicht abgegeben.',
                ]);
            }

            // Same gate as editing the positions card by hand.
            $this->appraisalPositions->assertEditable($order);

            $sortOrder = (int) AppraisalPosition::where('order_id', $order->id)->lockForUpdate()->max('sort_order');

            $created = AppraisalPosition::create([
                'order_id' => $order->id,
                'auftragsnummer' => $order->auftragsnummer,
                'sort_order' => $sortOrder + 1,
                'component' => $locked->component,
                'damage_description' => $locked->damage_description,
                // The workshop's price is the only amount this damage has.
                // The admin can correct it on the positions card afterwards.
                'original_amount_net' => (string) $locked->amount_net,
                'chargeable_amount_net' => null,
                'repair_method' => $locked->repair_method,
                'damage_image_document_ids' => $this->imagesOfThisOrder($order, $locked->damage_image_document_ids ?? []),
                'source' => AppraisalPosition::SOURCE_MANUAL,
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ]);

            // The price is the workshop's, so it belongs in the workshop column
            // of the comparison — and in the repair total the customer offer is
            // built from. Without this item the position showed its amount only
            // on the appraisal side, with a dash opposite it, which both read as
            // "the workshop did not quote this" and inflated the saving by its
            // own amount.
            WorkshopQuotationItem::updateOrCreate(
                ['quotation_id' => $quotation->id, 'appraisal_position_id' => $created->id],
                [
                    'amount_net' => (string) $locked->amount_net,
                    'repair_method' => $locked->repair_method,
                    'not_repairable' => false,
                ],
            );

            $locked->update([
                'review_status' => WorkshopAdditionalPosition::STATUS_ACCEPTED,
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $user->id,
                'appraisal_position_id' => $created->id,
            ]);

            $this->audit($order, $locked, $user, 'WORKSHOP_ADDITIONAL_POSITION_ACCEPTED', [
                'appraisal_position_id' => $created->id,
            ]);

            return $created;
        });
    }

    /**
     * Decline the workshop's additional damage. Nothing else changes: the
     * quotation stays as the workshop submitted it.
     */
    public function reject(WorkshopAdditionalPosition $position, User $user): void
    {
        DB::transaction(function () use ($position, $user) {
            $locked = WorkshopAdditionalPosition::whereKey($position->id)->lockForUpdate()->firstOrFail();

            $this->assertPending($locked);

            [, $order] = $this->context($locked);

            $locked->update([
                'review_status' => WorkshopAdditionalPosition::STATUS_REJECTED,
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $user->id,
            ]);

            $this->audit($order, $locked, $user, 'WORKSHOP_ADDITIONAL_POSITION_REJECTED');
        });
    }

    private function assertPending(WorkshopAdditionalPosition $position): void
    {
        if (! $position->isPendingReview()) {
            throw ValidationException::withMessages([
                'additional_position' => 'Dieser zusätzliche Schaden wurde bereits geprüft.',
            ]);
        }
    }

    /**
     * @return array{0: WorkshopQuotation, 1: LeasybackOrder}
     */
    private function context(WorkshopAdditionalPosition $position): array
    {
        $quotation = WorkshopQuotation::find($position->quotation_id);
        $order = $quotation === null ? null : LeasybackOrder::find($quotation->order_id);

        if ($quotation === null || $order === null) {
            throw ValidationException::withMessages([
                'additional_position' => 'Der zugehörige Auftrag wurde nicht gefunden.',
            ]);
        }

        return [$quotation, $order];
    }

    /**
     * Only images that really are report documents of this order — the same
     * list the positions card validates against — so an accepted position can
     * be saved again on that card without a validation error.
     *
     * @param  array<int, string>  $documentIds
     * @return array<int, string>|null
     */
    private function imagesOfThisOrder(LeasybackOrder $order, array $documentIds): ?array
    {
        $allowed = $this->appraisalPositions->allowedDocumentIds($order);

        $ids = array_values(array_unique(array_filter(
            $documentIds,
            fn (mixed $id) => is_string($id) && in_array($id, $allowed, true),
        )));

        return $ids === [] ? null : $ids;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(LeasybackOrder $order, WorkshopAdditionalPosition $position, User $user, string $action, array $extra = []): void
    {
        OrderAuditLog::create([
            'order_id' => $order->id,
            'vehicle_id' => $order->vehicle_id,
            'action' => $action,
            'old_values' => ['review_status' => WorkshopAdditionalPosition::STATUS_PENDING],
            'new_values' => [
                'additional_position_id' => $position->id,
                'quotation_id' => $position->quotation_id,
                'component' => $position->component,
                'amount_net' => (string) $position->amount_net,
                'review_status' => $position->review_status,
                ...$extra,
            ],
            'changed_by_user_id' => $user->id,
        ]);
    }
}
