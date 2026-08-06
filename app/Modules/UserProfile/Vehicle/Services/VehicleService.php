<?php

namespace App\Modules\UserProfile\Vehicle\Services;

use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAuditLog;
use App\Models\VehicleDocument;
use App\Modules\PartnerApi\Services\PartnerWebhookEvents;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\Order\Models\LogisticsAddressProfile;
use App\Modules\UserProfile\Order\Services\B2bOfferService;
use App\Modules\UserProfile\Order\Services\B2bOrderNoteService;
use App\Modules\UserProfile\Order\Services\OrderCollectionService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VehicleService
{
    /**
     * Memoised per company, see companyAddressProfiles().
     *
     * @var array<string, Collection<int, LogisticsAddressProfile>>
     */
    private array $addressProfileCache = [];

    public function __construct(
        private readonly B2bContext $b2bContext,
        private readonly OrderCollectionService $orderCollectionService,
        private readonly B2bOfferService $b2bOfferService,
        private readonly B2bOrderNoteService $b2bOrderNoteService,
        private readonly VehicleScopeService $vehicleScopeService,
        private readonly PartnerWebhookEvents $webhooks,
    ) {}

    /**
     * Resolve the b2b_id of the company a user is currently acting as.
     *
     * Delegates to B2bContext because a user can belong to several companies;
     * taking the first `user_b2b` row, as this used to, would attribute a new
     * vehicle to an arbitrary one of them.
     */
    public function getB2bIdByUser(string $userId): ?string
    {
        return $this->b2bContext->activeCompanyIdForUserId($userId);
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
                ...$this->b2bFleetAttributes($validated, $belongs, $b2bId, $user),
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
                // Attribution, not ownership: the vehicle belongs to the
                // company, but a member restricted to their own vehicles is
                // matched on this, and the owner's per-member analytics and
                // dashboard filter both group by it.
                'created_by_user_id' => $user->id,
            ]);

            VehicleAuditLog::create([
                'vehicle_id' => $vehicle->vehicle_id,
                'action' => 'INSERT',
                'new_values' => $validated,
                'changed_by_user_id' => $user->id,
            ]);

            // In the transaction: a vehicle that rolls back must not be
            // announced. A B2C vehicle emits nothing — PartnerWebhookEvents
            // resolves no company for one, so there is no channel filter here.
            $this->webhooks->vehicleCreated($vehicle);

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

            $fleet = $this->b2bFleetAttributes($validated, $vehicle->vehicle_belongs, $vehicle->b2b_id, $user);
            $plain = Arr::except($validated, [...Vehicle::B2B_ONLY_ATTRIBUTES, 'collection_address']);

            $vehicle->update([...array_filter($plain, fn ($value) => $value !== null), ...$fleet]);

            VehicleAuditLog::create([
                'vehicle_id' => $vehicle->vehicle_id,
                'action' => 'UPDATE',
                'old_values' => $old,
                'new_values' => $validated,
                'changed_by_user_id' => $user->id,
            ]);

            $updated = $vehicle->fresh();

            $this->webhooks->vehicleUpdated($updated);

            return $updated;
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
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function b2bFleetAttributes(array $validated, ?string $belongs, ?string $b2bId, User $user): array
    {
        if ($belongs !== 'B2B' || $b2bId === null) {
            return [];
        }

        $attributes = [];

        foreach (['mileage', 'contract_number', 'cost_centre', 'driver_name', 'driver_contact'] as $field) {
            if (array_key_exists($field, $validated)) {
                $value = $validated[$field];
                $attributes[$field] = is_string($value) && trim($value) === '' ? null : $value;
            }
        }

        if (array_key_exists('collection_address', $validated)) {
            $attributes['collection_address_profile_id'] = $this->resolveCollectionAddressProfileId(
                $validated['collection_address'],
                $b2bId,
                $user,
            );
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>|null  $address
     */
    private function resolveCollectionAddressProfileId(?array $address, string $b2bId, User $user): ?string
    {
        $details = [];

        foreach (['street', 'number', 'additional_address', 'zip_code', 'city', 'country'] as $field) {
            $value = trim((string) ($address[$field] ?? ''));
            $details[$field] = $value === '' ? null : $value;
        }

        if (collect($details)->filter()->isEmpty()) {
            return null;
        }

        $profiles = $this->companyAddressProfiles($b2bId);

        $existing = $profiles->first(fn (LogisticsAddressProfile $profile) => $profile->details === $details);

        if ($existing !== null) {
            return $existing->id;
        }

        $label = trim(implode(', ', array_filter([
            trim(implode(' ', array_filter([$details['street'], $details['number']]))),
            trim(implode(' ', array_filter([$details['zip_code'], $details['city']]))),
        ])));

        $profile = LogisticsAddressProfile::create([
            'owner_type' => 'B2B',
            'b2b_id' => $b2bId,
            'profile_name' => $label !== '' ? $label : 'Abholadresse',
            'details' => $details,
            'is_default' => false,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);

        $profiles->push($profile);

        return $profile->id;
    }

    /**
     * The company's address profiles, read once per request.
     *
     * The dedupe comparison is unchanged — still an exact match on the whole
     * `details` array, in PHP — but it no longer re-reads every profile from
     * the database for each vehicle. That was invisible when creating one
     * vehicle at a time and quadratic on a bulk import, where a fleet list
     * typically repeats the same depot address on every row.
     *
     * Profiles created during the request are pushed onto the same collection,
     * so a later row still matches an address an earlier row just created and
     * no duplicate profile is written.
     *
     * @return Collection<int, LogisticsAddressProfile>
     */
    private function companyAddressProfiles(string $b2bId): Collection
    {
        return $this->addressProfileCache[$b2bId] ??= LogisticsAddressProfile::where('owner_type', 'B2B')
            ->where('b2b_id', $b2bId)
            ->get();
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?int} [belongs, b2b_id, b2c_user_id]
     */
    private function resolveOwnership(User $user, array $validated): array
    {
        // The context they are acting in, not the account type: a Privatkunde
        // who is also a company member registers company vehicles while acting
        // as that company, and private ones while acting as themselves.
        return match ($this->b2bContext->effectiveUserType($user)->value) {
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
            ->whereNotIn('order_status', OrderStatus::closedValues())
            ->exists();
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
    /**
     * The same per-vehicle shape listVehiclesWithOrders() returns, for one
     * vehicle — the detail page renders exactly what the dashboard row does.
     *
     * @return array<string, mixed>|null
     */
    public function findVehicleWithOrders(string $vehicleId, ?string $ownerId, string $belongs, ?User $viewer = null): ?array
    {
        return $this->listVehiclesWithOrders($ownerId, $belongs, [], $vehicleId, $viewer)[0] ?? null;
    }

    /**
     * One page of the dashboard list. Only the page's vehicles are hydrated,
     * so the payload and the seven child queries stay bounded regardless of
     * how many vehicles the customer owns.
     *
     * @param  array{search?: string, status?: string, sort?: string, direction?: string}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    public function paginateVehiclesWithOrders(?string $ownerId, string $belongs, array $filters = [], int $perPage = 10, int $page = 1, ?User $viewer = null): array
    {
        $paginator = $this->applyVehicleFilters($this->scopedVehicleQuery($ownerId, $belongs, $viewer), $filters)
            ->paginate(perPage: $perPage, page: $page);

        return [
            'data' => $this->hydrateVehicles(collect($paginator->items())),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    public function listVehiclesWithOrders(?string $ownerId, string $belongs, array $filters = [], ?string $vehicleId = null, ?User $viewer = null): array
    {
        $query = $this->scopedVehicleQuery($ownerId, $belongs, $viewer);

        if ($vehicleId !== null) {
            $query->where('v.vehicle_id', $vehicleId);
        }

        return $this->hydrateVehicles($this->applyVehicleFilters($query, $filters)->get());
    }

    /**
     * The company/owner half of vehicle access, plus the member-level half
     * when a viewer is supplied.
     *
     * The member-level narrowing is delegated to
     * VehicleScopeService::ownVehicleRestrictionFor(), the same decision
     * VehicleScopeService::scopeQuery() applies to policies and detail
     * lookups. Before phase 17's fix this method knew only about `b2b_id`, so
     * an own-scope member was listed the whole company fleet even though
     * opening any of it was correctly refused.
     *
     * `$viewer` is nullable because Admin-side callers legitimately have no
     * member scope to apply; a null viewer never widens the company filter
     * above, which is applied regardless.
     */
    private function scopedVehicleQuery(?string $ownerId, string $belongs, ?User $viewer = null): Builder
    {
        $query = DB::table('vehicles as v');

        if ($belongs === 'ALL') {
            // Admin sees all
        } elseif ($belongs === 'B2B') {
            $query->where('v.vehicle_belongs', 'B2B')->where('v.b2b_id', $ownerId);
        } else {
            $query->where('v.vehicle_belongs', 'B2C')->where('v.b2c_user_id', $ownerId);
        }

        $restrictedTo = $viewer === null ? null : $this->vehicleScopeService->ownVehicleRestrictionFor($viewer);

        if ($restrictedTo !== null) {
            $query->where('v.created_by_user_id', $restrictedTo);
        }

        return $query;
    }

    /**
     * @param  Collection<int, object>  $vehicles
     * @return list<array<string, mixed>>
     */
    private function hydrateVehicles(Collection $vehicles): array
    {
        if ($vehicles->isEmpty()) {
            return [];
        }

        $vehicleIds = $vehicles->pluck('vehicle_id')->all();

        // Cancelled orders are included: hiding them made a cancelled order
        // vanish from the customer's dashboard entirely — no timeline, no
        // documents, no explanation — while Admin still saw everything.
        // getCustomerOrderFlowSteps() already renders a cancelled order as a
        // full timeline with the cancellation marked at the stage it stopped,
        // so the customer now sees the same history Admin does.
        $ordersByVehicle = DB::table('leasyback_orders')
            ->whereIn('vehicle_id', $vehicleIds)
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
        // `rejected` is included so a B2B customer keeps seeing the offer they
        // turned down instead of it vanishing from the page. A B2C offer never
        // reaches that status, so this list is unchanged for B2C.
        $offersByOrder = DB::table('leasyback_offers')
            ->whereIn('order_id', $orderIds)
            ->whereIn('offer_status', ['published', 'selected', B2bOfferService::STATUS_REJECTED])
            ->orderBy('offer_sequence')
            ->get()
            ->groupBy('order_id');

        $offerPresentations = $this->b2bOfferService->forOffers(
            $offersByOrder->flatten(1)->pluck('offer_id')->all(),
        );

        $documentsByVehicle = DB::table('vehicle_documents')
            ->whereIn('vehicle_id', $vehicleIds)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('vehicle_id');

        $hasB2bVehicle = $vehicles->contains(fn ($vehicle) => $vehicle->vehicle_belongs === 'B2B');

        $orderCollections = $hasB2bVehicle
            ? $this->orderCollectionService->forOrders($auftragsnummern)
            : [];

        // Customer-visible notes only (§16). `forCustomerOrders()` applies the
        // visibility scope internally and takes no flag that could widen it,
        // so an internal note has no path into this payload.
        $orderNotes = $hasB2bVehicle
            ? $this->b2bOrderNoteService->forCustomerOrders($auftragsnummern)
            : [];

        $collectionAddresses = LogisticsAddressProfile::whereIn(
            'id',
            $vehicles->where('vehicle_belongs', 'B2B')->pluck('collection_address_profile_id')->filter()->unique()->all(),
        )->get()->mapWithKeys(fn (LogisticsAddressProfile $profile) => [$profile->id => $profile->details]);

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
                        // Who caused the change, as a coarse role only — the
                        // timeline uses it to tell the customer a cancellation
                        // came from Leasyback rather than from TÜV SÜD. The
                        // `updated_by` name/email stays Admin-only.
                        'auth_source' => $su->auth_source,
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

                $isB2bOffer = $vehicle->vehicle_belongs === 'B2B';

                $offers = $offersByOrder->get($order->id, collect())
                    ->map(fn ($offer) => [
                        'offer_id' => $offer->offer_id,
                        'offer_sequence' => $offer->offer_sequence,
                        'offer_status' => $offer->offer_status,
                        'repair_cost_net' => $offer->repair_cost_net,
                        'depreciation_value_net' => $offer->depreciation_value_net,
                        'workshop_repair_quote_net' => $offer->workshop_repair_quote_net,
                        'missing_parts_cost_net' => $offer->missing_parts_cost_net,
                        'final_total_net' => $offer->final_total_net,
                        // §9 forbids gross anywhere in the B2B quotation
                        // process, so a B2B payload simply has no gross keys.
                        ...($isB2bOffer ? [] : [
                            'repair_cost_gross' => $offer->repair_cost_gross,
                            'depreciation_value_gross' => $offer->depreciation_value_gross,
                            'workshop_repair_quote_gross' => $offer->workshop_repair_quote_gross,
                            'missing_parts_cost_gross' => $offer->missing_parts_cost_gross,
                            'final_total_gross' => $offer->final_total_gross,
                        ]),
                        ...($isB2bOffer ? ['presentation' => $offerPresentations[$offer->offer_id] ?? null] : []),
                        'additional_notes' => $offer->additional_notes,
                        'published_at' => $offer->published_at,
                        'selected_at' => $offer->selected_at,
                    ])
                    ->values()
                    ->toArray();

                $ordersArr[] = [
                    ...($vehicle->vehicle_belongs === 'B2B'
                        ? [
                            'collection' => $orderCollections[$order->auftragsnummer] ?? null,
                            'notes' => $orderNotes[$order->auftragsnummer] ?? [],
                        ]
                        : []),
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
                    'url' => $this->generateSignedUrl($doc->path, 1800),
                    'created_at' => $doc->created_at,
                ])
                ->values()
                ->toArray();

            $result[] = [
                ...($vehicle->vehicle_belongs === 'B2B' ? [
                    'mileage' => $vehicle->mileage,
                    'contract_number' => $vehicle->contract_number,
                    'cost_centre' => $vehicle->cost_centre,
                    'driver_name' => $vehicle->driver_name,
                    'driver_contact' => $vehicle->driver_contact,
                    'collection_address' => $collectionAddresses[$vehicle->collection_address_profile_id] ?? null,
                ] : []),
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
     * The current status lives on the vehicle's latest order, so it is
     * resolved as a correlated subquery and the whole thing is wrapped in a
     * derived table — neither MySQL nor SQLite allows filtering or ordering a
     * select alias directly in the same statement.
     *
     * Cancelled orders count here. Skipping them meant a vehicle whose only
     * order had been cancelled showed no status at all, and one that was
     * cancelled after an earlier delivered order still advertised
     * "delivered" — in both cases hiding the cancellation from its owner.
     *
     * @param  array{search?: string, status?: string, sort?: string, direction?: string}  $filters
     */
    private function applyVehicleFilters(Builder $query, array $filters): Builder
    {
        $latestStatus = DB::table('leasyback_orders as lo')
            ->select('lo.order_status')
            ->whereColumn('lo.vehicle_id', 'v.vehicle_id')
            ->orderByDesc('lo.created_at')
            ->limit(1);

        $query->select('v.*')->selectSub($latestStatus, 'current_order_status');

        // Owner-side "who added this?" filter. Applied on the inner query so
        // it narrows before the status subselect is wrapped; a member with a
        // restricted scope is already limited by VehicleScopeService, so this
        // can only ever narrow further, never widen.
        $createdBy = $filters['created_by'] ?? null;

        if ($createdBy !== null && $createdBy !== '') {
            $query->where('v.created_by_user_id', (int) $createdBy);
        }

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
        } elseif ($status === 'open') {
            // "Laufend" spans every status that is neither finished nor
            // abandoned, so it can't be expressed as a single status value —
            // it is the complement of the closed set. Mirrors
            // B2bAnalyticsService::vehicleStates()'s in_progress bucket, so
            // clicking that segment shows exactly the rows it counted.
            $outer->whereNotNull('f.current_order_status')
                ->whereNotIn('f.current_order_status', ['delivered', 'completed', 'cancelled', 'discarded']);
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
