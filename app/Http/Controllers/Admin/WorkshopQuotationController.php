<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeasybackOrder;
use App\Models\Vehicle;
use App\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Services\B2bOfferService;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkshopQuotationController extends Controller
{
    public function __construct(
        private readonly WorkshopQuotationService $workshopQuotationService,
        private readonly B2bOfferService $b2bOfferService,
    ) {}

    /**
     * Issue a workshop link. The plaintext token exists only in this response —
     * it is flashed once so Admin can copy it, and is never recoverable
     * afterwards because only its hash is stored.
     */
    public function store(Request $request, string $orderId): RedirectResponse
    {
        $order = $this->order($orderId);

        $validated = $request->validate(WorkshopQuotationService::inviteRules());

        $result = $this->workshopQuotationService->invite($order, $request->user(), $validated);

        return back()->with('success', 'Werkstattlink wurde erstellt.')
            ->with('workshop_link', $result['url']);
    }

    /**
     * Turn a submitted quotation into a draft customer offer (§10). It stays
     * invisible to the customer until published through the existing
     * admin.orders.offers.publish action.
     */
    public function createOffer(Request $request, string $orderId): RedirectResponse
    {
        [$order, $vehicle] = $this->b2bOrder($orderId);

        $validated = $request->validate(B2bOfferService::createRules());

        try {
            $this->b2bOfferService->createFromQuotation($order, $vehicle, $request->user(), $validated);
        } catch (HttpResponseException $e) {
            $message = $e->getResponse()->getData(true)['error'] ?? 'Angebot konnte nicht erstellt werden.';

            return back()->withErrors(['offer' => $message])->with('error', $message);
        }

        return back()->with('success', 'Kundenangebot wurde als Entwurf erstellt.');
    }

    public function destroy(Request $request, string $quotationId): RedirectResponse
    {
        $quotation = WorkshopQuotation::find($quotationId);
        abort_unless($quotation !== null, 404);

        $this->order($quotation->order_id);

        $this->workshopQuotationService->revoke($quotation, $request->user());

        return back()->with('success', 'Werkstattlink wurde widerrufen.');
    }

    /**
     * An order that may hold workshop quotations — which is any order, in
     * either channel. Asking a workshop what a repair costs is not a fact about
     * who owns the car.
     */
    private function order(string $orderId): LeasybackOrder
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        abort_unless(Vehicle::where('vehicle_id', $order->vehicle_id)->exists(), 404);

        return $order;
    }

    /**
     * Building a customer offer out of a quotation is still B2B-only — §10's
     * presentation, its net-only totals and its validity/reminder machinery are
     * not yet defined for a private customer. Deliberately a separate resolver
     * from order(): the quotation half of this controller is shared and the
     * offer half is not, and collapsing them again would quietly open the offer
     * route the next time someone touches the guard.
     *
     * @return array{0: LeasybackOrder, 1: Vehicle}
     */
    private function b2bOrder(string $orderId): array
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->first();
        abort_unless($vehicle !== null && $vehicle->vehicle_belongs === 'B2B', 404);

        return [$order, $vehicle];
    }
}
