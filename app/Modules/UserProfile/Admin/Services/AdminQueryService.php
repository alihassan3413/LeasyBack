<?php

namespace App\Modules\UserProfile\Admin\Services;

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminQueryService
{
    /** @return array{page:int,limit:int,start:?CarbonImmutable,end:?CarbonImmutable,status:?string} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'nullable', 'string'],
            'order_status' => ['sometimes', 'nullable', 'string'],
        ]);

        $status = strtolower(trim((string) ($validated['order_status'] ?? $validated['status'] ?? ''))) ?: null;
        if ($status !== null && ! in_array($status, OrderStatus::values(), true)) {
            throw ValidationException::withMessages(['order_status' => 'Invalid order status']);
        }

        $start = isset($validated['start_date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['start_date'])->startOfDay()
            : null;
        $end = isset($validated['end_date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['end_date'])->endOfDay()
            : null;
        if ($start !== null && $end !== null && $end->lessThan($start)) {
            throw ValidationException::withMessages(['end_date' => 'end_date must not precede start_date']);
        }

        return [
            'page' => (int) ($validated['page'] ?? 1),
            'limit' => (int) ($validated['limit'] ?? 20),
            'start' => $start,
            'end' => $end,
            'status' => $status,
        ];
    }

    private function applyDatesAndStatus(Builder $query, array $filters, string $dateColumn): void
    {
        $query->when($filters['start'], fn (Builder $q, $date) => $q->where($dateColumn, '>=', $date));
        $query->when($filters['end'], fn (Builder $q, $date) => $q->where($dateColumn, '<=', $date));
        $query->when($filters['status'], fn (Builder $q, $status) => $q->where('o.order_status', $status));
    }

    private function applyOwnerFilter(Builder $query, ?string $userType, int|string|null $userId): void
    {
        if ($userType === 'Privatkunde') {
            $query->where('v.vehicle_belongs', 'B2C');
        } elseif ($userType === 'Firmenkunde') {
            $query->where('v.vehicle_belongs', 'B2B');
        }

        if ($userId !== null) {
            $query->where(function (Builder $owner) use ($userId) {
                $owner->where('v.b2c_user_id', $userId)
                    ->orWhereExists(function (Builder $membership) use ($userId) {
                        $membership->selectRaw('1')
                            ->from('user_b2b as ub_filter')
                            ->whereColumn('ub_filter.b2b_id', 'v.b2b_id')
                            ->where('ub_filter.user_id', $userId);
                    });
            });
        }
    }

    public function orders(Request $request, ?string $userType = null, int|string|null $userId = null): array
    {
        $filters = $this->filters($request);
        $base = DB::table('leasyback_orders as o')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'o.vehicle_id');
        $this->applyDatesAndStatus($base, $filters, 'o.created_at');
        $this->applyOwnerFilter($base, $userType, $userId);

        $counts = $this->orderCounts($base);
        $rows = (clone $base)
            ->select([
                'o.id', 'o.vehicle_id', 'o.auftragsnummer', 'o.leasyback_partner',
                'o.order_status', 'o.sent_at', 'o.created_at', 'o.response_status',
                'v.license_plate', 'v.vin', 'v.make', 'v.model',
                'v.b2c_user_id', 'v.b2b_id',
            ])
            ->when(
                $request->input('sort_by') === 'license_plate',
                function (Builder $query) use ($request) {
                    $direction = strtolower((string) $request->input('sort_order', 'asc'));
                    if (! in_array($direction, ['asc', 'desc'], true)) {
                        throw ValidationException::withMessages(['sort_order' => 'Supported values: asc, desc']);
                    }
                    $query->orderBy('v.license_plate', $direction);
                },
                fn (Builder $query) => $query->orderByDesc('o.created_at')
            )
            ->orderByDesc('o.id')
            ->offset(($filters['page'] - 1) * $filters['limit'])
            ->limit($filters['limit'])
            ->get();

        $data = $this->enrichOrders($rows);

        return array_merge([
            'page' => $filters['page'],
            'limit' => $filters['limit'],
        ], $counts, ['data' => $data]);
    }

    private function orderCounts(Builder $base): array
    {
        return [
            'total' => (clone $base)->distinct()->count('o.id'),
            'total_active' => (clone $base)->whereIn('o.order_status', OrderStatus::activeValues())->distinct()->count('o.id'),
            'total_confirmed' => (clone $base)->where('o.order_status', 'confirmed')->distinct()->count('o.id'),
            'total_inspected' => (clone $base)->where('o.order_status', 'inspected')->distinct()->count('o.id'),
            'total_delivered' => (clone $base)->where('o.order_status', 'delivered')->distinct()->count('o.id'),
        ];
    }

    private function enrichOrders(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $owners = $this->ownersForVehicles($rows);
        $auftragsnummern = $rows->pluck('auftragsnummer')->filter()->unique()->values();
        $confirmations = DB::table('leasyback_order_confirmations')
            ->whereIn('auftragsnummer', $auftragsnummern)
            ->pluck('confirmation_date', 'auftragsnummer');
        $assessmentDocs = $this->assessmentDocuments($auftragsnummern);
        $reportDocs = $this->reportDocuments($auftragsnummern);

        return $rows->map(function (object $row) use ($owners, $confirmations, $assessmentDocs, $reportDocs) {
            $owner = $owners[(string) $row->vehicle_id] ?? [];
            $key = $row->auftragsnummer.'|'.$row->vehicle_id;

            return [
                'id' => $row->id,
                'vehicle_id' => $row->vehicle_id,
                'auftragsnummer' => $row->auftragsnummer,
                'leasyback_partner' => $row->leasyback_partner,
                'order_status' => $row->order_status,
                'sent_at' => $row->sent_at,
                'created_at' => $row->created_at,
                'response_status' => $row->response_status,
                'license_plate' => $row->license_plate,
                'vin' => $row->vin,
                'make' => $row->make,
                'model' => $row->model,
                'user_id' => $owner['user_id'] ?? null,
                'user_email' => $owner['user_email'] ?? null,
                'user_type' => $owner['user_type'] ?? null,
                'b2b_id' => $row->b2b_id,
                'company_name' => $owner['company_name'] ?? null,
                'confirmation_date' => $confirmations[$row->auftragsnummer] ?? null,
                'assessment_documents' => $assessmentDocs[$row->auftragsnummer] ?? [],
                'report_documents' => $reportDocs[$key] ?? [],
            ];
        })->all();
    }

    private function ownersForVehicles(Collection $rows): array
    {
        $b2cIds = $rows->pluck('b2c_user_id')->filter()->unique();
        $b2bIds = $rows->pluck('b2b_id')->filter()->unique();
        $users = DB::table('users')->whereIn('id', $b2cIds)
            ->get(['id', 'email', 'user_type'])->keyBy('id');
        $companies = DB::table('b2b')->whereIn('b2b_id', $b2bIds)
            ->get(['b2b_id', 'company_name'])->keyBy('b2b_id');
        $members = DB::table('user_b2b as ub')
            ->join('users as u', 'u.id', '=', 'ub.user_id')
            ->whereIn('ub.b2b_id', $b2bIds)
            ->orderBy('u.created_at')->orderBy('u.id')
            ->get(['ub.b2b_id', 'u.id', 'u.email', 'u.user_type'])
            ->groupBy('b2b_id')->map->first();

        $result = [];
        foreach ($rows as $row) {
            $user = $row->b2c_user_id !== null ? $users->get($row->b2c_user_id) : $members->get($row->b2b_id);
            $result[(string) $row->vehicle_id] = [
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'user_type' => $user?->user_type,
                'company_name' => $companies->get($row->b2b_id)?->company_name,
            ];
        }

        return $result;
    }

    private function assessmentDocuments(Collection $auftragsnummern): array
    {
        return DB::table('assessment_documents as ad')
            ->join('vehicle_assessments as va', 'va.id', '=', 'ad.assessment_id')
            ->whereIn('va.auftragsnummer', $auftragsnummern)
            ->orderByDesc('ad.created_at')
            ->get([
                'ad.id', 'ad.assessment_id', 'va.auftragsnummer', 'ad.doc_type',
                'ad.external_id', 'ad.title', 'ad.mime', 'ad.file_format', 'ad.sort_order',
                'ad.s3_bucket', 'ad.s3_key', 'ad.s3_url', 'ad.created_at',
            ])->groupBy('auftragsnummer')->map(function (Collection $documents) {
                return $documents->map(function (object $document) {
                    $item = (array) $document;
                    $item['signed_url'] = $this->temporaryUrl($document->s3_key, 30);

                    return $item;
                })->values()->all();
            })->all();
    }

    private function reportDocuments(Collection $auftragsnummern): array
    {
        return DB::table('vehicle_report_documents')
            ->whereIn('auftragsnummer', $auftragsnummern)
            ->orderByDesc('created_at')->get()
            ->groupBy(fn (object $document) => $document->auftragsnummer.'|'.$document->vehicle_id)
            ->map(function (Collection $documents) {
                return $documents->map(function (object $document) {
                    $item = (array) $document;
                    $item['published'] = (bool) $document->published;
                    $item['signed_url'] = $this->temporaryUrl($document->s3_key, 30);

                    return $item;
                })->values()->all();
            })->all();
    }

    private function temporaryUrl(string $key, int $minutes): ?string
    {
        try {
            return Storage::disk((string) config('tim.storage_disk', 's3'))
                ->temporaryUrl($key, now()->addMinutes($minutes));
        } catch (\Throwable) {
            return null;
        }
    }

    public function vehicles(Request $request, ?string $userType = null, int|string|null $userId = null): array
    {
        $filters = $this->filters($request);
        $base = DB::table('vehicles as v')
            ->leftJoin('leasyback_orders as o', function ($join) {
                $join->on('o.vehicle_id', '=', 'v.vehicle_id')
                    ->whereRaw('o.id = (SELECT latest.id FROM leasyback_orders latest WHERE latest.vehicle_id = v.vehicle_id ORDER BY latest.created_at DESC, latest.id DESC LIMIT 1)');
            });
        $base->when($filters['start'], fn (Builder $q, $date) => $q->where('v.created_at', '>=', $date));
        $base->when($filters['end'], fn (Builder $q, $date) => $q->where('v.created_at', '<=', $date));
        $base->when($filters['status'], fn (Builder $q, $status) => $q->where('o.order_status', $status));
        $this->applyOwnerFilter($base, $userType, $userId);

        $counts = $this->vehicleCounts($base);
        $rows = (clone $base)->select([
            'v.vehicle_id', 'v.license_plate', 'v.first_registration_date', 'v.leasing_end_date',
            'v.leasinggeber', 'v.vin', 'v.make', 'v.model', 'v.vehicle_belongs',
            'v.b2b_id', 'v.b2c_user_id', 'v.assigned_profile_id', 'v.created_at', 'v.updated_at',
            'o.id as current_order_id', 'o.auftragsnummer as current_auftragsnummer',
            'o.order_status as current_order_status', 'o.created_at as current_order_created_at',
        ])->orderByDesc('v.created_at')->orderByDesc('v.vehicle_id')
            ->offset(($filters['page'] - 1) * $filters['limit'])->limit($filters['limit'])->get();

        return array_merge([
            'page' => $filters['page'],
            'limit' => $filters['limit'],
        ], $counts, ['data' => $this->enrichVehicles($rows)]);
    }

    private function vehicleCounts(Builder $base): array
    {
        return [
            'total' => (clone $base)->distinct()->count('v.vehicle_id'),
            'total_active' => (clone $base)->whereIn('o.order_status', OrderStatus::activeValues())->distinct()->count('v.vehicle_id'),
            'total_completed' => (clone $base)->where('o.order_status', 'delivered')->distinct()->count('v.vehicle_id'),
            'total_confirmed' => (clone $base)->where('o.order_status', 'confirmed')->distinct()->count('v.vehicle_id'),
            'total_inspected' => (clone $base)->where('o.order_status', 'inspected')->distinct()->count('v.vehicle_id'),
            'total_delivered' => (clone $base)->where('o.order_status', 'delivered')->distinct()->count('v.vehicle_id'),
        ];
    }

    private function enrichVehicles(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $owners = $this->ownersForVehicles($rows);
        $vehicleIds = $rows->pluck('vehicle_id')->unique()->values();
        $history = DB::table('leasyback_orders as o')
            ->leftJoin('leasyback_order_confirmations as c', 'c.auftragsnummer', '=', 'o.auftragsnummer')
            ->whereIn('o.vehicle_id', $vehicleIds)->orderByDesc('o.created_at')
            ->get([
                'o.vehicle_id', 'o.id', 'o.auftragsnummer', 'o.leasyback_partner',
                'o.order_status', 'o.sent_at', 'o.created_at', 'o.response_status', 'c.confirmation_date',
            ])->groupBy('vehicle_id')->map(fn (Collection $items) => $items->map(function (object $item) {
                unset($item->vehicle_id);

                return (array) $item;
            })->values()->all());
        $documents = DB::table('vehicle_documents')->whereIn('vehicle_id', $vehicleIds)
            ->orderByDesc('created_at')->get([
                'vehicle_id', 'document_id', 'document_category', 'document_type',
                'original_file_name', 's3_key', 'content_type', 'file_size',
                'uploaded_by_user_id', 'created_at',
            ])->groupBy('vehicle_id')->map(fn (Collection $items) => $items->map(function (object $item) {
                unset($item->vehicle_id);

                return (array) $item;
            })->values()->all());

        return $rows->map(function (object $row) use ($owners, $history, $documents) {
            $owner = $owners[(string) $row->vehicle_id] ?? [];

            return [
                'vehicle_id' => $row->vehicle_id,
                'license_plate' => $row->license_plate,
                'first_registration_date' => $row->first_registration_date,
                'leasing_end_date' => $row->leasing_end_date,
                'leasinggeber' => $row->leasinggeber,
                'vin' => $row->vin,
                'make' => $row->make,
                'model' => $row->model,
                'vehicle_belongs' => $row->vehicle_belongs,
                'b2b_id' => $row->b2b_id,
                'b2c_user_id' => $row->b2c_user_id,
                'assigned_profile_id' => $row->assigned_profile_id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'user_id' => $owner['user_id'] ?? null,
                'user_email' => $owner['user_email'] ?? null,
                'user_type' => $owner['user_type'] ?? null,
                'company_name' => $owner['company_name'] ?? null,
                'current_order_id' => $row->current_order_id,
                'current_auftragsnummer' => $row->current_auftragsnummer,
                'current_order_status' => $row->current_order_status,
                'current_order_created_at' => $row->current_order_created_at,
                'order_history' => $history[$row->vehicle_id] ?? [],
                'documents' => $documents[$row->vehicle_id] ?? [],
            ];
        })->all();
    }

    public function validatedUserType(Request $request): string
    {
        $type = $request->validate([
            'user_type' => ['required', 'string', 'in:Privatkunde,Firmenkunde'],
        ])['user_type'];

        return trim($type);
    }
}
