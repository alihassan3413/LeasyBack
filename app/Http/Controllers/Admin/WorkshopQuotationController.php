<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeasybackOrder;
use App\Models\Vehicle;
use App\Models\WorkshopQuotation;
use App\Modules\UserProfile\Order\Services\RepairOfferService;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkshopQuotationController extends Controller
{
    public function __construct(
        private readonly WorkshopQuotationService $workshopQuotationService,
        private readonly RepairOfferService $repairOfferService,
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

        // The link is flashed regardless of how the send went. When the email
        // failed it is the fallback an admin sends by hand; when no address was
        // given it was always the plan. It cannot be shown again later — only
        // the hash is stored — so this response is the one chance to copy it.
        return back()
            ->with('success', match ($result['notified']) {
                true => sprintf('Anfrage an %s gesendet.', $result['quotation']->invited_email),
                false => 'Anfrage erstellt, E-Mail konnte nicht gesendet werden. Bitte Link manuell senden.',
                default => 'Werkstattlink wurde erstellt. Bitte Link manuell senden.',
            })
            ->with('workshop_link', $result['url']);
    }

    /**
     * Turn a submitted quotation into a draft customer offer (§10), in either
     * channel. It stays invisible to the customer until published through the
     * existing admin.orders.offers.publish action.
     *
     * Whether the resulting offer carries gross amounts is decided by
     * OfferPricingPolicy inside the service, not here — the controller's job is
     * to resolve the order and hand over a validated request.
     */
    public function createOffer(Request $request, string $orderId): RedirectResponse
    {
        $order = $this->order($orderId);

        $validated = $request->validate(RepairOfferService::createRules());

        try {
            $this->repairOfferService->createFromQuotation($order, $request->user(), $validated);
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
}
