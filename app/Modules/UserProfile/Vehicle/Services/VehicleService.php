<?php

namespace App\Modules\UserProfile\Vehicle\Services;

use App\Enums\UserType;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAuditLog;
use App\Models\VehicleDocument;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VehicleService
{
    /**
     * Resolve b2b_id from user_b2b table for a given user.
     */
    public function getB2bIdByUser(string $userId): ?string
    {
        $row = DB::table('user_b2b')->where('user_id', $userId)->first();

        return $row?->b2b_id;
    }

    /**
     * Create a vehicle, resolving its owner from the authenticated user
     * (Admin must supply vehicle_belongs/b2b_id/b2c_user_id explicitly;
     * Firmenkunde/Privatkunde are always assigned to their own company/self).
     * Used by both the Sanctum API controller and the session-authenticated
     * web dashboard controller.
     */
    public function createVehicle(User $user, array $validated): Vehicle
    {
        [$belongs, $b2bId, $b2cUserId] = $this->resolveOwnership($user, $validated);

        return DB::transaction(function () use ($validated, $belongs, $b2bId, $b2cUserId, $user) {
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
    }

    /**
     * Update a vehicle's own fields (never its owner or license plate).
     * Ownership authorization is the caller's job (VehiclePolicy) — this
     * assumes the caller is already allowed to update $vehicle.
     */
    public function updateVehicle(Vehicle $vehicle, array $validated, User $user): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $validated, $user) {
            $old = $vehicle->toArray();
            $vehicle->update(array_filter($validated, fn ($value) => $value !== null));

            VehicleAuditLog::create([
                'vehicle_id' => $vehicle->vehicle_id,
                'action' => 'UPDATE',
                'old_values' => $old,
                'new_values' => $validated,
                'changed_by_user_id' => $user->id,
            ]);

            return $vehicle->fresh();
        });
    }

    /**
     * Store an uploaded vehicle document. The path is always server-derived
     * — the client never supplies or sees a storage path/key, only the
     * resulting document_id. Used by both the Sanctum API controller and
     * the session-authenticated web dashboard controller.
     */
    public function uploadDocument(string $vehicleId, UploadedFile $file, string $documentType, User $user): VehicleDocument
    {
        $originalName = $file->getClientOriginalName();
        $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $ext = $file->getClientOriginalExtension();
        $path = "vehicle-documents/{$vehicleId}/{$safeName}-".Str::uuid().".{$ext}";

        Storage::disk('documents')->put($path, file_get_contents($file));

        return VehicleDocument::create([
            'vehicle_id' => $vehicleId,
            'document_category' => 'Fahrzeug',
            'document_type' => $documentType,
            'original_file_name' => $originalName,
            'path' => $path,
            'content_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by_user_id' => $user->id,
        ]);
    }

    public function deleteDocument(VehicleDocument $document): void
    {
        Storage::disk('documents')->delete($document->path);
        $document->delete();
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?int} [belongs, b2b_id, b2c_user_id]
     */
    private function resolveOwnership(User $user, array $validated): array
    {
        return match ($user->user_type->value) {
            'Admin' => $this->resolveAdminOwnership($validated),
            'Firmenkunde' => $this->resolveFirmenkundeOwnership($user),
            'Privatkunde' => ['B2C', null, $user->id],
            default => $this->fail(400, 'Not proper user type'),
        };
    }

    private function resolveAdminOwnership(array $validated): array
    {
        $belongs = $validated['vehicle_belongs'] ?? abort(422, 'vehicle_belongs is required for Admin');

        if ($belongs === 'B2B') {
            $b2bId = $validated['b2b_id'] ?? abort(422, 'b2b_id is required for B2B vehicle');

            return ['B2B', $b2bId, null];
        }

        $b2cUserId = $validated['b2c_user_id'] ?? abort(422, 'b2c_user_id is required for B2C vehicle');

        return ['B2C', null, $b2cUserId];
    }

    private function resolveFirmenkundeOwnership(User $user): array
    {
        $b2bId = $this->getB2bIdByUser((string) $user->id);

        if (! $b2bId) {
            $this->fail(404, 'Not Found: B2B profile not found');
        }

        return ['B2B', $b2bId, null];
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }

    /**
     * Resolve vehicle access scope based on user type.
     * Returns ['type' => Admin|B2B|B2C, 'owner_id' => uuid|null]
     */
    public function resolveVehicleAccessScope(object $user): array
    {
        $userType = $user->user_type instanceof UserType
            ? $user->user_type->value
            : (string) $user->user_type;

        return match ($userType) {
            'Admin' => ['type' => 'Admin', 'owner_id' => null],
            'Firmenkunde' => [
                'type' => 'B2B',
                'owner_id' => $this->getB2bIdByUser($user->user_id),
            ],
            'Privatkunde' => ['type' => 'B2C', 'owner_id' => $user->user_id],
            default => ['type' => 'invalid', 'owner_id' => null],
        };
    }

    /**
     * Fetch vehicle row_to_json equivalent by scope for ownership verification.
     */
    public function fetchVehicleByScope(string $vehicleId, array $scope): ?object
    {
        return match ($scope['type']) {
            'Admin' => DB::table('vehicles')->where('vehicle_id', $vehicleId)->first(),
            'B2B' => DB::table('vehicles')
                ->where('vehicle_id', $vehicleId)
                ->where('vehicle_belongs', 'B2B')
                ->where('b2b_id', $scope['owner_id'])
                ->first(),
            'B2C' => DB::table('vehicles')
                ->where('vehicle_id', $vehicleId)
                ->where('vehicle_belongs', 'B2C')
                ->where('b2c_user_id', $scope['owner_id'])
                ->first(),
            default => null,
        };
    }

    /**
     * Check if vehicle has an unfinished order.
     */
    public function hasUnfinishedOrder(string $vehicleId): bool
    {
        return DB::table('leasyback_orders')
            ->where('vehicle_id', $vehicleId)
            ->whereNotIn('order_status', ['delivered', 'cancelled', 'discarded'])
            ->exists();
    }

    /**
     * Generate auftragsnummer from license plate + local date.
     */
    public function generateAuftragsnummer(string $licensePlate): string
    {
        $cleaned = str_replace([' ', '-'], '', $licensePlate);

        return $cleaned.now()->format('ymd');
    }

    /**
     * Generate a temporary signed URL for a document's storage path, via the
     * swappable `documents` disk (see config/filesystems.php) rather than a
     * hardcoded 's3' disk — works the same on local or S3 without this
     * method changing.
     */
    public function generateSignedUrl(string $path, int $expiresInSeconds = 10800): string
    {
        return Storage::disk('documents')->temporaryUrl($path, now()->addSeconds($expiresInSeconds));
    }

    /**
     * Insert vehicle audit log (best effort).
     */
    public function auditVehicle(string $vehicleId, string $action, $oldValues, $newValues, string $changedByUserId): void
    {
        try {
            DB::table('vehicle_audit_log')->insert([
                'log_id' => (string) Str::uuid(),
                'vehicle_id' => $vehicleId,
                'action' => $action,
                'old_values' => $oldValues ? json_encode($oldValues) : null,
                'new_values' => $newValues ? json_encode($newValues) : null,
                'changed_by_user_id' => $changedByUserId,
                'changed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // best effort
        }
    }

    /**
     * Validate allowed document types.
     */
    /**
     * Customer-selectable document types only — gutachten/Sonstiges are
     * Admin-managed report/invoice types, not something a customer upload
     * may declare (docs/B2C_ADMIN_PERMISSION_MATRIX.md's Vehicle Document
     * row). Currently unused (both VehicleDocumentController::upload()
     * methods inline the same restriction directly in their validation
     * rules) — kept in sync so it's not a landmine if it's ever wired in.
     */
    public function isValidDocumentType(string $type): bool
    {
        return in_array($type, ['Leasingvertrag', 'vorschaden']);
    }

    /**
     * Validate file type by extension.
     */
    public function isValidFileType(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($ext, ['pdf', 'jpg', 'jpeg', 'png']);
    }

    /**
     * Columns the dashboard table may be sorted by, mapped to the ordering
     * actually applied. Anything else falls back to VEHICLE_SORT_DEFAULT.
     *
     * @var array<string, list<string>>
     */
    private const VEHICLE_SORTS = [
        'license_plate' => ['license_plate'],
        'make' => ['make', 'model'],
        'leasing_end_date' => ['leasing_end_date'],
        'status' => ['current_order_status'],
        'created_at' => ['created_at'],
    ];

    private const VEHICLE_SORT_DEFAULT = 'created_at';

    /** Free-text search covers every column a customer would recognise a vehicle by. */
    private const VEHICLE_SEARCH_COLUMNS = ['license_plate', 'make', 'model', 'vin', 'leasinggeber'];

    /**
     * List vehicles with nested orders for dashboard.
     *
     * Children are fetched in one batched query per relation and grouped in
     * memory, so the query count stays constant (7) no matter how many
     * vehicles or orders come back.
     *
     * @param  array{search?: string, status?: string, sort?: string, direction?: string}  $filters
     */
    public function listVehiclesWithOrders(?string $ownerId, string $belongs, array $filters = []): array
    {
        $query = DB::table('vehicles as v');

        if ($belongs === 'ALL') {
            // Admin sees all
        } elseif ($belongs === 'B2B') {
            $query->where('v.vehicle_belongs', 'B2B')->where('v.b2b_id', $ownerId);
        } else {
            $query->where('v.vehicle_belongs', 'B2C')->where('v.b2c_user_id', $ownerId);
        }

        $vehicles = $this->applyVehicleFilters($query, $filters)->get();

        if ($vehicles->isEmpty()) {
            return [];
        }

        $vehicleIds = $vehicles->pluck('vehicle_id')->all();

        $ordersByVehicle = DB::table('leasyback_orders')
            ->whereIn('vehicle_id', $vehicleIds)
            ->where('order_status', '!=', 'cancelled')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('vehicle_id');

        $auftragsnummern = $ordersByVehicle->flatten(1)->pluck('auftragsnummer')->unique()->all();
        $orderIds = $ordersByVehicle->flatten(1)->pluck('id')->all();

        $statusUpdatesByOrder = DB::table('leasyback_order_status_updates')
            ->whereIn('auftragsnummer', $auftragsnummern)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('auftragsnummer');

        $confirmationsByOrder = DB::table('leasyback_order_confirmations')
            ->whereIn('auftragsnummer', $auftragsnummern)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('auftragsnummer');

        $reportDocsByOrder = DB::table('vehicle_report_documents')
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereIn('auftragsnummer', $auftragsnummern)
            ->where('published', true)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn ($doc) => $doc->vehicle_id.'|'.$doc->auftragsnummer);

        // Only published/selected offers — matches OfferController::customerList's
        // own filter, so the dashboard never shows a customer a draft/cancelled offer.
        $offersByOrder = DB::table('leasyback_offers')
            ->whereIn('order_id', $orderIds)
            ->whereIn('offer_status', ['published', 'selected'])
            ->orderBy('offer_sequence')
            ->get()
            ->groupBy('order_id');

        $documentsByVehicle = DB::table('vehicle_documents')
            ->whereIn('vehicle_id', $vehicleIds)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('vehicle_id');

        $result = [];
        foreach ($vehicles as $vehicle) {
            $ordersArr = [];
            foreach ($ordersByVehicle->get($vehicle->vehicle_id, collect()) as $order) {
                $statusUpdates = $statusUpdatesByOrder->get($order->auftragsnummer, collect())
                    ->map(fn ($su) => [
                        'id' => $su->id,
                        'bewertung_id' => $su->bewertung_id,
                        'old_status' => $su->old_status,
                        'new_status' => $su->new_status,
                        'created_at' => $su->created_at,
                    ])
                    ->values()
                    ->toArray();

                $confirmations = $confirmationsByOrder->get($order->auftragsnummer, collect())
                    ->map(fn ($oc) => [
                        'id' => $oc->id,
                        'auftragsnummer' => $oc->auftragsnummer,
                        'confirmation_date' => $oc->confirmation_date,
                        'created_at' => $oc->created_at,
                    ])
                    ->values()
                    ->toArray();

                $reportDocsArr = $reportDocsByOrder
                    ->get($vehicle->vehicle_id.'|'.$order->auftragsnummer, collect())
                    ->map(fn ($doc) => [
                        'id' => $doc->id,
                        'document_type' => $doc->document_type,
                        'document_title' => $doc->document_title,
                        'url' => $this->generateSignedUrl($doc->path, 1800), // 30 min
                        'published' => $doc->published,
                        'created_at' => $doc->created_at,
                        'updated_at' => $doc->updated_at,
                    ])
                    ->values()
                    ->toArray();

                $offers = $offersByOrder->get($order->id, collect())
                    ->map(fn ($offer) => [
                        'offer_id' => $offer->offer_id,
                        'offer_sequence' => $offer->offer_sequence,
                        'offer_status' => $offer->offer_status,
                        'final_total_gross' => $offer->final_total_gross,
                        'additional_notes' => $offer->additional_notes,
                        'published_at' => $offer->published_at,
                        'selected_at' => $offer->selected_at,
                    ])
                    ->values()
                    ->toArray();

                $ordersArr[] = [
                    'id' => $order->id,
                    'auftragsnummer' => $order->auftragsnummer,
                    'leasyback_partner' => $order->leasyback_partner,
                    'sent_at' => $order->sent_at,
                    'request_payload' => json_decode($order->request_payload ?? '', false) ?: null,
                    'response_status' => $order->response_status,
                    'response_body' => json_decode($order->response_body ?? '', false) ?: null,
                    'order_status' => $order->order_status,
                    'created_by_user_id' => $order->created_by_user_id,
                    'created_at' => $order->created_at,
                    'status_updates' => $statusUpdates,
                    'order_confirmations' => $confirmations,
                    'report_documents' => $reportDocsArr,
                    'offers' => $offers,
                ];
            }

            $documents = $documentsByVehicle->get($vehicle->vehicle_id, collect())
                ->map(fn ($doc) => [
                    'document_id' => $doc->document_id,
                    'document_type' => $doc->document_type,
                    'original_file_name' => $doc->original_file_name,
                    'created_at' => $doc->created_at,
                ])
                ->values()
                ->toArray();

            $result[] = [
                'vehicle_id' => $vehicle->vehicle_id,
                'license_plate' => $vehicle->license_plate,
                'first_registration_date' => $vehicle->first_registration_date,
                'leasing_end_date' => $vehicle->leasing_end_date,
                'leasinggeber' => $vehicle->leasinggeber ?? null,
                'vin' => $vehicle->vin,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'vehicle_belongs' => $vehicle->vehicle_belongs,
                'created_at' => $vehicle->created_at,
                'updated_at' => $vehicle->updated_at,
                'orders' => $ordersArr,
                'documents' => $documents,
            ];
        }

        return $result;
    }

    /**
     * Apply the dashboard's search / status filter / sort to the vehicle query.
     *
     * The current status lives on the vehicle's latest non-cancelled order, so
     * it is resolved as a correlated subquery and the whole thing is wrapped in
     * a derived table — neither MySQL nor SQLite allows filtering or ordering a
     * select alias directly in the same statement.
     *
     * @param  array{search?: string, status?: string, sort?: string, direction?: string}  $filters
     */
    private function applyVehicleFilters(Builder $query, array $filters): Builder
    {
        $latestStatus = DB::table('leasyback_orders as lo')
            ->select('lo.order_status')
            ->whereColumn('lo.vehicle_id', 'v.vehicle_id')
            ->where('lo.order_status', '!=', 'cancelled')
            ->orderByDesc('lo.created_at')
            ->limit(1);

        $query->select('v.*')->selectSub($latestStatus, 'current_order_status');

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $scoped) use ($term) {
                foreach (self::VEHICLE_SEARCH_COLUMNS as $column) {
                    $scoped->orWhere("v.{$column}", 'like', $term);
                }
            });
        }

        $outer = DB::query()->fromSub($query, 'f');

        $status = (string) ($filters['status'] ?? '');

        if ($status === 'none') {
            $outer->whereNull('f.current_order_status');
        } elseif ($status !== '') {
            $outer->where('f.current_order_status', $status);
        }

        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $sort = (string) ($filters['sort'] ?? self::VEHICLE_SORT_DEFAULT);
        $columns = self::VEHICLE_SORTS[$sort] ?? self::VEHICLE_SORTS[self::VEHICLE_SORT_DEFAULT];

        foreach ($columns as $column) {
            $outer->orderBy("f.{$column}", $direction);
        }

        if ($sort !== self::VEHICLE_SORT_DEFAULT) {
            $outer->orderByDesc('f.created_at');
        }

        return $outer;
    }
}
