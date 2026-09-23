<?php

namespace App\Modules\UserProfile\Vehicle\Http\Controllers;

use App\Models\LeasybackUserProfile;
use App\Models\Vehicle;
use App\Models\VehicleAuditLog;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\Vehicle\Http\Requests\StoreVehicleRequest;
use App\Modules\UserProfile\Vehicle\Http\Requests\UpdateVehicleRequest;
use App\Modules\UserProfile\Vehicle\Services\VehicleScopeService;
use App\Modules\UserProfile\Vehicle\Services\VehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class VehicleController extends Controller
{
    public function __construct(
        private VehicleScopeService $scope,
        private VehicleService $vehicleService,
        private B2bContext $b2bContext,
    ) {}

    /**
     * POST /vehicle/create
     */
    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $vehicle = $this->vehicleService->createVehicle($request->user(), $request->validated());

        return response()->json($vehicle, 201);
    }

    /**
     * PATCH /vehicle/{vehicleId}
     */
    public function update(UpdateVehicleRequest $request, string $vehicleId): JsonResponse
    {
        $user = $request->user();
        $vehicle = Vehicle::find($vehicleId);

        if (! $vehicle || ! $user->can('update', $vehicle)) {
            return response()->json('Vehicle not found', 404);
        }

        $vehicle = $this->vehicleService->updateVehicle($vehicle, $request->validated(), $user);

        return response()->json($vehicle);
    }

    /**
     * PUT /vehicle/{vehicleId} — assign profile
     *
     * docs/B2C_ADMIN_PERMISSION_MATRIX.md's Vehicle row flags this as a
     * fix needed (audit §3.2): the assigned profile must belong to the
     * vehicle's actual owner, not just any profile_id the caller supplies.
     * Previously validated only that profile_id was an integer — any
     * vehicle owner (or Admin acting on their behalf) could attach a
     * completely unrelated user's profile to a vehicle. The check is
     * against the vehicle's owner, not the calling user, so Admin can
     * still assign the real owner's own profile on their behalf.
     */
    public function assignProfile(Request $request, string $vehicleId): JsonResponse
    {
        $user = $request->user();
        $vehicle = Vehicle::find($vehicleId);

        if (! $vehicle || ! $user->can('update', $vehicle)) {
            return response()->json('Vehicle not found', 404);
        }

        $validated = $request->validate([
            'profile_id' => 'required|integer',
        ]);

        if (! $this->profileBelongsToVehicleOwner($vehicle, $validated['profile_id'])) {
            return response()->json(['error' => 'This profile does not belong to the vehicle\'s owner'], 422);
        }

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
     * B2C: the profile must belong to the vehicle's own b2c_user_id.
     * B2B: the profile must belong to some member of the vehicle's b2b
     * company (mirrors how vehicle ownership itself is scoped for B2B
     * elsewhere in this controller — "own company's vehicles only").
     */
    private function profileBelongsToVehicleOwner(Vehicle $vehicle, int $profileId): bool
    {
        $profile = LeasybackUserProfile::find($profileId);
        if (! $profile) {
            return false;
        }

        if ($vehicle->vehicle_belongs === 'B2C') {
            return (int) $profile->user_id === (int) $vehicle->b2c_user_id;
        }

        if ($vehicle->vehicle_belongs === 'B2B') {
            return DB::table('user_b2b')
                ->where('b2b_id', $vehicle->b2b_id)
                ->where('user_id', $profile->user_id)
                ->exists();
        }

        return false;
    }

    /**
     * GET /vehicle/find/{vehicleId}/{ownerId}
     *
     * The client-supplied ownerId is never trusted for the access decision
     * — findVehicleWithAccess() scopes to vehicles the authenticated user
     * actually owns (or all vehicles for Admin). This used to run an
     * unscoped query and trust ownerId alone, letting any authenticated
     * user read any vehicle by pairing a guessed vehicleId with its real
     * owner_id. ownerId is still checked against the resolved vehicle so
     * the endpoint's existing contract (id + owner must both match) holds.
     */
    public function findByOwner(Request $request, string $vehicleId, string $ownerId): JsonResponse
    {
        $user = $request->user();
        $vehicle = $this->scope->findVehicleWithAccess($vehicleId, $user);

        $matchesRequestedOwner = $vehicle !== null && match ($vehicle->vehicle_belongs) {
            'B2B' => (string) $vehicle->b2b_id === $ownerId,
            'B2C' => (string) $vehicle->b2c_user_id === $ownerId,
            default => false,
        };

        if (! $matchesRequestedOwner) {
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

        // The owner in the URL must be the caller's own: the company they are
        // *acting as* (not whichever company or account type the row says),
        // or their own id when acting privately. Admin may list any owner.
        $userType = $this->b2bContext->effectiveUserType($user)->value;

        if ($userType === 'Firmenkunde') {
            if ($ownerId !== $this->scope->getB2bIdForUser($user->id)) {
                return response()->json(['error' => 'Not Found: You can only view vehicles for your own company'], 404);
            }
        } elseif ($userType === 'Privatkunde') {
            if ((string) $ownerId !== (string) $user->id) {
                return response()->json(['error' => 'Not Found: You can only view vehicles of your own'], 404);
            }
        }

        $query = Vehicle::where(function ($q) use ($ownerId) {
            $q->where(function ($q2) use ($ownerId) {
                $q2->where('vehicle_belongs', 'B2B')->where('b2b_id', $ownerId);
            })->orWhere(function ($q2) use ($ownerId) {
                $q2->where('vehicle_belongs', 'B2C')->where('b2c_user_id', $ownerId);
            });
        });

        // The same scope every web page and policy uses — this is what keeps a
        // member restricted to their own vehicles from listing the whole fleet.
        $vehicles = $this->scope->scopeQuery($query, $user)
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

        // Decided by the context the caller is acting in, not the raw account
        // type, so a Privatkunde acting as a company gets that company's
        // vehicles and a company member only the vehicles their scope allows.
        switch ($this->b2bContext->effectiveUserType($user)->value) {
            case 'Admin':
            case 'Privatkunde':
                break;
            case 'Firmenkunde':
                if ($this->scope->getB2bIdForUser($user->id) === null) {
                    return response()->json(['error' => 'Not Found: No B2B company is linked to this authenticated user'], 404);
                }
                break;
            default:
                return response()->json(['error' => 'Only Admin, Firmenkunde or Privatkunde can access this endpoint'], 400);
        }

        $query = $this->scope->scopeQuery(Vehicle::query(), $user)
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
