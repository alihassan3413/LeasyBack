<?php

namespace App\Modules\UserProfile\Vehicle\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleAuditLog;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class VehicleController extends Controller
{
    public function __construct(private VehicleScopeService $scope) {}

    /**
     * POST /vehicle/create
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'license_plate' => 'required|string|unique:vehicles,license_plate',
            'first_registration_date' => 'nullable|date',
            'leasing_end_date' => 'nullable|date',
            'leasinggeber' => 'nullable|string',
            'vin' => 'nullable|string|size:17',
            'make' => 'nullable|string',
            'model' => 'nullable|string',
            'vehicle_belongs' => 'nullable|string|in:B2B,B2C',
            'b2b_id' => 'nullable|uuid',
            'b2c_user_id' => 'nullable|integer',
        ]);

        // Determine belongs/owner based on user type
        $belongs = null;
        $b2bId = null;
        $b2cUserId = null;

        switch ($user->user_type->value) {
            case 'Admin':
                $belongs = $validated['vehicle_belongs'] ?? abort(422, 'vehicle_belongs is required for Admin');
                if ($belongs === 'B2B') {
                    $b2bId = $validated['b2b_id'] ?? abort(422, 'b2b_id is required for B2B vehicle');
                } else {
                    $b2cUserId = $validated['b2c_user_id'] ?? abort(422, 'b2c_user_id is required for B2C vehicle');
                }
                break;
            case 'Firmenkunde':
                $b2bId = $this->scope->getB2bIdForUser($user->id);
                if (!$b2bId) {
                    return response()->json(['error' => 'Not Found: B2B profile not found'], 404);
                }
                $belongs = 'B2B';
                break;
            case 'Privatkunde':
                $b2cUserId = $user->id;
                $belongs = 'B2C';
                break;
            default:
                return response()->json(['error' => 'Not proper user type'], 400);
        }

        $vehicle = DB::transaction(function () use ($validated, $belongs, $b2bId, $b2cUserId, $user) {
            $vehicle = Vehicle::create([
                'license_plate' => $validated['license_plate'],
                'first_registration_date' => $validated['first_registration_date'] ?? null,
                'leasing_end_date' => $validated['leasing_end_date'] ?? null,
                'leasinggeber' => $validated['leasinggeber'] ?? null,
                'vin' => $validated['vin'] ?? null,
                'make' => $validated['make'] ?? null,
                'model' => $validated['model'] ?? null,
                'b2b_id' => $b2bId,
                'b2c_user_id' => $b2cUserId,
                'vehicle_belongs' => $belongs,
            ]);

            VehicleAuditLog::create([
                'vehicle_id' => $vehicle->vehicle_id,
                'action' => 'INSERT',
                'new_values' => $validated,
                'changed_by_user_id' => $user->id,
            ]);

            return $vehicle;
        });

        return response()->json($vehicle, 201);
    }

    /**
     * PATCH /vehicle/{vehicleId}
     */
    public function update(Request $request, string $vehicleId): JsonResponse
    {
        $user = $request->user();
        $vehicle = $this->scope->findVehicleWithAccess($vehicleId, $user);

        if (!$vehicle) {
            return response()->json('Vehicle not found', 404);
        }

        $validated = $request->validate([
            'first_registration_date' => 'nullable|date',
            'leasing_end_date' => 'nullable|date',
            'leasinggeber' => 'nullable|string',
            'vin' => 'nullable|string|size:17',
            'make' => 'nullable|string',
            'model' => 'nullable|string',
        ]);

        DB::transaction(function () use ($vehicle, $validated, $user) {
            $old = $vehicle->toArray();
            $vehicle->update(array_filter($validated, fn($v) => $v !== null));

            VehicleAuditLog::create([
                'vehicle_id' => $vehicle->vehicle_id,
                'action' => 'UPDATE',
                'old_values' => $old,
                'new_values' => $validated,
                'changed_by_user_id' => $user->id,
            ]);
        });

        return response()->json($vehicle->fresh());
    }

    /**
     * PUT /vehicle/{vehicleId} — assign profile
     */
    public function assignProfile(Request $request, string $vehicleId): JsonResponse
    {
        $user = $request->user();
        $vehicle = $this->scope->findVehicleWithAccess($vehicleId, $user);

        if (!$vehicle) {
            return response()->json('Vehicle not found', 404);
        }

        $validated = $request->validate([
            'profile_id' => 'required|integer',
        ]);

        DB::transaction(function () use ($vehicle, $validated, $user) {
            $old = $vehicle->toArray();
            $vehicle->update(['assigned_profile_id' => $validated['profile_id']]);

            VehicleAuditLog::create([
                'vehicle_id' => $vehicle->vehicle_id,
                'action' => 'ASSIGN_PROFILE',
                'old_values' => $old,
                'new_values' => ['assigned_profile_id' => $validated['profile_id']],
                'changed_by_user_id' => $user->id,
            ]);
        });

        return response()->json($vehicle->fresh());
    }

    /**
     * GET /vehicle/find/{vehicleId}/{ownerId}
     */
    public function findByOwner(Request $request, string $vehicleId, string $ownerId): JsonResponse
    {
        $vehicle = Vehicle::where('vehicle_id', $vehicleId)
            ->where(function ($q) use ($ownerId) {
                $q->where(function ($q2) use ($ownerId) {
                    $q2->where('vehicle_belongs', 'B2B')->where('b2b_id', $ownerId);
                })->orWhere(function ($q2) use ($ownerId) {
                    $q2->where('vehicle_belongs', 'B2C')->where('b2c_user_id', $ownerId);
                });
            })
            ->first();

        if (!$vehicle) {
            return response()->json([
                'error' => 'Vehicle not found or you do not have access',
                'vehicle_id' => $vehicleId,
                'owner_id' => $ownerId,
            ], 404);
        }

        return response()->json($vehicle);
    }

    /**
     * GET /vehicle/list/{ownerId}
     */
    public function listByOwner(Request $request, string $ownerId): JsonResponse
    {
        $user = $request->user();

        // Validate ownership
        if ($user->user_type->value === 'Firmenkunde') {
            $b2bId = $this->scope->getB2bIdForUser($user->id);
            if ($ownerId !== $b2bId) {
                return response()->json(['error' => 'Not Found: You can only view vehicles for your own company'], 404);
            }
        } elseif ($user->user_type->value === 'Privatkunde') {
            if ((string) $ownerId !== (string) $user->id) {
                return response()->json(['error' => 'Not Found: You can only view vehicles of your own'], 404);
            }
        }

        $vehicles = Vehicle::where(function ($q) use ($ownerId) {
            $q->where(function ($q2) use ($ownerId) {
                $q2->where('vehicle_belongs', 'B2B')->where('b2b_id', $ownerId);
            })->orWhere(function ($q2) use ($ownerId) {
                $q2->where('vehicle_belongs', 'B2C')->where('b2c_user_id', $ownerId);
            });
        })
        ->orderByDesc('created_at')
        ->get();

        return response()->json($vehicles);
    }

    /**
     * GET /vehicle/list/report/status
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $userType = $user->user_type->value;

        // Determine belongs/owner_id
        $belongs = null;
        $ownerId = null;

        switch ($userType) {
            case 'Admin':
                $belongs = 'ALL';
                break;
            case 'Firmenkunde':
                $b2bId = $this->scope->getB2bIdForUser($user->id);
                if (!$b2bId) {
                    return response()->json(['error' => 'Not Found: No B2B company is linked to this authenticated user'], 404);
                }
                $belongs = 'B2B';
                $ownerId = $b2bId;
                break;
            case 'Privatkunde':
                $belongs = 'B2C';
                $ownerId = $user->id;
                break;
            default:
                return response()->json(['error' => 'Only Admin, Firmenkunde or Privatkunde can access this endpoint'], 400);
        }

        // Build query matching Rust CTE logic
        $query = Vehicle::query()
            ->when($belongs === 'B2B', fn($q) => $q->where('vehicle_belongs', 'B2B')->where('b2b_id', $ownerId))
            ->when($belongs === 'B2C', fn($q) => $q->where('vehicle_belongs', 'B2C')->where('b2c_user_id', $ownerId))
            ->orderByDesc('created_at');

        $vehicles = $query->get()->map(function ($vehicle) {
            $orders = DB::table('leasyback_orders')
                ->where('vehicle_id', $vehicle->vehicle_id)
                ->where('order_status', '!=', 'cancelled')
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($order) {
                    $statusUpdates = DB::table('leasyback_order_status_updates')
                        ->where('auftragsnummer', $order->auftragsnummer)
                        ->orderByDesc('created_at')
                        ->get();

                    $confirmations = DB::table('leasyback_order_confirmations')
                        ->where('auftragsnummer', $order->auftragsnummer)
                        ->get();

                    $reportDocs = DB::table('vehicle_report_documents')
                        ->where('vehicle_id', $order->vehicle_id)
                        ->where('auftragsnummer', $order->auftragsnummer)
                        ->where('published', true)
                        ->orderByDesc('created_at')
                        ->get();

                    return [
                        'id' => $order->id,
                        'auftragsnummer' => $order->auftragsnummer,
                        'leasyback_partner' => $order->leasyback_partner,
                        'sent_at' => $order->sent_at,
                        'request_payload' => json_decode($order->request_payload),
                        'response_status' => $order->response_status,
                        'response_body' => json_decode($order->response_body),
                        'order_status' => $order->order_status,
                        'created_by_user_id' => $order->created_by_user_id,
                        'created_at' => $order->created_at,
                        'status_updates' => $statusUpdates,
                        'order_confirmations' => $confirmations,
                        'report_documents' => $reportDocs,
                    ];
                });

            $vehicleArr = $vehicle->toArray();
            $vehicleArr['orders'] = $orders;
            return $vehicleArr;
        });

        return response()->json($vehicles);
    }
}
