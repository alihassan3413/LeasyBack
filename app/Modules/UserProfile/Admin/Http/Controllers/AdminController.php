<?php

namespace App\Modules\UserProfile\Admin\Http\Controllers;

use App\Modules\UserProfile\Admin\Services\AdminQueryService;
use App\Modules\UserProfile\Admin\Support\EnsuresAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    use EnsuresAdmin;

    public function __construct(private readonly AdminQueryService $adminQueryService) {}

    /**
     * GET /admin/dashboard/summary
     */
    public function summary(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'viewDashboardSummary', 'Only admin can access dashboard summary')) {
            return $denied;
        }

        return response()->json($this->adminQueryService->summary());
    }

    /**
     * GET /admin/users/b2c
     */
    public function b2c(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'viewAdminListings', 'Only admin can access dashboard summary')) {
            return $denied;
        }

        return response()->json($this->adminQueryService->b2cList($request));
    }

    /**
     * GET /admin/users/b2b
     */
    public function b2b(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'viewAdminListings', 'Only admin can access dashboard summary')) {
            return $denied;
        }

        return response()->json($this->adminQueryService->b2bList($request));
    }

    /**
     * PATCH /admin/b2c/{userId}/status
     */
    public function updateB2cStatus(Request $request, string $userId): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'updateCustomerStatus', 'Only admin can access dashboard summary')) {
            return $denied;
        }

        $validated = $request->validate(['is_active' => 'required|boolean']);

        $customer = $this->adminQueryService->updateB2cStatus($userId, $validated['is_active']);
        if (! $customer) {
            return response()->json(['error' => 'B2C customer not found', 'user_id' => $userId], 404);
        }

        return response()->json($customer);
    }

    /**
     * PATCH /admin/b2b/{b2bId}/status
     */
    public function updateB2bStatus(Request $request, string $b2bId): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'updateCustomerStatus', 'Only admin can access dashboard summary')) {
            return $denied;
        }

        $validated = $request->validate(['is_active' => 'required|boolean']);

        $company = $this->adminQueryService->updateB2bStatus($b2bId, $validated['is_active']);
        if (! $company) {
            return response()->json(['error' => 'B2B customer not found', 'b2b_id' => $b2bId], 404);
        }

        return response()->json($company);
    }

    /**
     * GET /admin/list/orders
     *
     * Delegates to AdminQueryService, which builds this listing through
     * Eloquent's query builder with a validated order_status allow-list —
     * this used to interpolate `order_status` straight into raw SQL
     * (`... WHERE 1=1 AND o.order_status = '{$status}'`), a live SQL
     * injection. The service is the fix, not a rewrite: it already existed,
     * already parameterized, and was simply never wired in.
     */
    public function orders(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'viewAdminListings', 'Only admin can access dashboard summary')) {
            return $denied;
        }

        return response()->json($this->adminQueryService->orders($request));
    }

    /**
     * GET /admin/list/orders/by-user-type
     */
    public function ordersByUserType(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'viewAdminListings', 'Only admin can access dashboard summary')) {
            return $denied;
        }

        // Simplified — same structure as orders() with user_type filter
        return $this->orders($request);
    }

    /**
     * GET /admin/list/orders/user/{userId}
     */
    public function ordersByUser(Request $request, string $userId): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'viewAdminListings', 'Only admin can access dashboard summary')) {
            return $denied;
        }

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $offset = ($page - 1) * $limit;

        $orders = DB::select('
            SELECT o.id, o.vehicle_id, o.auftragsnummer, o.leasyback_partner, o.order_status,
                o.sent_at, o.created_at, o.response_status,
                v.license_plate, v.vin, v.make, v.model
            FROM leasyback_orders o
            INNER JOIN vehicles v ON v.vehicle_id = o.vehicle_id
            LEFT JOIN user_b2b ub ON ub.b2b_id = v.b2b_id
            WHERE v.b2c_user_id = ? OR ub.user_id = ?
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ', [$userId, $userId, $limit, $offset]);

        return response()->json([
            'page' => $page,
            'limit' => $limit,
            'total' => count($orders),
            'data' => $orders,
        ]);
    }

    /**
     * GET /admin/list/vehicles
     */
    public function vehicles(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'viewAdminListings', 'Only admin can access dashboard summary')) {
            return $denied;
        }

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $offset = ($page - 1) * $limit;

        $vehicles = DB::select('
            SELECT v.vehicle_id, v.license_plate, v.first_registration_date, v.leasing_end_date,
                v.leasinggeber, v.vin, v.make, v.model, v.vehicle_belongs,
                v.b2b_id, v.b2c_user_id, v.created_at, v.updated_at
            FROM vehicles v
            ORDER BY v.created_at DESC
            LIMIT ? OFFSET ?
        ', [$limit, $offset]);

        $total = DB::selectOne('SELECT COUNT(*) AS total FROM vehicles')->total;

        return response()->json([
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $total,
            'data' => $vehicles,
        ]);
    }

    /**
     * GET /admin/list/vehicles/by-user-type
     */
    public function vehiclesByUserType(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'viewAdminListings', 'Only admin can access dashboard summary')) {
            return $denied;
        }

        return $this->vehicles($request);
    }

    /**
     * GET /admin/list/vehicles/user/{userId}
     */
    public function vehiclesByUser(Request $request, string $userId): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request, 'viewAdminListings', 'Only admin can access dashboard summary')) {
            return $denied;
        }

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $offset = ($page - 1) * $limit;

        $vehicles = DB::select('
            SELECT v.vehicle_id, v.license_plate, v.first_registration_date, v.leasing_end_date,
                v.leasinggeber, v.vin, v.make, v.model, v.vehicle_belongs,
                v.created_at, v.updated_at
            FROM vehicles v
            LEFT JOIN user_b2b ub ON ub.b2b_id = v.b2b_id
            WHERE v.b2c_user_id = ? OR ub.user_id = ?
            ORDER BY v.created_at DESC
            LIMIT ? OFFSET ?
        ', [$userId, $userId, $limit, $offset]);

        return response()->json([
            'page' => $page,
            'limit' => $limit,
            'total' => count($vehicles),
            'data' => $vehicles,
        ]);
    }
}
