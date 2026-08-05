# B2B Implementation Handoff

Working document for continuing the LeasyBack B2B leasing-return portal.
Branch: `feat/admin-chat`. Stack: Laravel 12 / PHP 8.4 / Inertia v2 / Vue 3 / Tailwind 3.

**Requirement source: `b2b.txt`** (repo root, 355 lines). It is the authoritative
specification — section numbers below (§n) refer to it. Where it conflicts with
existing code, the code path must be reported before business logic changes (§21).

---

## 1. Completed phases

All phases below are implemented, verified and **uncommitted** on `feat/admin-chat`.
Each was delivered under the standing constraints in §7 of this document.

### Phase 1 — Company master data & service fee (§4, §13)

| File | Change |
|---|---|
| `database/migrations/2026_08_05_000001_add_service_fee_to_b2b_table.php` | service fee + effective-from |
| `app/Modules/UserProfile/B2B/Models/B2B.php`, `Services/B2BService.php` | fee handling |
| `app/Http/Controllers/Admin/CustomerController.php` | admin editing |
| `resources/js/components/account/CompanyMasterDataCard.vue`, `pages/Admin/Customers/Show.vue`, `types/b2b.ts` | UI |

Default EUR 295, effective 2026-01-01 for existing companies.

### Phase 2 — B2B vehicle fields (§5)

| File | Change |
|---|---|
| `database/migrations/2026_08_05_000002_add_b2b_fleet_fields_to_vehicles_table.php` | `mileage`, `contract_number`, `cost_centre`, `driver_name`, `driver_contact`, `collection_address_profile_id` |
| `app/Modules/UserProfile/Vehicle/Models/Vehicle.php` | `B2B_ONLY_ATTRIBUTES` + `toArray()` strip |
| `Vehicle/Http/Requests/{Store,Update}VehicleRequest.php`, `Requests/Concerns/` | shared B2B rules |
| `Vehicle/Services/VehicleService.php` | persistence |
| `resources/js/components/vehicle/AddVehicleModal.vue`, `VehicleRow.vue`, `pages/Dashboard.vue`, `types/vehicle.ts` | UI |

B2B-only fields are stripped at **serialization** level, so a B2C payload can never
carry them even if a row somehow has values.

### Phase 3 — Collection appointment (§7)

| File | Change |
|---|---|
| `database/migrations/2026_08_05_000003_add_collection_appointment_to_order_logistics_table.php` | `requested_collection_date`, `confirmed_collection_date`, `internal_note` on `leasyback_order_logistics` |
| `app/Modules/UserProfile/Order/Services/OrderCollectionService.php` | **new** — the only writer/reader |
| `Order/Models/OrderLogistics.php` | fillable + date casts |
| `app/Http/Controllers/Admin/OrderController.php` | `updateCollection()`, 404s on non-B2B |
| `routes/admin.php` | `admin.orders.collection` |
| `resources/js/components/admin/AdminCollectionCard.vue`, `types/order.ts` | UI |

Address strategy: an unchanged address is **referenced** via `pickup_profile_id`
(details null); only a genuinely deviating address writes an order-specific
`pickup_details` snapshot. No new profile rows, no duplication.
`forOrders($nummern, $includeInternal)` returns `internal_note` only when
`$includeInternal` is true (Admin only).

### Phase 4 — B2B order creation split from TÜV SÜD (§6)

| File | Change |
|---|---|
| `Order/Services/OrderService.php` | `createB2bCollectionOrder()`, `approveB2bCollectionOrder()`, `isB2bCollectionOrder()`; B2B guards on `createTuvsudOrder()`/`createOtherOrder()` |
| `Order/Services/OrderCollectionService.php` | `b2bOrderRules()` (`prohibited` on `station_id`, `termin`, `provider`, `remarks`) |
| `app/Modules/UserProfile/vehicle_order_api_routes.php`, `Order/Http/Controllers/OrderController.php` | `POST order/b2b/create/{vehicleId}` |
| `resources/js/components/vehicle/OrderCreationModal.vue` | station block hidden for B2B |
| `tests/Feature/Api/OrderControllerTest.php`, `tests/Feature/Order/OrderAuditAndNotificationTest.php` | **updated** — two tests booked a B2B vehicle through the TÜV SÜD route; repointed to the B2B route, all assertions kept |

No migration: `request_payload` stays non-null and stores the honest minimal marker
`{"order_type":"b2b_collection"}`. Admin approval of a B2B order never calls TÜV SÜD.

### Phase 5 — B2B statuses and transition rules (§6)

| File | Change |
|---|---|
| `app/Enums/OrderStatus.php` | 6 new cases + `b2bOnlyValues()`, `b2cOnlyValues()`, `closedValues()`, `activeValues()` |
| `Order/Actions/TransitionOrderStatus.php` | `B2B_ALLOWED_TRANSITIONS`, `isB2bOrder()`, `guardChannel()`, `allowedNextStatuses($from, $isB2b)` |
| `app/Support/OrderStatusLabel.php` | German labels for all 6 |
| `Admin/Services/AdminQueryService.php`, `Vehicle/Services/VehicleService.php` | channel-aware transitions, counters, `hasUnfinishedOrder()` |
| `resources/js/lib/vehicleStatus.ts`, `adminStatus.ts`, `timeline.ts` | label/pill/filter maps |
| `tests/Feature/Admin/VehicleControllerTest.php` | **updated** — drift probe used `completed` as an invalid status; swapped to `not_a_status` |

One enum, one writer. Two transition maps inside the one action, because `confirmed`
forks by channel (B2C → `inspected`, B2B → `vehicle_collected`) and a single
from-status-keyed map cannot express both.

### Phase 6 — B2B customer-facing timeline (§15)

| File | Change |
|---|---|
| `resources/js/lib/customerOrderFlow.ts` | `B2bOrderStage`, `B2B_ORDER_STAGE_SEQUENCE`, labels/tooltips, `resolveB2bProgressIndex`, `b2bStageDate`, `b2bStageSubtitle`, `buildB2bStep`, `getB2bOrderFlowSteps`; `channel` input; `published?` on the doc type |
| `resources/js/components/vehicle/VehicleExpandedPanel.vue`, `pages/vehicles/Show.vue`, `pages/Admin/Orders/Show.vue` | pass `channel` (+ `collection`, doc `published`) |
| `resources/js/lib/timeline.ts` | `STATUS_CORE_INDEX` extended with the six B2B statuses |

`toOrderTimelineEntries` itself is stage-agnostic, so both channels share one renderer
and one `OrderStatusTimeline.vue` — but `timeline.ts` **was** edited: its
`STATUS_CORE_INDEX` map needed entries for the new statuses (`vehicle_collected`→0,
`workshop_commissioned`→1, `repair_completed`→2, `vehicle_returned`→3,
`invoice_processed`→3) or a B2B order would have collapsed to the wrong core step.
*(Corrected 2026-08-05: an earlier revision of this document claimed timeline.ts needed
no change.)*

### Phase 7 — OrderTaskResolver (§14)

| File | Change |
|---|---|
| `Order/Services/OrderTaskResolver.php` | **new** — derived admin task queue |
| `Admin/Services/AdminQueryService.php` | resolver injected; `$order['tasks']` in `orderDetail()` |
| `resources/js/components/admin/AdminOrderTasksCard.vue` | **new** |
| `resources/js/pages/Admin/Orders/Show.vue` | card + `id="order-section-…"` anchors |
| `resources/js/types/admin.ts` | `AdminOrderTask*` types |
| `resources/css/app.css` | `.task-target` jump highlight |

### Phase 7.1 — Dashboard status-count correction (2026-08-05)

A gap found while auditing phase 5, not a new feature. `AdminQueryService::summary()`
had a hardcoded `active_orders` status list that was never extended when the six B2B
statuses were added, while its `delivered_orders` list *was* extended. A B2B order in
`vehicle_collected`, `workshop_commissioned`, `repair_completed`, `vehicle_returned` or
`invoice_processed` therefore counted in **neither** bucket, and the summary tile
disagreed with `orderCounts()`/`vehicleCounts()`, which already used
`OrderStatus::activeValues()`.

| File | Change |
|---|---|
| `app/Enums/OrderStatus.php` | **new** `completedValues()` (`delivered`, `completed`); `closedValues()` now spreads it instead of repeating both |
| `app/Modules/UserProfile/Admin/Services/AdminQueryService.php` | `summary()` derives both lists from the enum via bound `?` placeholders (new private `bindingPlaceholders()`); the three remaining `['delivered','completed']` literals in `orderCounts()`/`vehicleCounts()` replaced with `OrderStatus::completedValues()` |

No migration. Four copies of the successful-terminal list collapsed to one enum accessor.

**Business-flow change:** none. Only reporting counts changed.

**B2C protection:** `activeValues()` is a strict superset of the old hardcoded list —
verified `array_diff(old, new) === []`, the only additions being the five B2B statuses.
`delivered_orders` keeps `delivered`+`completed` and deliberately does **not** use
`closedValues()`, which would have folded `cancelled`/`discarded` into a
"delivered" tile. `pending_inspections` keeps its own literal
`('order_placed','confirmed')` pair: it is the B2C awaiting-inspection stat, not an
active/closed partition, so it was left alone. Response keys unchanged.

**Verified** with a rolled-back `DB::beginTransaction()` probe inserting one order per
status: active +5, delivered +2 (`completed`+`delivered`), `cancelled` counted in
neither, `pending_inspections` delta 0, key list byte-identical.

### Phase 8 — Appraisal repair positions (§8)

| File | Change |
|---|---|
| `database/migrations/2026_08_05_000004_create_b2b_appraisal_positions_table.php` | **new** table |
| `app/Modules/UserProfile/Order/Models/AppraisalPosition.php` | **new** model (+ `app/Models/AppraisalPosition.php` re-export, matching the existing alias convention) |
| `app/Modules/UserProfile/Order/Services/AppraisalPositionService.php` | **new** — the only reader/writer |
| `app/Http/Controllers/Admin/AppraisalPositionController.php` | **new** — `update()`, 404s on non-B2B |
| `routes/admin.php` | `PUT admin.orders.appraisal-positions` |
| `app/Modules/UserProfile/Admin/Services/AdminQueryService.php` | service injected; `appraisal_positions` + `appraisal_totals` on `orderDetail()` |
| `resources/js/components/admin/AdminAppraisalPositionsCard.vue` | **new** correction UI |
| `resources/js/pages/Admin/Orders/Show.vue` | card mounted under the documents card |
| `resources/js/types/admin.ts` | `AdminAppraisalPosition`, `AdminAppraisalTotals`, two fields on `AdminOrderDetail` |

**Schema decisions.** A new table was unavoidable: `leasyback_offers` is a flat
one-row-per-offer aggregate with no line items (and gross columns), while
`assessment_documents` / `vehicle_report_documents` store files only. Nothing existing
could hold a position.

```
b2b_appraisal_positions
  id uuid pk | order_id uuid → leasyback_orders.id (cascade) | auftragsnummer text idx
  sort_order | component | damage_description
  original_amount_net decimal(10,2) | chargeable_amount_net decimal(10,2) nullable
  repair_method | source ('manual'|'extracted') | damage_image_document_ids json
  created_by_user_id | updated_by_user_id | timestampsTz
```

- **Net only.** No gross column exists, so §9 cannot be violated by a later renderer.
- **`chargeable_amount_net` is nullable** and means "same as the appraisal amount";
  `effectiveAmountNet()` resolves the fallback in one place so callers never re-derive it.
- **Initial appraisal only.** Nachgutachten positions are deliberately out of scope, so
  §17's "final appraisal excluded from savings" is structural rather than a query filter.
- **`damage_image_document_ids` is a JSON array of `vehicle_report_documents.id`**, not a
  pivot table — images are optional and this avoids a second table for a list. The
  trade-off is no FK: membership is enforced at validation time via `Rule::in` over the
  order's own documents, so a document from another order is rejected (verified).
- **`source`** is always `'manual'` today; it exists so a future extractor can mark its
  rows and the UI can distinguish extracted-then-corrected values.

**Business flow.** Admin submits the whole set at once (`PUT`); the service reconciles
against what is stored inside one transaction — rows matched by `id` are updated and
**keep their id**, new rows are inserted, omitted rows are deleted. An empty array is
legal and clears the set. Statuses, the transition graph, the timeline, offers and
billing were not touched.

**Extraction status — everything is manual.** `AppraisalDocumentPullService` is
hard-filtered to `leasyback_partner = 'tuvsud'`; a B2B collection order is
`'leasyback'`, so the TÜV SÜD pull can never run for one. Independently the TIM ingest
stores only documents — `assessment_documents` has no amount, component or position
column at all. There is nothing to extract and therefore nothing to auto-correct; the
card is a manual entry + correction surface, as the phase brief permitted.

**B2C protections.**
1. `AppraisalPositionController::update()` 404s unless the order's **persisted** vehicle
   is `vehicle_belongs === 'B2B'` — the channel is never read from the request.
2. `AppraisalPositionService::sync()` returns early for a non-B2B vehicle, so even a
   direct service call cannot write positions onto a B2C order (verified).
3. `orderDetail()` sets both `appraisal_positions` and `appraisal_totals` to `null` for
   B2C, so the keys carry no data and the Vue card does not render.
4. Positions are attached to the **Admin** payload only. No customer/B2C response,
   export or email reads this table in this phase.

**Verified** with a rolled-back probe: 2 positions created (totals 690.50 / 530.50 with
the null-chargeable fallback resolving to 210.50); resync preserved the kept row's id,
applied the correction, deleted the omitted row and inserted the new one (totals
630.00 / 450.00); B2B `orderDetail()` returned both keys populated; flipping the vehicle
to B2C returned `null` for both and made `sync()` a no-op. Validation probe: foreign
document id rejected, own id accepted, missing `component` rejected, negative amount
rejected, empty set accepted.

**Placeholders / not done in this phase:** no PDF extraction, no per-position workshop
quotation link (Phase 9), no customer-facing presentation of positions (Phase 10), no
saving statistics (Phase 14).

---

## 2. Current B2B workflow and status graph

Single table `leasyback_orders`. For every order and status decision the channel is
resolved from the persisted `vehicles.vehicle_belongs`, never from request input — see
§7 for the one documented exception, which is vehicle *creation* validation only and
never reaches order or status logic.

```
order_requested → order_placed → confirmed → vehicle_collected → inspected
  → workshop_commissioned → workshop → repair_completed → reinspection
  → vehicle_returned → invoice_processed → completed
```

Every state except `invoice_processed` and `completed` may also go to `cancelled`;
`order_requested` may additionally go to `discarded`.

Defined in `TransitionOrderStatus::B2B_ALLOWED_TRANSITIONS`. B2C keeps its own map,
unchanged. `guardChannel()` rejects `b2bOnlyValues()` on a B2C order and
`b2cOnlyValues()` (`delivered`, `reworkshop`) on a B2B order, inside the locked
transaction, before the idempotent same-status short-circuit.

Order creation: `POST order/b2b/create/{vehicleId}` requires
`requested_collection_date`, `collection_address.{street,zip_code,city}`, optional
`collection_note`; rejects `station_id`/`termin`/`provider`/`remarks` outright.
Orders land in `order_requested` for Admin review.

---

## 3. 15-stage timeline mapping

One implementation, `getCustomerOrderFlowSteps(ctx)`; `ctx.channel === 'B2B'`
delegates to `getB2bOrderFlowSteps`. Admin and customer call it with the same inputs.

| # | German label | Source | Kind |
|---|---|---|---|
| 1 | Auftrag eingegangen | `order.created_at` | status |
| 2 | Abholtermin angefragt | `order_requested`/`order_placed` + `requested_collection_date` | status + appointment |
| 3 | Abholung terminiert | `confirmed` + `confirmed_collection_date` + address | status + appointment |
| 4 | Fahrzeug abgeholt | `vehicle_collected` | status |
| 5 | Erstgutachten verfügbar | `inspected`, dated from the `gutachten` doc | status + document |
| 6 | Werkstattangebote in Vorbereitung | `inspected` **and no published offer** | offer-derived |
| 7 | Freigabe erforderlich | offer `published`, dated `published_at` | offer-derived |
| 8 | Reparatur freigegeben | offer `selected`, dated `selected_at` | offer-derived |
| 9 | Werkstatt beauftragt | `workshop_commissioned` | status |
| 10 | Fahrzeug in Reparatur | `workshop` | status |
| 11 | Reparatur abgeschlossen | `repair_completed` | status |
| 12 | Nachgutachten abgeschlossen | `reinspection`, dated from the `nachgutachten` doc | status + document |
| 13 | Fahrzeug an Leasinggeber übergeben | `vehicle_returned` | status |
| 14 | Abrechnung abgeschlossen | `invoice_processed` + `rechnung` link | status + document |
| 15 | Auftrag abgeschlossen | `completed` | status |

- `datetime` carries the real status-history timestamp; **business dates are separate**,
  rendered as labelled subtitle lines (`Wunschtermin:` / `Bestätigter Abholtermin:`)
  via `whitespace-pre-line`.
- Action prompts ("Bitte geben Sie ein Angebot Ihrer Wahl frei") are gated on
  `isCurrent` so they never appear on completed or future stages.
- `internal_note` is never read by timeline code.
- Admin/customer parity: report documents are filtered on `published !== false`
  (undefined in the customer payload, `false` for Admin drafts). B2B branch only.

---

## 4. OrderTaskResolver behavior

`OrderTaskResolver::forOrderDetail(array $order): ?array` — returns `null` unless
`vehicle_belongs === 'B2B'`. Nothing is persisted; no task table, no task state.

Returns `{next, history, is_closed, closed_status}`. The 18 definitions form one
ordered decision tree keyed by a linear status `rank` (0 `order_requested` …
11 `completed`). The **first** definition whose `open` predicate holds becomes `next`
— a single object, never a list. Satisfied definitions become compact `history`
(`key`, `title`, `date`, `section`, `state: 'done'`). Anything neither done nor due is
omitted.

`next` fields: `key`, `title` (German), `description`, `state` (`open` | `waiting`),
`date` + `date_label`, `section`, `action`.
`action` is `{method, url, payload, label}` — `POST admin.orders.approve`,
`PATCH admin.orders.status` (10 status moves), `PATCH admin.orders.collection`.
Every emitted target status is a legal edge in `B2B_ALLOWED_TRANSITIONS`.

Anchors: `#order-section-{abholung|angebote|dokumente|status}`, defined as constants
on the resolver and as `id` attributes in `pages/Admin/Orders/Show.vue`.

Task keys in order:
`confirm_collection`, `release_order`, `confirm_order`, `mark_vehicle_collected`,
`upload_initial_appraisal`, `complete_initial_appraisal`, `request_workshop_quotations`,
`prepare_customer_offer`, `await_customer_approval` *(waiting)*, `commission_workshop`,
`enter_repair_appointment`, `monitor_repair` *(waiting)*, `upload_final_appraisal`,
`complete_final_appraisal`, `confirm_vehicle_returned`, `prepare_invoice`,
`mark_invoice_processed`, `complete_order`.

Five of those (`release_order`, `confirm_order`, `complete_initial_appraisal`,
`complete_final_appraisal`, `mark_invoice_processed`) are not in the spec's §14 list;
they exist because the status graph needs them and the queue would otherwise go silent.

**Duplicate/stale prevention:** `open` is forced to `open && !done`, and a task whose
phase the order has already passed is `done` by rank regardless of its own data
condition. Cancellation/completion sets `is_closed` and forces `next` to null, which
removes all future open tasks. A cancelled order keeps the phase it reached (recovered
from the last non-terminal `old_status`) so its history stays readable.

### Placeholders — entity does not exist yet

| Task | Currently derived from | Real requirement |
|---|---|---|
| `request_workshop_quotations` | appraisal published, zero offers | §9 workshop quotation entity + secure links |
| `enter_repair_appointment` | reduced to the `workshop` status move | §11 confirmed workshop appointment date |
| `prepare_invoice` | published `rechnung` document | §13 Lexware invoice draft |
| `await_customer_approval` | published offer, none selected | §10 explicit customer **reject** state (offers have no `rejected`) |

---

## 5. B2C protections that must remain unchanged

1. `getCustomerOrderFlowSteps()` — B2C still renders exactly 8 stages with labels
   `Wunschtermin angefragt | Wunschtermin bestätigt | Erstbegutachtung abgeschlossen |
   Reparaturangebote zur Freigabe | Angebotsfreigabe erteilt | In Reparaturphase |
   Nachgutachten abgeschlossen | Fahrzeug abholbereit`.
   *(Corrected 2026-08-05: an earlier revision claimed everything below
   `if (ctx.channel === 'B2B')` was byte-unchanged. It is not.)* The **shared** helpers
   below that branch were edited and are used by both channels:
   - `resolveProgressIndex` — added `completed`→7 and `CLOSING_STATUSES`
     (`vehicle_returned`, `invoice_processed`)→6; `vehicle_collected` folded in with
     `confirmed`→1; `REPAIR_PHASE_STATUSES` gained `workshop_commissioned` and
     `repair_completed`
   - `getStageDate` — `followup_completed` also searches `CLOSING_STATUSES`
   - `buildStep` — `requested` and `appointment_confirmed` now prefer `ctx.collection`
     when present, falling back to the original `besichtigungsort`/`termin` wording
   - `FINISHED_ORDER_STATUSES` gained `completed`

   B2C **behaviour** is nevertheless unchanged: a B2C order can never hold any of the
   added statuses (`guardChannel()` rejects them) and never receives a `collection`
   object (`VehicleService` only attaches it for B2B vehicles), so every added branch is
   unreachable on a B2C payload. Treat these helpers as shared, not B2C-private, when
   editing them further.
2. `TransitionOrderStatus::ALLOWED_TRANSITIONS` (the B2C map) is untouched;
   `delivered` and `reworkshop` remain B2C-only terminals.
3. The TÜV SÜD **request/response contract** is untouched and B2B never reaches the
   external call. *(Corrected 2026-08-05: the earlier "the path is untouched" wording was
   wrong — the methods themselves were edited.)* What changed inside them:
   - `createTuvsudOrder()` / `createOtherOrder()` open with a `vehicle_belongs === 'B2B'`
     rejection guard (422)
   - all three creation transactions call
     `OrderCollectionService::recordCustomerRequest()`, which returns early for non-B2B
     vehicles, so it is a no-op on the B2C path
   - the B2C validation rule sets merge `OrderCollectionService::customerRules(false)`,
     which adds `prohibited` rules on `requested_collection_date` / `collection_address` /
     `collection_note`

   No payload a legitimate B2C client sends is affected; the TÜV SÜD booking body,
   `approveOrder()`'s external call and the response shapes are all as before.
4. `EnsureB2bPermission` waves non-Firmenkunde user types (Privatkunde, Werkstatt,
   Admin) straight through — this is what keeps B2C unaffected by B2B permissions.
5. `Vehicle::B2B_ONLY_ATTRIBUTES` are stripped from every B2C serialization.
6. `OrderCollectionService` write paths return early unless the vehicle is B2B, and
   `admin.orders.collection` 404s on a non-B2B order.
7. `OrderTaskResolver` returns `null` for B2C, so `tasks` is absent from every
   B2C payload and the Admin card does not render.
8. The Admin report-document `published` filter applies only in the B2B builder; the
   pre-existing B2C draft-document behaviour was deliberately left as it was.

---

## 6. Baseline test failures (pre-existing, not caused by this work)

`php artisan test --compact` → **406 passed, 4 skipped, 2 failed (1603 assertions)**

1. `Tests\Unit\SendGridMailTransportTest > sendgrid api key comes from the environment not a hardcoded default`
   — `assertSame(env('SENDGRID_API_KEY'), config('mail.mailers.sendgrid.password'))`; the
   config resolves to a literal `SG.…` key locally.
2. `Tests\Feature\HandleInertiaRequestsTest > shared auth user prop exposes only the intended fields`
   — *Not a valid Inertia response* (`AssertableInertia.php:84`).

Confirmed failing on a clean stashed baseline (`git stash push -- app resources routes database`).
**Do not treat these as regressions.** Any third failure is yours.

Re-verified 2026-08-05 both before and after the phase 7.1 correction: identical
counts (406/4/2, 1603 assertions), same two tests, no third failure.

### Verification results — phase 7.1

| Check | Result |
|---|---|
| `php artisan test --compact` | 406 passed, 4 skipped, **2 failed** (the two above), 1603 assertions |
| `php artisan test --compact tests/Feature/Api/Admin/AdminControllerTest.php tests/Feature/Admin/DashboardControllerTest.php` | 13 passed (36 assertions) |
| `vendor/bin/pint --dirty --format agent` | `passed` |
| `npm run build` | `✓ built in 29.98s`, exit 0 (run as a regression check; phase 7.1 touched no frontend file) |
| `npx eslint` | not run — phase 7.1 is PHP-only, no frontend file changed |

### Verification results — phase 8

| Check | Result |
|---|---|
| `php artisan test --compact` | 406 passed, 4 skipped, **2 failed** (the two baseline ones), 1603 assertions |
| `php artisan migrate` | `2026_08_05_000004_create_b2b_appraisal_positions_table` DONE (batch 11) |
| `vendor/bin/pint --dirty --format agent` | `passed` |
| `npm run build` | `✓ built in 20.88s`, exit 0 |
| `npx eslint <3 changed files>` | clean, no output |
| `php artisan route:list --path=admin/orders` | `PUT admin/orders/{orderId}/appraisal-positions` registered |

No test was added: per the standing brief, tests are added only when existing
expectations conflict, and none did. Behaviour was proven with rolled-back
`DB::beginTransaction()` probes (see phase 8 above) — **this is the weakest part of the
phase's evidence.** If tests are ever allowed for B2B, the highest-value first cases
are: the controller 404 on a B2C order, the id-preserving resync, and the cross-order
`damage_image_document_ids` rejection.

## 6a. Current working-tree state (after phase 8, 2026-08-05)

Branch `feat/admin-chat`. Everything below is **uncommitted and unstaged** —
`git diff --cached` is empty. `git status --porcelain` → **41 modified, 16 untracked**.
All four migrations are applied locally (`migrate:status` batches 8, 9, 10, 11).

Untracked (new):

```
B2B_IMPLEMENTATION_HANDOFF.md      b2b.txt
app/Http/Controllers/Admin/AppraisalPositionController.php
app/Models/AppraisalPosition.php
app/Modules/UserProfile/Order/Models/AppraisalPosition.php
app/Modules/UserProfile/Order/Services/AppraisalPositionService.php
app/Modules/UserProfile/Order/Services/OrderCollectionService.php
app/Modules/UserProfile/Order/Services/OrderTaskResolver.php
app/Modules/UserProfile/Vehicle/Http/Requests/Concerns/ValidatesB2bVehicleFields.php
database/migrations/2026_08_05_000001_add_service_fee_to_b2b_table.php
database/migrations/2026_08_05_000002_add_b2b_fleet_fields_to_vehicles_table.php
database/migrations/2026_08_05_000003_add_collection_appointment_to_order_logistics_table.php
database/migrations/2026_08_05_000004_create_b2b_appraisal_positions_table.php
resources/js/components/admin/AdminAppraisalPositionsCard.vue
resources/js/components/admin/AdminCollectionCard.vue
resources/js/components/admin/AdminOrderTasksCard.vue
```

Modified: 17 PHP files (`app/Enums/OrderStatus.php`, `app/Support/OrderStatusLabel.php`,
2 Admin controllers, `app/Http/Controllers/OrderController.php`, `AdminQueryService`,
`B2B` model + service, `TransitionOrderStatus`, module `OrderController`,
`OrderLogistics`, `OrderService`, `Store`/`UpdateVehicleRequest`, `Vehicle`,
`VehicleService`, `vehicle_order_api_routes.php`), `routes/admin.php`,
20 frontend files, and 3 test files (`Admin/VehicleControllerTest`,
`Api/OrderControllerTest`, `Order/OrderAuditAndNotificationTest`).

Nothing has been committed across phases 1–8. That is a growing risk: the branch now
carries four migrations and a full B2B subsystem in one unstaged blob.

---

## 7. Architectural rules (non-negotiable)

- One central order (`leasyback_orders`). No separate B2B orders table.
- One `OrderStatus` enum. One status writer: `TransitionOrderStatus` — preserves
  `lockForUpdate()`, audit history, idempotent same-status no-op, after-commit dispatch.
- One timeline implementation. One task system. No second copies (§21).
- Channel is read from the **persisted** vehicle/order, never from frontend input.
  One documented exception: `StoreVehicleRequest::isB2bContext()` reads
  `$this->input('vehicle_belongs')` when the caller is an **Admin**, because vehicle
  *creation* has no persisted row to read yet. A `Firmenkunde` caller is B2B by user
  type regardless of input, `UpdateVehicleRequest` reads the persisted row, and the
  value only selects which validation rule set applies — `VehicleService` still derives
  the stored `vehicle_belongs` itself. Do not copy this pattern anywhere a persisted
  row exists.
- Enforce separation in backend validation and services, not only in Vue.
- Reuse existing logistics tables, the existing offer service/tables and
  `vehicle_report_documents`. Do not create appointment or task tables. Phase 8 added
  `b2b_appraisal_positions` — the one genuinely new entity so far, because no existing
  table could hold a line item. A new table needs that level of justification.
- `internal_note` must never reach a customer API, email or export (§16).
- No gross prices anywhere in the B2B quotation process (§9).
- An order must not complete before mandatory billing (§13, §21).
- Company-level data isolation via `B2bContext` + `EnsureB2bPermission` (`b2b.can:*`)
  + `VehicleScopeService::scopeQuery()`. Every permission enforced server-side.
- Do not add dependencies, do not modify unrelated files, do not add tests unless
  existing expectations directly conflict with an approved change.
- Follow `CLAUDE.md`: Pint on every PHP change, PHPDoc over inline comments,
  `php artisan make:` for new files, named routes.
- **Updating this document is part of every phase, not a follow-up.** A phase may not be
  declared complete until §1 carries its files, migrations and schema decisions,
  business-flow changes, B2C protections, placeholders and unresolved decisions; §8 and
  §9 are re-pointed; the verification results (test / build / Pint / ESLint) are
  recorded; and any statement here found to be inaccurate is corrected in place with a
  dated note rather than silently rewritten.

### Dead code noted, deliberately untouched

`resources/js/lib/orderFlow.ts` (`getOrderFlowSteps`, `isTerminalOffRamp`,
`getOffRampLabel`, `TIMELINE_STAGES`) is a legacy 6-step flow referenced only by the
generated `auto-imports.d.ts`. Cleanup candidate, out of scope so far.

---

## 8. Remaining phases, recommended order

Phases 1–8 plus the 7.1 correction pass are done. **Phase 9 has not been started** — no
quotation entity, workshop link or public workshop route exists yet.

| # | Phase | Spec | Why here |
|---|---|---|---|
| 9 | **Workshop quotation process** — quotation entity, revocable time-limited public workshop links, itemized **net** submissions, admin comparison view | §9 | Replaces the first resolver placeholder; positions from phase 8 are the line items a workshop prices |
| 10 | **Customer offer presentation** — offer view with appraisal + positions + saving, accept/**reject**/comment, offer validity | §10 | Needs 9; phase 8 positions are Admin-only until then |
| 11 | **Repair appointment** — confirmed workshop appointment date, timeline entry, auto-complete the task | §11 | Replaces the second placeholder |
| 12 | **Billing & Lexware** — invoice draft, service fee injection, completion gate | §13 | Replaces the third placeholder; §21 forbids completing without it |
| 13 | **Notifications** — B2B status emails, offer-available, 24 h reminders that stop on accept/reject/cancel/expiry, async + retry-safe | §18 | Needs stable offer states from 10 |
| 14 | **Statistics & Excel export** — company-scoped, saving = chargeable appraisal − accepted repair, final appraisal excluded | §17 | Needs amounts from 8–10 |
| 15 | **Excel vehicle import** — per-row validation, keep valid rows | §5 | Independent, can move earlier |
| 16 | **Notes** — internal vs customer-visible note types, explicit marking before save | §16 | Independent |
| 17 | **Acceptance audit** — role matrix, cross-company isolation, audit-log coverage against §20 | §19, §20 | Final gate |

---

## 9. Exact next phase

**Phase 9 — Workshop quotation process (§9).** The `b2b_appraisal_positions` rows from
phase 8 are the line items a workshop prices, so the comparison view §9 asks for
(appraisal amount vs workshop amount vs difference, per position) is now expressible.
Replaces the `request_workshop_quotations` resolver placeholder.

Ready-to-use prompt:

```
Proceed with the next B2B-only implementation phase: workshop quotation process (b2b.txt §9).

Read B2B_IMPLEMENTATION_HANDOFF.md first, then inspect: b2b_appraisal_positions +
AppraisalPositionService, leasyback_offers + OfferService, OrderTaskResolver
(request_workshop_quotations / prepare_customer_offer), routes/admin.php, routes/web.php
and how signed/public routes are handled elsewhere in this app.

Requirements:
- A workshop quotation entity belonging to the central order, with itemized amounts that
  reference the existing appraisal positions. Do not duplicate the positions.
- Workshops have no portal account: a time-limited, revocable public link per workshop.
- A quotation captures company name, contact person, email, phone, earliest repair start,
  estimated processing time in working days, repair method per position, a net amount per
  position, a total net amount, and a "cannot repair for this amount" indication.
- NET AMOUNTS ONLY. No gross column, no gross rendering, anywhere.
- Admin can expand and compare quotations: position, appraisal amount, workshop amount,
  difference, repair method, total, duration.
- Submitted quotations stay visible to Admin after one is presented or accepted.
- Public endpoints must be rate limited, tokens single-purpose, revocable and expiring;
  generic error messages, no internal details leaked.
- Keep B2C completely unchanged; quotations must never appear in a B2C payload.
- Determine the channel from the persisted vehicle/order, never from frontend input.
- Do not touch statuses, the transition graph, the timeline, billing or notifications.
  Do not implement the customer-facing offer view — that is phase 10.
- Do not add comments. Do not add tests; update existing tests only when old
  expectations directly conflict. Do not add dependencies. Do not modify unrelated files.

Before implementation, report:
1. The quotation data model and how it references appraisal positions
2. The public-link mechanism chosen (signed URL vs token column) and its TTL/revocation
3. How quotations are scoped to B2B only and how the public route is isolated
4. Which Admin section owns the quotation list and the comparison view
5. Any §9 requirement that conflicts with the existing offer tables — report before changing

After implementation, report: Files changed; Data model; Workshop submission flow;
Admin comparison flow; Security of the public link; How B2C was protected; How to verify.

Update B2B_IMPLEMENTATION_HANDOFF.md as part of this task, before declaring the phase
complete: add phase 9 to section 1 with its files, migrations and schema decisions,
business-flow changes, B2C protections, placeholders and unresolved decisions; re-point
sections 8 and 9 to phase 10; record test, build, Pint and ESLint results; refresh the
working-tree state in 6a; and correct in place, with a dated note, any statement in the
document you find to be inaccurate.
```

---

## 10. Unresolved product decisions

1. **Offer rejection.** `leasyback_offers.offer_status` is
   `draft|published|selected|closed|cancelled` — there is no customer *reject*.
   §10 requires accept **and** reject plus a comment. Needs a new state and a
   decision on what a rejection does to the order status.
2. **`discarded`.** No endpoint exercises it; the Admin reject action was never
   confirmed as wanted (`docs/B2C_ADMIN_IMPLEMENTATION_PLAN.md` §13).
3. **Completion gate.** §13/§21 forbid completing before billing, but "mandatory
   billing step processed" is not yet defined — published `rechnung` document,
   Lexware draft transmitted, or both?
4. **Repair appointment shape.** §11 says "confirmed repair appointment"; unclear
   whether that is a single date, a date range, or start + estimated working days
   (§9 collects "estimated total processing time in working days").
5. **Workshop link lifetime and revocation UX** — §9/§19 require time-limited and
   revocable; no TTL or revocation surface agreed.
6. **Saving statistics denominator** — §17 defines saving per vehicle, but not how
   partially-approved or cancelled orders count.
7. **Service fee in offers** — §13 says it must not appear in the repair offer before
   final billing; where exactly it surfaces at billing time is undefined.
8. **Leasing-end alerts** — §18 optional per-vehicle alerts with configurable lead
   time; not designed.

Opened by phase 8:

9. **Are positions editable after an offer is published or accepted?** Today they are
   always editable, with no lock and no versioning. §10 requires Admin to be able to see
   *exactly what was presented to the customer* — once phase 10 shows positions to the
   customer, editing them after publication would silently rewrite that record. Needs
   either a lock at publication or a snapshot taken when the offer is published.
10. **Damage images have no referential integrity.** `damage_image_document_ids` is a
    JSON array of `vehicle_report_documents.id`, validated on write but not FK-enforced,
    so deleting a report document leaves a dangling id in any position that referenced
    it. Acceptable while the list is Admin-only; revisit if positions become
    customer-visible in phase 10. The alternative is a pivot table.
11. **Nachgutachten positions.** `b2b_appraisal_positions` deliberately holds initial
    appraisal positions only. If the final appraisal ever needs itemization, decide
    whether it gets a `kind` discriminator on this table or its own — and keep §17's
    "final appraisal excluded from savings" intact either way.
12. **`source` semantics.** The column is always `'manual'` today. If a PDF extractor is
    added, decide whether an admin edit flips an `'extracted'` row to `'manual'`, or
    whether a separate `corrected_at` marker is needed to keep "what did TÜV SÜD
    originally say" answerable.

---

## 11. Commands

```bash
# migrations (SQLite locally, Postgres in production)
php artisan migrate
php artisan migrate:status

# formatting — required by CLAUDE.md after any PHP change
vendor/bin/pint --dirty --format agent

# frontend
npm run build              # verification signal; vue-tsc has 2 pre-existing config errors
npm run dev                # or: composer run dev
npx eslint resources/js/<changed files>

# tests
php artisan test --compact                       # expect 2 pre-existing failures
php artisan test --compact --filter=testName
php artisan test --compact tests/Feature/Admin/VehicleControllerTest.php

# inspection
php artisan route:list --path=admin/orders
php artisan config:show database.default
```

Verification technique used throughout: rolled-back
`DB::beginTransaction()` / `DB::rollBack()` tinker scripts with `Http::fake()` /
`Mail::fake()` for backend flows, and esbuild-bundled TypeScript probes run under
node for frontend logic. Keep probe files in the session scratchpad, never in the repo.
