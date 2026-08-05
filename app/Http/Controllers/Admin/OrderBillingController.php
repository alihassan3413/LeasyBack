<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeasybackOrder;
use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Services\B2bBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderBillingController extends Controller
{
    public function __construct(private readonly B2bBillingService $b2bBillingService) {}

    /**
     * Records the internal billing state of a B2B order. B2B only — the same
     * 404-on-persisted-vehicle-type rule the other B2B order endpoints use.
     */
    public function update(Request $request, string $orderId): RedirectResponse
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->first();
        abort_unless($vehicle !== null && $vehicle->vehicle_belongs === 'B2B', 404);

        $validated = $request->validate(
            B2bBillingService::rules($this->b2bBillingService->allowedDocumentIds($order)),
        );

        $this->b2bBillingService->update($order, $vehicle, $request->user(), $validated);

        return back()->with('success', 'Abrechnung wurde gespeichert.');
    }
}
