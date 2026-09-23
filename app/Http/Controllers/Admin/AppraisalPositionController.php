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
     * Replaces the order's repair positions with the submitted set.
     *
     * Open to both channels: a repair position describes damage on a car, and
     * that is the same fact whether the car belongs to a company or a private
     * customer. What still 404s is an order that does not exist or whose
     * vehicle has gone — the ownership context every position is scoped to.
     */
    public function update(Request $request, string $orderId): RedirectResponse
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        abort_unless(Vehicle::where('vehicle_id', $order->vehicle_id)->exists(), 404);

        $validated = $request->validate(
            AppraisalPositionService::rules($this->appraisalPositionService->allowedDocumentIds($order)),
        );

        $this->appraisalPositionService->sync($order, $request->user(), $validated);

        return back()->with('success', 'Gutachtenpositionen wurden gespeichert.');
    }
}
