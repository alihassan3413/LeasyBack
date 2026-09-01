<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeasybackOrder;
use App\Models\Vehicle;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Services\OrderCollectionService;
use App\Modules\UserProfile\Order\Services\OrderService;
use App\Modules\UserProfile\Order\Services\WorkshopCommissionService;
use App\Modules\UserProfile\Payment\Enums\FeeReason;
use App\Modules\UserProfile\Payment\Services\B2cFeeService;
use App\Modules\UserProfile\Payment\Support\TuvAppointment;
use Illuminate\Http\Exceptions\HttpResponseException;
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
        private readonly WorkshopCommissionService $workshopCommissionService,
    ) {}

    /**
     * Mark the TÜV appointment as not attended. The one no-show entry point:
     * nothing infers it from time passing.
     */
    public function markNoShow(Request $request, string $orderId): RedirectResponse
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        if (TransitionOrderStatus::isB2bOrder($order)) {
            return back()->with('error', 'Nur B2C-Aufträge kennen eine Nichtwahrnehmung.');
        }

        $fee = app(B2cFeeService::class)->trigger($order, FeeReason::TuvNoShow, [
            'appointment_at' => TuvAppointment::for($order)?->toIso8601String(),
            'marked_at' => now()->toIso8601String(),
        ]);

        return back()->with(
            'success',
            $fee === null
                ? 'Nichtwahrnehmung wurde vermerkt.'
                : sprintf('Nichtwahrnehmung vermerkt — Gebühr %s € ausgelöst.', $fee->amountDecimal()),
        );
    }

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

        // Commissioning a workshop is not a status change with a side effect —
        // it resolves the winning workshop, records who was instructed and
        // sends them the repair order. Letting this generic endpoint set
        // `workshop_commissioned` while a real winning workshop exists would
        // produce an order that claims a workshop was instructed when none was.
        if ($validated['status'] === 'workshop_commissioned' && $this->workshopCommissionService->resolve($order) !== null) {
            $message = 'Bitte beauftragen Sie die gewählte Werkstatt über die Aktion „Gewählte Werkstatt beauftragen".';

            return back()->withErrors(['status' => $message])->with('error', $message);
        }

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

    /**
     * Confirms or reschedules the workshop repair appointment, in either
     * channel. Saving from `workshop_commissioned` also starts the repair phase
     * — see OrderCollectionService::updateRepairAppointment().
     */
    public function updateRepairAppointment(Request $request, string $orderId): RedirectResponse
    {
        [$order, $vehicle] = $this->orderWithVehicle($orderId);

        $validated = $request->validate(OrderCollectionService::repairAppointmentRules());

        $this->orderCollectionService->updateRepairAppointment($order, $vehicle, $request->user(), $validated);

        return back()->with('success', 'Reparaturtermin wurde gespeichert.');
    }

    /**
     * Instruct the workshop whose offer the customer accepted. The workshop is
     * never named by the request — it is resolved from the accepted offer — so
     * there is nothing here for a caller to point somewhere else.
     */
    public function commissionWorkshop(Request $request, string $orderId): RedirectResponse
    {
        [$order] = $this->orderWithVehicle($orderId);

        try {
            $result = $this->workshopCommissionService->commission($order, $request->user());
        } catch (HttpResponseException $e) {
            $message = $e->getResponse()->getData(true)['error'] ?? 'Die Werkstatt konnte nicht beauftragt werden.';

            return back()->withErrors(['commission' => $message])->with('error', $message);
        }

        $workshop = $result['workshop']['company_name'] ?? $result['workshop']['label'] ?? 'Die Werkstatt';

        if ($result['already_commissioned']) {
            return back()->with('success', sprintf('%s war bereits beauftragt.', $workshop));
        }

        // A commissioning that could not be delivered is still a commissioning.
        // It is reported as such rather than rolled back, and the card offers a
        // resend.
        return back()->with('success', $result['notified']
            ? sprintf('%s wurde beauftragt und benachrichtigt.', $workshop)
            : sprintf('%s wurde beauftragt, die E-Mail konnte jedoch nicht zugestellt werden.', $workshop));
    }

    public function resendWorkshopCommission(Request $request, string $orderId): RedirectResponse
    {
        [$order] = $this->orderWithVehicle($orderId);

        try {
            $sent = $this->workshopCommissionService->resendNotification($order, $request->user());
        } catch (HttpResponseException $e) {
            $message = $e->getResponse()->getData(true)['error'] ?? 'Die Benachrichtigung konnte nicht gesendet werden.';

            return back()->withErrors(['commission' => $message])->with('error', $message);
        }

        return back()->with($sent ? 'success' : 'error', $sent
            ? 'Die Werkstatt wurde erneut benachrichtigt.'
            : 'Die E-Mail konnte nicht zugestellt werden.');
    }

    /**
     * @return array{0: LeasybackOrder, 1: Vehicle}
     */
    private function orderWithVehicle(string $orderId): array
    {
        $order = LeasybackOrder::find($orderId);
        abort_unless($order !== null, 404);

        $vehicle = Vehicle::where('vehicle_id', $order->vehicle_id)->first();
        abort_unless($vehicle !== null, 404);

        return [$order, $vehicle];
    }
}
