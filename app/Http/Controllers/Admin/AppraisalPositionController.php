<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeasybackOrder;
use App\Models\Vehicle;
use App\Modules\UserProfile\Order\Services\AppraisalPositionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AppraisalPositionController extends Controller
{
    public function __construct(private readonly AppraisalPositionService $appraisalPositionService) {}

    /**
     * Replaces the order's appraisal positions with the submitted set. B2B
     * only — a B2C order has no position workflow, so the route 404s on the
     * persisted vehicle type rather than relying on the page not offering it.
     */
    public function update(Request $request, string $orderId): RedirectResponse
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->first();
        abort_unless($vehicle !== null && $vehicle->vehicle_belongs === 'B2B', 404);

        $validated = $request->validate(
            AppraisalPositionService::rules($this->appraisalPositionService->allowedDocumentIds($order)),
        );

        $this->appraisalPositionService->sync($order, $vehicle, $request->user(), $validated);

        return back()->with('success', 'Gutachtenpositionen wurden gespeichert.');
    }
}
