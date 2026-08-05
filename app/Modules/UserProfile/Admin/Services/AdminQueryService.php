<?php

namespace App\Modules\UserProfile\Admin\Services;

use App\Enums\OrderStatus;
use App\Modules\UserProfile\Order\Actions\TransitionOrderStatus;
use App\Modules\UserProfile\Order\Services\AppraisalPositionService;
use App\Modules\UserProfile\Order\Services\B2bBillingService;
use App\Modules\UserProfile\Order\Services\B2bOfferService;
use App\Modules\UserProfile\Order\Services\OrderCollectionService;
use App\Modules\UserProfile\Order\Services\OrderTaskResolver;
use App\Modules\UserProfile\Order\Services\WorkshopQuotationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminQueryService
{
    public function __construct(
        private readonly OrderCollectionService $orderCollectionService,
        private readonly OrderTaskResolver $orderTaskResolver,
        private readonly AppraisalPositionService $appraisalPositionService,
        private readonly WorkshopQuotationService $workshopQuotationService,
        private readonly B2bOfferService $b2bOfferService,
        private readonly B2bBillingService $b2bBillingService,
    ) {}

    /**
     * Cross-domain dashboard counts — moved here from the Sanctum API's
     * AdminController::summary() (unchanged response shape) so the new
     * session-authenticated web Admin dashboard can reuse it without
     * duplicating the raw SQL.
     *
     * The active and completed status lists are derived from OrderStatus
     * rather than repeated inline, so a new B2B status cannot fall out of
     * both buckets the way the six added in phase 5 did. `pending_inspections`
     * keeps its own literal pair: it is the B2C "awaiting inspection" stat,
     * not an active/closed partition.
     */
    public function summary(): object
    {
        $active = OrderStatus::activeValues();
        $completed = OrderStatus::completedValues();

        return DB::selectOne(sprintf("
            SELECT
                (SELECT COUNT(*) FROM users WHERE user_type = 'Privatkunde') AS total_b2c_customers,
                (SELECT COUNT(*) FROM users WHERE user_type = 'Firmenkunde') AS total_b2b_users,
                (SELECT COUNT(*) FROM b2b) AS total_b2b_companies,
                (SELECT COUNT(*) FROM vehicles) AS total_vehicles,
                (SELECT COUNT(*) FROM leasyback_orders) AS total_orders,
                (SELECT COUNT(*) FROM leasyback_orders WHERE order_status IN (%s)) AS active_orders,
                (SELECT COUNT(*) FROM leasyback_orders WHERE order_status IN (%s)) AS delivered_orders,
                (SELECT COUNT(*) FROM leasyback_orders WHERE order_status IN ('order_placed','confirmed')) AS pending_inspections
        ", $this->bindingPlaceholders($active), $this->bindingPlaceholders($completed)), [...$active, ...$completed]);
    }

    /**
     * @param  array<int, string>  $values
     */
    private function bindingPlaceholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * Moved from AdminController::b2c() (unchanged query, unchanged response
     * shape) so the new web Admin customer list can reuse it. Added
     * parameterized `search` support (name/email/city) — leasyback_web's
     * own admin panel fetched the *entire* customer list client-side and
     * searched in the browser; this repo shouldn't repeat that, and a
     * bound `LIKE` clause is cheap to add safely. The aggregate
     * total/total_active/total_inactive counts are intentionally
     * unaffected by `is_active`/`search` — they're header stats over the
     * whole Privatkunde population, not the currently filtered page.
     */
    public function b2cList(Request $request): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $offset = ($page - 1) * $limit;
        $isActive = $request->query('is_active');
        $search = trim((string) $request->query('search', ''));

        $counts = DB::selectOne("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN u.is_active THEN 1 ELSE 0 END) AS total_active,
                SUM(CASE WHEN u.is_active THEN 0 ELSE 1 END) AS total_inactive
            FROM users u WHERE u.user_type = 'Privatkunde'
        ");

        $query = DB::table('users as u')
            ->leftJoin('user_profiles as up', 'up.user_id', '=', 'u.id')
            ->leftJoin('contacts as c', 'c.contact_id', '=', 'up.contact_id')
            ->leftJoin('addresses as a', 'a.address_id', '=', 'c.address_id')
            ->where('u.user_type', 'Privatkunde')
            ->when($isActive !== null, fn (Builder $q) => $q->where('u.is_active', $isActive === 'true'))
            ->when($search !== '', fn (Builder $q) => $this->applyCustomerSearch($q, $search, ['u.email', 'c.first_name', 'c.last_name', 'a.city']));

        $users = (clone $query)
            ->select([
                'u.id as user_id', 'u.email as user_email', 'u.user_type', 'u.is_active',
                'up.profile_id', 'up.image_url',
                'c.contact_id', 'c.salutation', 'c.first_name', 'c.last_name',
                'a.address_id', 'a.street', 'a.number', 'a.additional_address',
                'a.zip_code', 'a.city', 'a.country', 'u.created_at',
            ])
            ->orderByDesc('u.created_at')
            ->offset($offset)->limit($limit)->get();

        return [
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $counts->total,
            'total_active' => (int) $counts->total_active,
            'total_inactive' => (int) $counts->total_inactive,
            'data' => $users,
        ];
    }

    /**
     * Moved from AdminController::b2b() (unchanged query, unchanged response
     * shape — one row per (user, company) membership, same as before) plus
     * the same `search` addition as b2cList().
     */
    public function b2bList(Request $request): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $offset = ($page - 1) * $limit;
        $isActive = $request->query('is_active');
        $search = trim((string) $request->query('search', ''));

        $counts = DB::selectOne("
            SELECT
                COUNT(DISTINCT b.b2b_id) AS total,
                COUNT(DISTINCT CASE WHEN b.is_active THEN b.b2b_id END) AS total_active,
                COUNT(DISTINCT CASE WHEN b.is_active THEN NULL ELSE b.b2b_id END) AS total_inactive
            FROM users u
            INNER JOIN user_b2b ub ON ub.user_id = u.id
            INNER JOIN b2b b ON b.b2b_id = ub.b2b_id
            WHERE u.user_type = 'Firmenkunde'
        ");

        $query = DB::table('users as u')
            ->join('user_b2b as ub', 'ub.user_id', '=', 'u.id')
            ->join('b2b as b', 'b.b2b_id', '=', 'ub.b2b_id')
            ->join('contacts as c', 'c.contact_id', '=', 'b.contact_id')
            ->join('addresses as a', 'a.address_id', '=', 'b.address_id')
            ->where('u.user_type', 'Firmenkunde')
            ->when($isActive !== null, fn (Builder $q) => $q->where('b.is_active', $isActive === 'true'))
            ->when($search !== '', fn (Builder $q) => $this->applyCustomerSearch($q, $search, ['u.email', 'b.company_name', 'c.first_name', 'c.last_name', 'a.city']));

        $users = (clone $query)
            ->select([
                'u.id as user_id', 'u.email as user_email', 'u.user_type',
                'b.b2b_id', 'b.company_name', 'b.vat_id', 'b.logo_url', 'b.contact_email', 'b.is_active',
                'ub.role',
                'c.contact_id', 'c.salutation', 'c.first_name', 'c.last_name',
                'a.address_id', 'a.street', 'a.number', 'a.additional_address', 'a.zip_code', 'a.city', 'a.country',
                'b.created_at',
            ])
            ->orderByDesc('b.created_at')
            ->offset($offset)->limit($limit)->get();

        return [
            'page' => $page,
            'limit' => $limit,
            'total' => (int) $counts->total,
            'total_active' => (int) $counts->total_active,
            'total_inactive' => (int) $counts->total_inactive,
            'data' => $users,
        ];
    }

    /** @param array<string> $columns */
    private function applyCustomerSearch(Builder $query, string $search, array $columns): Builder
    {
        return $query->where(function (Builder $q) use ($search, $columns) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', '%'.$search.'%');
            }
        });
    }

    /**
     * Moved from AdminController::updateB2cStatus()/updateB2bStatus() —
     * response shape unchanged, `NOW()` (Postgres-only) changed to the
     * ANSI-standard `CURRENT_TIMESTAMP` so this is actually testable under
     * this repo's sqlite test database (same fix as Checkpoint 8's
     * AdminController regression test needed).
     */
    public function updateB2cStatus(string $userId, bool $isActive): ?object
    {
        $affected = DB::update(
            "UPDATE users SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_type = 'Privatkunde'",
            [$isActive, $userId]
        );

        if (! $affected) {
            return null;
        }

        return DB::selectOne('SELECT id as user_id, email as user_email, user_type, is_active, created_at, updated_at FROM users WHERE id = ?', [$userId]);
    }

    public function updateB2bStatus(string $b2bId, bool $isActive): ?object
    {
        $affected = DB::update(
            'UPDATE b2b SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE b2b_id = ?',
            [$isActive, $b2bId]
        );

        if (! $affected) {
            return null;
        }

        return DB::selectOne('SELECT b2b_id, company_name, contact_email, is_active, created_at, updated_at FROM b2b WHERE b2b_id = ?', [$b2bId]);
    }

    /**
     * Single-customer detail for the new Admin customer detail page — the
     * Fahrzeuge/Aufträge tabs on that page reuse the existing vehicles()/
     * orders() methods (already support filtering by owner), not a
     * duplicate query here.
     */
    public function b2cDetail(int $userId): ?array
    {
        $row = DB::table('users as u')
            ->leftJoin('user_profiles as up', 'up.user_id', '=', 'u.id')
            ->leftJoin('contacts as c', 'c.contact_id', '=', 'up.contact_id')
            ->leftJoin('addresses as a', 'a.address_id', '=', 'c.address_id')
            ->where('u.id', $userId)
            ->where('u.user_type', 'Privatkunde')
            ->select([
                'u.id as user_id', 'u.email as user_email', 'u.is_active', 'u.created_at',
                'up.profile_id', 'up.image_url',
                'c.contact_id', 'c.salutation', 'c.first_name', 'c.last_name',
                'a.address_id', 'a.street', 'a.number', 'a.additional_address',
                'a.zip_code', 'a.city', 'a.country',
            ])
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * @return array{b2b_id:string,company_name:string,...,members:array}|null
     */
    public function b2bDetail(string $b2bId): ?array
    {
        $row = DB::table('b2b as b')
            ->leftJoin('contacts as c', 'c.contact_id', '=', 'b.contact_id')
            ->leftJoin('addresses as a', 'a.address_id', '=', 'b.address_id')
            ->where('b.b2b_id', $b2bId)
            ->select([
                'b.b2b_id', 'b.company_name', 'b.vat_id', 'b.logo_url', 'b.contact_email',
                'b.is_active', 'b.created_at',
                'b.service_fee_amount', 'b.service_fee_effective_from',
                'c.contact_id', 'c.salutation', 'c.first_name', 'c.last_name',
                'a.address_id', 'a.street', 'a.number', 'a.additional_address',
                'a.zip_code', 'a.city', 'a.country',
            ])
            ->first();

        if (! $row) {
            return null;
        }

        $members = DB::table('user_b2b as ub')
            ->join('users as u', 'u.id', '=', 'ub.user_id')
            ->where('ub.b2b_id', $b2bId)
            ->orderByDesc('ub.role')
            ->orderBy('u.created_at')
            ->get(['u.id as user_id', 'u.email as user_email', 'ub.role'])
            ->all();

        return [...(array) $row, 'members' => $members];
    }

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

    /**
     * Free-text `?search=` over the columns an admin would recognise a row
     * by. Bound LIKE, same approach as b2cList()/b2bList(). Callers apply it
     * to the *base* query before counting, so the header stats always match
     * the list underneath them.
     *
     * @param  list<string>  $columns
     */
    private function applyListSearch(Builder $query, Request $request, array $columns): void
    {
        $search = trim((string) $request->query('search', ''));

        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function (Builder $scoped) use ($term, $columns) {
            foreach ($columns as $column) {
                $scoped->orWhere($column, 'like', $term);
            }
        });
    }

    private function applyDatesAndStatus(Builder $query, array $filters, string $dateColumn): void
    {
        $query->when($filters['start'], fn (Builder $q, $date) => $q->where($dateColumn, '>=', $date));
        $query->when($filters['end'], fn (Builder $q, $date) => $q->where($dateColumn, '<=', $date));
        $query->when($filters['status'], fn (Builder $q, $status) => $q->where('o.order_status', $status));
    }

    /**
     * `$b2bId` is a direct company filter (used by the Admin customer
     * detail page, which knows the company id but not any one specific
     * member's user id) — distinct from `$userId`'s "this user, or the
     * company they belong to" membership lookup.
     */
    private function applyOwnerFilter(Builder $query, ?string $userType, int|string|null $userId, ?string $b2bId = null): void
    {
        if ($userType === 'Privatkunde') {
            $query->where('v.vehicle_belongs', 'B2C');
        } elseif ($userType === 'Firmenkunde') {
            $query->where('v.vehicle_belongs', 'B2B');
        }

        if ($b2bId !== null) {
            $query->where('v.b2b_id', $b2bId);
        } elseif ($userId !== null) {
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

    public function orders(Request $request, ?string $userType = null, int|string|null $userId = null, ?string $b2bId = null): array
    {
        $filters = $this->filters($request);
        $base = DB::table('leasyback_orders as o')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'o.vehicle_id');
        $this->applyDatesAndStatus($base, $filters, 'o.created_at');
        $this->applyOwnerFilter($base, $userType, $userId, $b2bId);
        $this->applyListSearch($base, $request, ['o.auftragsnummer', 'v.license_plate', 'v.vin', 'v.make', 'v.model']);

        $counts = $this->orderCounts($base);
        $rows = (clone $base)
            ->select([
                'o.id', 'o.vehicle_id', 'o.auftragsnummer', 'o.leasyback_partner',
                'o.order_status', 'o.sent_at', 'o.created_at', 'o.response_status', 'o.response_body',
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

    /**
     * Single-order detail for the Admin order detail page — reuses
     * enrichOrders() (owner, confirmation, documents) so its shape matches
     * a single row from orders(), matching how vehicleDetail() reuses
     * enrichVehicles(). Also attaches every offer (not just
     * published/selected like the customer-facing endpoint — Admin needs
     * to see drafts and cancelled offers too) and the status-update audit
     * trail.
     *
     * `available_transitions` deliberately excludes `order_placed` (that
     * transition only happens through the dedicated approve() action,
     * which also fires the external TÜV SÜD call) and `discarded` (the
     * reject action — an open product question per
     * docs/B2C_ADMIN_IMPLEMENTATION_PLAN.md §13, not yet confirmed as
     * wanted, so no endpoint exercises it).
     */
    public function orderDetail(string $orderId): ?array
    {
        $row = DB::table('leasyback_orders as o')
            ->join('vehicles as v', 'v.vehicle_id', '=', 'o.vehicle_id')
            ->where('o.id', $orderId)
            ->select([
                'o.id', 'o.vehicle_id', 'o.auftragsnummer', 'o.leasyback_partner',
                'o.order_status', 'o.sent_at', 'o.created_at', 'o.response_status', 'o.response_body',
                'v.license_plate', 'v.vin', 'v.make', 'v.model',
                'v.b2c_user_id', 'v.b2b_id', 'v.vehicle_belongs',
            ])
            ->first();

        if (! $row) {
            return null;
        }

        $order = $this->enrichOrders(collect([$row]))[0] ?? null;
        if ($order === null) {
            return null;
        }

        $order['offers'] = DB::table('leasyback_offers')
            ->where('order_id', $orderId)
            ->orderBy('offer_sequence')
            ->get()
            ->all();

        $order['status_updates'] = DB::table('leasyback_order_status_updates')
            ->where('auftragsnummer', $row->auftragsnummer)
            ->orderByDesc('created_at')
            ->get()
            ->all();

        $order['available_transitions'] = array_values(array_diff(
            TransitionOrderStatus::allowedNextStatuses($row->order_status, $row->vehicle_belongs === 'B2B'),
            ['order_placed', 'discarded'],
        ));

        $order['vehicle_belongs'] = $row->vehicle_belongs;
        $order['collection'] = $row->vehicle_belongs !== 'B2B'
            ? null
            : ($this->orderCollectionService->forOrders([$row->auftragsnummer], true)[$row->auftragsnummer] ?? null);

        $positions = $row->vehicle_belongs !== 'B2B' ? [] : $this->appraisalPositionService->forOrder($orderId);
        $order['appraisal_positions'] = $row->vehicle_belongs !== 'B2B' ? null : $positions;
        $order['appraisal_totals'] = $row->vehicle_belongs !== 'B2B' ? null : $this->appraisalPositionService->totals($positions);
        $order['workshop_quotations'] = $row->vehicle_belongs !== 'B2B'
            ? null
            : $this->workshopQuotationService->forOrder($orderId);
        $order['billing'] = $row->vehicle_belongs !== 'B2B'
            ? null
            : $this->b2bBillingService->forOrder($orderId);

        if ($row->vehicle_belongs === 'B2B') {
            $presentations = $this->b2bOfferService->forOffers(array_column($order['offers'], 'offer_id'));

            $order['offers'] = array_map(function (object $offer) use ($presentations) {
                $offer->presentation = $presentations[$offer->offer_id] ?? null;

                return $offer;
            }, $order['offers']);
        }

        $order['tasks'] = $this->orderTaskResolver->forOrderDetail($order);

        return $order;
    }

    private function orderCounts(Builder $base): array
    {
        return [
            'total' => (clone $base)->distinct()->count('o.id'),
            'total_active' => (clone $base)->whereIn('o.order_status', OrderStatus::activeValues())->distinct()->count('o.id'),
            'total_confirmed' => (clone $base)->where('o.order_status', 'confirmed')->distinct()->count('o.id'),
            'total_inspected' => (clone $base)->where('o.order_status', 'inspected')->distinct()->count('o.id'),
            'total_delivered' => (clone $base)->whereIn('o.order_status', OrderStatus::completedValues())->distinct()->count('o.id'),
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
                // Same boolean enrichVehicles() computes: is there a
                // Gutachtennummer for "Dokumente abrufen" to work from? The
                // raw response_body is read here and deliberately not
                // forwarded — it is a third-party payload.
                'can_pull_documents' => $row->leasyback_partner === 'tuvsud'
                    && is_numeric(trim((string) $row->response_body, '"')),
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
                    // vehicle_report_documents.s3_key was renamed to `path`
                    // in the 2026_08_01_000001 migration — this read this
                    // still referenced the old name, so signed_url was
                    // silently always null (temporaryUrl()'s try/catch
                    // swallows the resulting Storage error).
                    $item['signed_url'] = $this->temporaryUrl($document->path, 30, 'documents');

                    return $item;
                })->values()->all();
            })->all();
    }

    private function temporaryUrl(string $key, int $minutes, ?string $disk = null): ?string
    {
        try {
            return Storage::disk($disk ?? (string) config('tim.storage_disk', 's3'))
                ->temporaryUrl($key, now()->addMinutes($minutes));
        } catch (\Throwable) {
            return null;
        }
    }

    public function vehicles(Request $request, ?string $userType = null, int|string|null $userId = null, ?string $b2bId = null): array
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
        $this->applyOwnerFilter($base, $userType, $userId, $b2bId);

        $this->applyListSearch($base, $request, ['v.license_plate', 'v.vin', 'v.make', 'v.model', 'v.leasinggeber', 'o.auftragsnummer']);

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

    /**
     * Single-vehicle detail for the new Admin vehicle detail page — reuses
     * enrichVehicles() (owner, order_history, documents) rather than a
     * separate query, so the detail page's data shape is identical to a
     * single row from vehicles().
     */
    public function vehicleDetail(string $vehicleId): ?array
    {
        $row = DB::table('vehicles as v')
            ->leftJoin('leasyback_orders as o', function ($join) {
                $join->on('o.vehicle_id', '=', 'v.vehicle_id')
                    ->whereRaw('o.id = (SELECT latest.id FROM leasyback_orders latest WHERE latest.vehicle_id = v.vehicle_id ORDER BY latest.created_at DESC, latest.id DESC LIMIT 1)');
            })
            ->where('v.vehicle_id', $vehicleId)
            ->select([
                'v.vehicle_id', 'v.license_plate', 'v.first_registration_date', 'v.leasing_end_date',
                'v.leasinggeber', 'v.vin', 'v.make', 'v.model', 'v.vehicle_belongs',
                'v.b2b_id', 'v.b2c_user_id', 'v.assigned_profile_id', 'v.created_at', 'v.updated_at',
                'o.id as current_order_id', 'o.auftragsnummer as current_auftragsnummer',
                'o.order_status as current_order_status', 'o.created_at as current_order_created_at',
            ])
            ->first();

        if (! $row) {
            return null;
        }

        $vehicle = $this->enrichVehicles(collect([$row]))[0] ?? null;

        return $vehicle === null ? null : $this->hydrateVehicleDetail($vehicle);
    }

    /**
     * Detail-page-only enrichment, deliberately kept out of the shared
     * enrichVehicles(): the vehicle *list* renders one row per vehicle and
     * has no use for per-order payloads, status trails or offers, so
     * loading them there would be three extra queries per page for data
     * nobody reads.
     *
     * What it adds is what VehicleExpandedPanel.vue (reused verbatim from
     * the customer dashboard) reads beyond the list shape: `request_payload`
     * (Besichtigungsort card), `status_updates` (customer flow timeline),
     * `offers` (Angebote card), plus `available_transitions` for the
     * per-order admin action menu, and a signed `url` on customer documents
     * — the panel links documents by `url`, the list only ever showed names.
     *
     * @param  array<string, mixed>  $vehicle
     * @return array<string, mixed>
     */
    private function hydrateVehicleDetail(array $vehicle): array
    {
        /** @var list<array<string, mixed>> $history */
        $history = $vehicle['order_history'];
        $auftragsnummern = collect($history)->pluck('auftragsnummer')->filter()->unique()->values();
        $orderIds = collect($history)->pluck('id')->filter()->unique()->values();

        $payloads = DB::table('leasyback_orders')
            ->whereIn('id', $orderIds)
            ->pluck('request_payload', 'id');

        $statusUpdates = DB::table('leasyback_order_status_updates')
            ->whereIn('auftragsnummer', $auftragsnummern)
            ->orderByDesc('created_at')
            ->get(['id', 'auftragsnummer', 'bewertung_id', 'old_status', 'new_status', 'created_at'])
            ->groupBy('auftragsnummer');

        // Every offer status, not just published/selected — Admin manages
        // drafts and cancelled offers too (same rationale as orderDetail()).
        $offers = DB::table('leasyback_offers')
            ->whereIn('order_id', $orderIds)
            ->orderBy('offer_sequence')
            ->get()
            ->groupBy('order_id');

        $isB2b = ($vehicle['vehicle_belongs'] ?? null) === 'B2B';

        $vehicle['order_history'] = array_map(function (array $order) use ($payloads, $statusUpdates, $offers, $isB2b) {
            $order['request_payload'] = json_decode((string) ($payloads[$order['id']] ?? ''), false) ?: null;
            $order['status_updates'] = $statusUpdates->get($order['auftragsnummer'], collect())->values()->all();
            $order['offers'] = $offers->get($order['id'], collect())->values()->all();
            $order['available_transitions'] = array_values(array_diff(
                TransitionOrderStatus::allowedNextStatuses($order['order_status'], $isB2b),
                ['order_placed', 'discarded'],
            ));

            return $order;
        }, $history);

        $vehicle['documents'] = array_map(function (array $document) {
            $document['url'] = $this->temporaryUrl((string) $document['path'], 30, 'documents');

            return $document;
        }, $vehicle['documents']);

        return $vehicle;
    }

    private function vehicleCounts(Builder $base): array
    {
        return [
            'total' => (clone $base)->distinct()->count('v.vehicle_id'),
            'total_active' => (clone $base)->whereIn('o.order_status', OrderStatus::activeValues())->distinct()->count('v.vehicle_id'),
            'total_completed' => (clone $base)->whereIn('o.order_status', OrderStatus::completedValues())->distinct()->count('v.vehicle_id'),
            'total_confirmed' => (clone $base)->where('o.order_status', 'confirmed')->distinct()->count('v.vehicle_id'),
            'total_inspected' => (clone $base)->where('o.order_status', 'inspected')->distinct()->count('v.vehicle_id'),
            'total_delivered' => (clone $base)->whereIn('o.order_status', OrderStatus::completedValues())->distinct()->count('v.vehicle_id'),
        ];
    }

    private function enrichVehicles(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $owners = $this->ownersForVehicles($rows);
        $vehicleIds = $rows->pluck('vehicle_id')->unique()->values();
        $rawHistory = DB::table('leasyback_orders as o')
            ->leftJoin('leasyback_order_confirmations as c', 'c.auftragsnummer', '=', 'o.auftragsnummer')
            ->whereIn('o.vehicle_id', $vehicleIds)->orderByDesc('o.created_at')
            ->get([
                'o.vehicle_id', 'o.id', 'o.auftragsnummer', 'o.leasyback_partner',
                'o.order_status', 'o.sent_at', 'o.created_at', 'o.response_status', 'o.response_body',
                'c.confirmation_date',
            ]);
        // Whether "Dokumente abrufen" can do anything for this vehicle: it
        // needs a TÜV SÜD order whose response_body carries the
        // Gutachtennummer. The raw body is only read here and then dropped
        // below — it is a third-party payload, not something the frontend
        // should receive just to compute a boolean.
        $canPull = $rawHistory->groupBy('vehicle_id')->map(fn (Collection $items) => $items->contains(
            fn (object $item) => $item->leasyback_partner === 'tuvsud' && is_numeric(trim((string) $item->response_body, '"'))
        ));
        // Report/invoice documents (Admin-managed) attached per order, the
        // same reportDocuments() helper enrichOrders() already uses, keyed
        // identically — the Admin vehicle detail page's per-order document
        // management needs these alongside the order itself, not a
        // separate query.
        $reportDocs = $this->reportDocuments($rawHistory->pluck('auftragsnummer')->unique()->values());
        $history = $rawHistory->groupBy('vehicle_id')->map(fn (Collection $items) => $items->map(function (object $item) use ($reportDocs) {
            $key = $item->auftragsnummer.'|'.$item->vehicle_id;
            unset($item->vehicle_id, $item->response_body);
            $arr = (array) $item;
            $arr['report_documents'] = $reportDocs[$key] ?? [];

            return $arr;
        })->values()->all());
        $documents = DB::table('vehicle_documents')->whereIn('vehicle_id', $vehicleIds)
            ->orderByDesc('created_at')->get([
                'vehicle_id', 'document_id', 'document_category', 'document_type',
                'original_file_name', 'path', 'content_type', 'file_size',
                'uploaded_by_user_id', 'created_at',
            ])->groupBy('vehicle_id')->map(fn (Collection $items) => $items->map(function (object $item) {
                unset($item->vehicle_id);

                return (array) $item;
            })->values()->all());

        return $rows->map(function (object $row) use ($owners, $history, $documents, $canPull) {
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
                // For the list row's action menu. Pure enum lookup, no query,
                // so unlike the rest of the detail-only hydration this one is
                // free to compute for every row. Same exclusions as
                // orderDetail()'s available_transitions.
                'current_order_transitions' => $row->current_order_status === null ? [] : array_values(array_diff(
                    TransitionOrderStatus::allowedNextStatuses($row->current_order_status, $row->vehicle_belongs === 'B2B'),
                    ['order_placed', 'discarded'],
                )),
                // Drives the row menu's "Auftrag erstellen" / "Dokumente
                // abrufen" entries. has_open_order mirrors
                // VehicleService::hasUnfinishedOrder(), the rule
                // OrderService actually enforces on create.
                'has_open_order' => collect($history[$row->vehicle_id] ?? [])->contains(
                    fn (array $order) => ! in_array($order['order_status'], OrderStatus::closedValues(), true)
                ),
                'can_pull_documents' => (bool) ($canPull[$row->vehicle_id] ?? false),
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
