<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeasybackOrder;
use App\Models\Vehicle;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Services\OrderCollectionService;
use App\Modules\UserProfile\Order\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly AdminQueryService $adminQueryService,
        private readonly OrderService $orderService,
        private readonly TransitionOrderStatus $transitionOrderStatus,
        private readonly OrderCollectionService $orderCollectionService,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Orders/Index', [
            'orders' => $this->adminQueryService->orders($request),
            'filters' => [
                'search' => trim((string) $request->query('search', '')),
                'status' => (string) $request->query('status', ''),
            ],
        ]);
    }

    public function show(string $orderId): Response
    {
        $order = $this->adminQueryService->orderDetail($orderId);
        abort_unless($order !== null, 404);

        return Inertia::render('Admin/Orders/Show', [
            'order' => $order,
        ]);
    }

    /**
     * Sends an order_requested order to TÜV SÜD and, on success, moves it
     * to order_placed — the same OrderService::approveOrder() the Sanctum
     * API's OrderController::approve() calls, so both entry points share
     * one implementation of the external call + persistence.
     */
    public function approve(Request $request, string $orderId): RedirectResponse
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        if (! in_array('order_placed', TransitionOrderStatus::allowedNextStatuses($order->order_status, TransitionOrderStatus::isB2bOrder($order)), true)) {
            return back()->withErrors(['status' => 'Nur angefragte Aufträge können freigegeben werden.'])
                ->with('error', 'Nur angefragte Aufträge können freigegeben werden.');
        }

        try {
            $this->orderService->approveOrder($order, $request->user(), $request->ip());
        } catch (ValidationException $e) {
            return back()->withErrors(['status' => $e->getMessage()])->with('error', $e->getMessage());
        }

        return back()->with('success', 'Auftrag wurde freigegeben.');
    }

    /**
     * The generic manual-progression endpoint: confirmed→inspected→
     * workshop→reinspection→reworkshop/delivered, and any→cancelled.
     * order_placed (approve()'s job) and discarded (the not-yet-confirmed
     * reject action) are deliberately not accepted here — see
     * AdminQueryService::orderDetail()'s available_transitions doc comment.
     */
    public function updateStatus(Request $request, string $orderId): RedirectResponse
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        $allowed = array_values(array_diff(
            TransitionOrderStatus::allowedNextStatuses($order->order_status, TransitionOrderStatus::isB2bOrder($order)),
            ['order_placed', 'discarded'],
        ));

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', $allowed ?: ['__none__'])],
        ]);

        $user = $request->user();

        try {
            $this->transitionOrderStatus->__invoke(
                $order,
                $validated['status'],
                'admin',
                $user->name ?? $user->email,
                $user->id,
                $request->ip(),
            );
        } catch (ValidationException $e) {
            return back()->withErrors(['status' => $e->getMessage()])->with('error', $e->getMessage());
        }

        return back()->with('success', 'Status wurde aktualisiert.');
    }

    /**
     * Confirms or overrides the customer's requested collection appointment.
     * B2B only — a B2C order has no collection workflow, so the route 404s
     * on the vehicle type rather than relying on the page not offering it.
     */
    public function updateCollection(Request $request, string $orderId): RedirectResponse
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->first();
        abort_unless($vehicle !== null && $vehicle->vehicle_belongs === 'B2B', 404);

        $validated = $request->validate(OrderCollectionService::adminRules());

        $this->orderCollectionService->updateByAdmin($order, $vehicle, $request->user(), $validated);

        return back()->with('success', 'Abholtermin wurde aktualisiert.');
    }
}
