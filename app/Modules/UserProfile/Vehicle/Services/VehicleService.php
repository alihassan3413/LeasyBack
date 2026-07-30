<?php

namespace App\Modules\UserProfile\Vehicle\Services;

use App\Enums\UserType;
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
     * Generate a signed URL for an S3 key.
     */
    public function generateSignedUrl(string $s3Key, int $expiresInSeconds = 10800): string
    {
        return Storage::disk('s3')->temporaryUrl($s3Key, now()->addSeconds($expiresInSeconds));
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
                    $signedUrl = $this->generateSignedUrl($doc->s3_key, 1800); // 30 min
                    $reportDocsArr[] = [
                        'id' => $doc->id,
                        'document_type' => $doc->document_type,
                        'document_title' => $doc->document_title,
                        's3_url' => $signedUrl,
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
            ];
        }

        return $result;
    }
}
