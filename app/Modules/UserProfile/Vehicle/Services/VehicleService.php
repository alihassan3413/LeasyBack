<?php

namespace App\Modules\UserProfile\Vehicle\Services;

use App\Enums\UserType;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAuditLog;
use App\Models\VehicleDocument;
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
    public function isValidDocumentType(string $type): bool
    {
        return in_array($type, ['Leasingvertrag', 'vorschaden', 'gutachten', 'Sonstiges']);
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
     * List vehicles with nested orders for dashboard.
     */
    public function listVehiclesWithOrders(?string $ownerId, string $belongs): array
    {
        $query = DB::table('vehicles as v');

        if ($belongs === 'ALL') {
            // Admin sees all
        } elseif ($belongs === 'B2B') {
            $query->where('v.vehicle_belongs', 'B2B')->where('v.b2b_id', $ownerId);
        } else {
            $query->where('v.vehicle_belongs', 'B2C')->where('v.b2c_user_id', $ownerId);
        }

        $vehicles = $query->orderByDesc('v.created_at')->get();

        $result = [];
        foreach ($vehicles as $vehicle) {
            $orders = DB::table('leasyback_orders')
                ->where('vehicle_id', $vehicle->vehicle_id)
                ->where('order_status', '!=', 'cancelled')
                ->orderByDesc('created_at')
                ->get();

            $ordersArr = [];
            foreach ($orders as $order) {
                $statusUpdates = DB::table('leasyback_order_status_updates')
                    ->where('auftragsnummer', $order->auftragsnummer)
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn ($su) => [
                        'id' => $su->id,
                        'bewertung_id' => $su->bewertung_id,
                        'old_status' => $su->old_status,
                        'new_status' => $su->new_status,
                        'created_at' => $su->created_at,
                    ])
                    ->values()
                    ->toArray();

                $confirmations = DB::table('leasyback_order_confirmations')
                    ->where('auftragsnummer', $order->auftragsnummer)
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn ($oc) => [
                        'id' => $oc->id,
                        'auftragsnummer' => $oc->auftragsnummer,
                        'confirmation_date' => $oc->confirmation_date,
                        'created_at' => $oc->created_at,
                    ])
                    ->values()
                    ->toArray();

                $reportDocs = DB::table('vehicle_report_documents')
                    ->where('vehicle_id', $vehicle->vehicle_id)
                    ->where('auftragsnummer', $order->auftragsnummer)
                    ->where('published', true)
                    ->orderByDesc('created_at')
                    ->get();

                $reportDocsArr = [];
                foreach ($reportDocs as $doc) {
                    $reportDocsArr[] = [
                        'id' => $doc->id,
                        'document_type' => $doc->document_type,
                        'document_title' => $doc->document_title,
                        'url' => $this->generateSignedUrl($doc->path, 1800), // 30 min
                        'published' => $doc->published,
                        'created_at' => $doc->created_at,
                        'updated_at' => $doc->updated_at,
                    ];
                }

                $ordersArr[] = [
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
                    'report_documents' => $reportDocsArr,
                ];
            }

            $documents = DB::table('vehicle_documents')
                ->where('vehicle_id', $vehicle->vehicle_id)
                ->orderByDesc('created_at')
                ->get()
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
}
