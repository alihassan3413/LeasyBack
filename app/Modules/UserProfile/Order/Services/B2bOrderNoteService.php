<?php

namespace App\Modules\UserProfile\Order\Services;

use App\Models\B2bOrderNote;
use App\Models\User;
use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Vehicle\Models\Vehicle;
use Illuminate\Validation\Rule;

/**
 * The only reader and writer of `b2b_order_notes` (§16).
 *
 * The isolation rule — *"internal notes must never appear in customer APIs,
 * emails or exports"* — is enforced by giving the two audiences **separate
 * methods** rather than one method with an `$includeInternal` flag. A boolean
 * defaults, gets forwarded, and eventually gets passed wrong; there is no
 * argument to `forCustomerOrders()` that could make it return an internal
 * note, because the scope is applied unconditionally inside it.
 *
 * Nothing else in the app reads this table, so emails and the Excel export are
 * covered structurally rather than by a filter someone could forget.
 */
class B2bOrderNoteService
{
    /**
     * Admin's view: both audiences, newest first, each row carrying its
     * visibility so the UI can label it.
     *
     * @return list<array<string, mixed>>
     */
    public function forOrder(string $orderId): array
    {
        return B2bOrderNote::where('order_id', $orderId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (B2bOrderNote $note) => $this->present($note, true))
            ->all();
    }

    /**
     * The customer's view, keyed by `auftragsnummer`.
     *
     * `customerVisible()` is applied here and cannot be switched off by a
     * caller. The presented row also omits `visibility` itself — a customer
     * has no use for a field whose only other value they may never see.
     *
     * @param  list<string>  $auftragsnummern
     * @return array<string, list<array<string, mixed>>>
     */
    public function forCustomerOrders(array $auftragsnummern): array
    {
        if ($auftragsnummern === []) {
            return [];
        }

        return B2bOrderNote::query()
            ->customerVisible()
            ->whereIn('auftragsnummer', $auftragsnummern)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('auftragsnummer')
            ->map(fn ($notes) => $notes->map(fn (B2bOrderNote $note) => $this->present($note, false))->values()->all())
            ->all();
    }

    /**
     * §16's "must be clearly marked as customer-visible before saving" is
     * enforced here, not only in the UI: `visibility` is **required**, so a
     * caller that omits it is rejected rather than inheriting a default. The
     * column's own default exists purely as a database-level backstop.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'visibility' => ['required', Rule::in(B2bOrderNote::visibilities())],
        ];
    }

    /**
     * Returns null for a non-B2B order, so even a direct service call cannot
     * attach a note to a B2C order — the controller's 404 is the first gate,
     * this is the one that holds if someone adds another caller.
     *
     * Type-hints the canonical models rather than the `App\Models` shims, so
     * both call shapes work: a shim instance IS-A canonical one, but not the
     * reverse, and relation-loaded instances are always canonical.
     *
     * @param  array<string, mixed>  $validated
     */
    public function create(LeasybackOrder $order, Vehicle $vehicle, User $user, array $validated): ?B2bOrderNote
    {
        if ($vehicle->vehicle_belongs !== 'B2B') {
            return null;
        }

        return B2bOrderNote::create([
            'order_id' => $order->id,
            'auftragsnummer' => $order->auftragsnummer,
            'visibility' => $validated['visibility'],
            'body' => trim($validated['body']),
            'author_user_id' => $user->id,
            // Snapshot, so the note stays attributable after the account goes.
            'author_name' => $user->name ?: $user->email,
        ]);
    }

    public function delete(string $orderId, string $noteId): bool
    {
        return B2bOrderNote::where('order_id', $orderId)
            ->where('id', $noteId)
            ->delete() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(B2bOrderNote $note, bool $includeVisibility): array
    {
        return [
            'id' => $note->id,
            ...($includeVisibility ? ['visibility' => $note->visibility] : []),
            'body' => $note->body,
            'author_name' => $note->author_name,
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }
}
