<?php

namespace App\Modules\UserProfile\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    private function ensureAdmin(Request $request): ?JsonResponse
    {
        if ($request->user()->user_type->value !== 'Admin') {
            return response()->json(['error' => 'Only admin can access dashboard summary'], 403);
        }
        return null;
    }

    /**
     * GET /admin/dashboard/summary
     */
    public function summary(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) return $denied;

        $summary = DB::selectOne("
            SELECT
                (SELECT COUNT(*) FROM users WHERE user_type = 'Privatkunde') AS total_b2c_customers,
                (SELECT COUNT(*) FROM users WHERE user_type = 'Firmenkunde') AS total_b2b_users,
                (SELECT COUNT(*) FROM b2b) AS total_b2b_companies,
                (SELECT COUNT(*) FROM vehicles) AS total_vehicles,
                (SELECT COUNT(*) FROM leasyback_orders) AS total_orders,
                (SELECT COUNT(*) FROM leasyback_orders WHERE order_status IN ('order_placed','confirmed','inspected','workshop','reinspection','reworkshop','order_requested')) AS active_orders,
                (SELECT COUNT(*) FROM leasyback_orders WHERE order_status = 'delivered') AS delivered_orders,
                (SELECT COUNT(*) FROM leasyback_orders WHERE order_status IN ('order_placed','confirmed')) AS pending_inspections
        ");

        return response()->json($summary);
    }

    /**
     * GET /admin/users/b2c
     */
    public function b2c(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) return $denied;

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $offset = ($page - 1) * $limit;
        $isActive = $request->query('is_active');

        $counts = DB::selectOne("
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE u.is_active = true) AS total_active,
                COUNT(*) FILTER (WHERE u.is_active = false) AS total_inactive
            FROM users u WHERE u.user_type = 'Privatkunde'
        ");

        $activeFilter = $isActive !== null ? "AND u.is_active = " . ($isActive === 'true' ? 'true' : 'false') : '';

        $users = DB::select("
            SELECT u.id as user_id, u.email as user_email, u.user_type, u.is_active,
                up.profile_id, up.image_url,
                c.contact_id, c.salutation, c.first_name, c.last_name,
                a.address_id, a.street, a.number, a.additional_address,
                a.zip_code, a.city, a.country, u.created_at
            FROM users u
            LEFT JOIN user_profiles up ON up.user_id = u.id
            LEFT JOIN contacts c ON c.contact_id = up.contact_id
            LEFT JOIN addresses a ON a.address_id = c.address_id
            WHERE u.user_type = 'Privatkunde' {$activeFilter}
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ", [$limit, $offset]);

        return response()->json([
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $counts->total,
            'total_active' => (int) $counts->total_active,
            'total_inactive' => (int) $counts->total_inactive,
            'data' => $users,
        ]);
    }

    /**
     * GET /admin/users/b2b
     */
    public function b2b(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) return $denied;

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $offset = ($page - 1) * $limit;
        $isActive = $request->query('is_active');

        $counts = DB::selectOne("
            SELECT
                COUNT(DISTINCT b.b2b_id) AS total,
                COUNT(DISTINCT b.b2b_id) FILTER (WHERE b.is_active = true) AS total_active,
                COUNT(DISTINCT b.b2b_id) FILTER (WHERE b.is_active = false) AS total_inactive
            FROM users u
            INNER JOIN user_b2b ub ON ub.user_id = u.id
            INNER JOIN b2b b ON b.b2b_id = ub.b2b_id
            WHERE u.user_type = 'Firmenkunde'
        ");

        $activeFilter = $isActive !== null ? "AND b.is_active = " . ($isActive === 'true' ? 'true' : 'false') : '';

        $users = DB::select("
            SELECT u.id as user_id, u.email as user_email, u.user_type,
                b.b2b_id, b.company_name, b.vat_id, b.logo_url, b.contact_email, b.is_active,
                ub.role,
                c.contact_id, c.salutation, c.first_name, c.last_name,
                a.address_id, a.street, a.number, a.additional_address, a.zip_code, a.city, a.country,
                b.created_at
            FROM users u
            INNER JOIN user_b2b ub ON ub.user_id = u.id
            INNER JOIN b2b b ON b.b2b_id = ub.b2b_id
            INNER JOIN contacts c ON c.contact_id = b.contact_id
            INNER JOIN addresses a ON a.address_id = b.address_id
            WHERE u.user_type = 'Firmenkunde' {$activeFilter}
            ORDER BY b.created_at DESC
            LIMIT ? OFFSET ?
        ", [$limit, $offset]);

        return response()->json([
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $counts->total,
            'total_active' => (int) $counts->total_active,
            'total_inactive' => (int) $counts->total_inactive,
            'data' => $users,
        ]);
    }

    /**
     * PATCH /admin/b2c/{userId}/status
     */
    public function updateB2cStatus(Request $request, string $userId): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) return $denied;

        $validated = $request->validate(['is_active' => 'required|boolean']);

        $affected = DB::update(
            "UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ? AND user_type = 'Privatkunde'",
            [$validated['is_active'], $userId]
        );

        if (!$affected) {
            return response()->json(['error' => 'B2C customer not found', 'user_id' => $userId], 404);
        }

        $customer = DB::selectOne("SELECT id as user_id, email as user_email, user_type, is_active, created_at, updated_at FROM users WHERE id = ?", [$userId]);

        return response()->json($customer);
    }

    /**
     * PATCH /admin/b2b/{b2bId}/status
     */
    public function updateB2bStatus(Request $request, string $b2bId): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) return $denied;

        $validated = $request->validate(['is_active' => 'required|boolean']);

        $affected = DB::update(
            "UPDATE b2b SET is_active = ?, updated_at = NOW() WHERE b2b_id = ?",
            [$validated['is_active'], $b2bId]
        );

        if (!$affected) {
            return response()->json(['error' => 'B2B customer not found', 'b2b_id' => $b2bId], 404);
        }

        $company = DB::selectOne("SELECT b2b_id, company_name, contact_email, is_active, created_at, updated_at FROM b2b WHERE b2b_id = ?", [$b2bId]);

        return response()->json($company);
    }

    /**
     * GET /admin/list/orders
     */
    public function orders(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) return $denied;

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $offset = ($page - 1) * $limit;
        $status = $request->query('order_status');

        $statusFilter = $status ? "AND o.order_status = '{$status}'" : '';

        $counts = DB::selectOne("
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE o.order_status IN ('order_placed','confirmed','inspected','workshop','reinspection','reworkshop','order_requested')) AS total_active,
                COUNT(*) FILTER (WHERE o.order_status = 'confirmed') AS total_confirmed,
                COUNT(*) FILTER (WHERE o.order_status = 'inspected') AS total_inspected,
                COUNT(*) FILTER (WHERE o.order_status = 'delivered') AS total_delivered
            FROM leasyback_orders o
            WHERE 1=1 {$statusFilter}
        ");

        $orders = DB::select("
            SELECT o.id, o.vehicle_id, o.auftragsnummer, o.leasyback_partner, o.order_status,
                o.sent_at, o.created_at, o.response_status,
                v.license_plate, v.vin, v.make, v.model
            FROM leasyback_orders o
            INNER JOIN vehicles v ON v.vehicle_id = o.vehicle_id
            WHERE 1=1 {$statusFilter}
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ", [$limit, $offset]);

        return response()->json([
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $counts->total,
            'total_active' => (int) $counts->total_active,
            'total_confirmed' => (int) $counts->total_confirmed,
            'total_inspected' => (int) $counts->total_inspected,
            'total_delivered' => (int) $counts->total_delivered,
            'data' => $orders,
        ]);
    }

    /**
     * GET /admin/list/orders/by-user-type
     */
    public function ordersByUserType(Request $request): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) return $denied;
        // Simplified — same structure as orders() with user_type filter
        return $this->orders($request);
    }

    /**
     * GET /admin/list/orders/user/{userId}
     */
    public function ordersByUser(Request $request, string $userId): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) return $denied;

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $offset = ($page - 1) * $limit;

        $orders = DB::select("
            SELECT o.id, o.vehicle_id, o.auftragsnummer, o.leasyback_partner, o.order_status,
                o.sent_at, o.created_at, o.response_status,
                v.license_plate, v.vin, v.make, v.model
            FROM leasyback_orders o
            INNER JOIN vehicles v ON v.vehicle_id = o.vehicle_id
            LEFT JOIN user_b2b ub ON ub.b2b_id = v.b2b_id
            WHERE v.b2c_user_id = ? OR ub.user_id = ?
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ", [$userId, $userId, $limit, $offset]);

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
        if ($denied = $this->ensureAdmin($request)) return $denied;

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $offset = ($page - 1) * $limit;

        $vehicles = DB::select("
            SELECT v.vehicle_id, v.license_plate, v.first_registration_date, v.leasing_end_date,
                v.leasinggeber, v.vin, v.make, v.model, v.vehicle_belongs,
                v.b2b_id, v.b2c_user_id, v.created_at, v.updated_at
            FROM vehicles v
            ORDER BY v.created_at DESC
            LIMIT ? OFFSET ?
        ", [$limit, $offset]);

        $total = DB::selectOne("SELECT COUNT(*) AS total FROM vehicles")->total;

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
        if ($denied = $this->ensureAdmin($request)) return $denied;
        return $this->vehicles($request);
    }

    /**
     * GET /admin/list/vehicles/user/{userId}
     */
    public function vehiclesByUser(Request $request, string $userId): JsonResponse
    {
        if ($denied = $this->ensureAdmin($request)) return $denied;

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $offset = ($page - 1) * $limit;

        $vehicles = DB::select("
            SELECT v.vehicle_id, v.license_plate, v.first_registration_date, v.leasing_end_date,
                v.leasinggeber, v.vin, v.make, v.model, v.vehicle_belongs,
                v.created_at, v.updated_at
            FROM vehicles v
            LEFT JOIN user_b2b ub ON ub.b2b_id = v.b2b_id
            WHERE v.b2c_user_id = ? OR ub.user_id = ?
            ORDER BY v.created_at DESC
            LIMIT ? OFFSET ?
        ", [$userId, $userId, $limit, $offset]);

        return response()->json([
            'page' => $page,
            'limit' => $limit,
            'total' => count($vehicles),
            'data' => $vehicles,
        ]);
    }
}
