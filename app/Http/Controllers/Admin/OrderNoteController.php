<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeasybackOrder;
use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Services\B2bOrderNoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Order notes (§16). Admin-authored: §16 gives company users the right to
 * *see* customer-visible notes, not to write them — the customer's own writing
 * surface is the existing `order_messages` thread, which is untouched.
 *
 * B2B only — the route 404s on the persisted vehicle type rather than trusting
 * the page not to offer it, matching AppraisalPositionController.
 */
class OrderNoteController extends Controller
{
    public function __construct(private readonly B2bOrderNoteService $notes) {}

    public function store(Request $request, string $orderId): RedirectResponse
    {
        [$order, $vehicle] = $this->resolveB2bOrder($orderId);

        $validated = $request->validate(B2bOrderNoteService::rules(), [
            'visibility.required' => 'Bitte wählen Sie, wer diese Notiz sehen darf.',
            'body.required' => 'Bitte geben Sie einen Notiztext ein.',
        ]);

        $note = $this->notes->create($order, $vehicle, $request->user(), $validated);

        return back()->with(
            'success',
            $note?->isInternal() === false
                ? 'Notiz wurde gespeichert und ist für den Kunden sichtbar.'
                : 'Interne Notiz wurde gespeichert.',
        );
    }

    public function destroy(Request $request, string $orderId, string $noteId): RedirectResponse
    {
        $this->resolveB2bOrder($orderId);

        abort_unless($this->notes->delete($orderId, $noteId), 404);

        return back()->with('success', 'Notiz wurde gelöscht.');
    }

    /**
     * @return array{0: LeasybackOrder, 1: Vehicle}
     */
    private function resolveB2bOrder(string $orderId): array
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->first();
        abort_unless($vehicle !== null && $vehicle->vehicle_belongs === 'B2B', 404);

        return [$order, $vehicle];
    }
}
