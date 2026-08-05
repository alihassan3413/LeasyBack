<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\B2B;
use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function __construct(private readonly AdminQueryService $adminQueryService) {}

    /**
     * Session-authenticated Admin "Kunden" list — B2C/B2B switched via a
     * `type` query param (not a modal-triggered client-side fetch like
     * leasyback_web's UsersView.vue), reusing the same AdminQueryService
     * methods the Sanctum API's AdminController::b2c()/b2b() now call.
     */
    public function index(Request $request): Response
    {
        $type = $request->query('type') === 'b2b' ? 'b2b' : 'b2c';

        return Inertia::render('Admin/Customers/Index', [
            'type' => $type,
            'customers' => $type === 'b2b'
                ? $this->adminQueryService->b2bList($request)
                : $this->adminQueryService->b2cList($request),
            'filters' => [
                'search' => (string) $request->query('search', ''),
                'is_active' => $request->query('is_active'),
            ],
        ]);
    }

    /**
     * A real routed page, not the bookmark-unfriendly modal leasyback_web
     * used for this — matches this app's own convention (Settings, Vehicle
     * dashboard) of dedicated Inertia pages over modals for substantial
     * content. Vehicles/orders reuse the existing owner-scoped queries
     * instead of duplicating them for the Admin detail view.
     */
    public function show(Request $request, string $type, string $id): Response
    {
        abort_unless(in_array($type, ['b2c', 'b2b'], true), 404);

        if ($type === 'b2c') {
            $customer = $this->adminQueryService->b2cDetail((int) $id);
            abort_unless($customer !== null, 404);

            $vehicles = $this->adminQueryService->vehicles($request, null, $customer['user_id']);
            $orders = $this->adminQueryService->orders($request, null, $customer['user_id']);
        } else {
            $customer = $this->adminQueryService->b2bDetail($id);
            abort_unless($customer !== null, 404);

            $vehicles = $this->adminQueryService->vehicles($request, null, null, $id);
            $orders = $this->adminQueryService->orders($request, null, null, $id);
        }

        return Inertia::render('Admin/Customers/Show', [
            'type' => $type,
            'customer' => $customer,
            'vehicles' => $vehicles['data'],
            'orders' => $orders['data'],
        ]);
    }

    /**
     * New functionality with no leasyback_web precedent — the legacy admin
     * panel only ever showed status as a read-only badge (see Checkpoint 9
     * decisions). Explicit Checkpoint 9 scope regardless.
     */
    public function updateStatus(Request $request, string $type, string $id): RedirectResponse
    {
        abort_unless(in_array($type, ['b2c', 'b2b'], true), 404);

        $validated = $request->validate(['is_active' => 'required|boolean']);

        $updated = $type === 'b2c'
            ? $this->adminQueryService->updateB2cStatus($id, $validated['is_active'])
            : $this->adminQueryService->updateB2bStatus($id, $validated['is_active']);

        abort_unless($updated !== null, 404);

        return back()->with('success', 'Status wurde aktualisiert.');
    }

    public function updateServiceFee(Request $request, string $id): RedirectResponse
    {
        $company = B2B::find($id);

        abort_unless($company !== null, 404);

        $validated = $request->validate([
            'service_fee_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'service_fee_effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        $company->update($validated);

        return back()->with('success', 'Servicepauschale wurde aktualisiert.');
    }
}
