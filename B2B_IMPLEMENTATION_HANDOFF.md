# B2B Implementation Handoff

Working document for continuing the LeasyBack B2B leasing-return portal.
Branch: `feat/b2b-flow` *(renamed from `feat/admin-chat` during phase 14 — verify with
`git rev-parse --abbrev-ref HEAD` rather than trusting this line)*.
Stack: Laravel 12 / PHP 8.4 / Inertia v2 / Vue 3 / Tailwind 3.

**Requirement source: `b2b.txt`** (repo root, 355 lines). It is the authoritative
specification — section numbers below (§n) refer to it. Where it conflicts with
existing code, the code path must be reported before business logic changes (§21).

---

## 1. Completed phases

Phases 9–14 are implemented, verified and **uncommitted**; phases 1–8 are in commit
`d79a473` (see §6a). Each was delivered under the standing constraints in §7 of this
document.

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

### Phase 9 — Workshop quotation process (§9)

| File | Change |
|---|---|
| `database/migrations/2026_08_05_000005_create_b2b_workshop_quotation_tables.php` | **new** — two tables |
| `app/Modules/UserProfile/Order/Models/WorkshopQuotation.php`, `WorkshopQuotationItem.php` | **new** (+ two `app/Models` re-exports) |
| `app/Modules/UserProfile/Order/Services/WorkshopQuotationService.php` | **new** — the only reader/writer |
| `app/Http/Controllers/Admin/WorkshopQuotationController.php` | **new** — issue / revoke, 404s on non-B2B |
| `app/Http/Controllers/Workshop/QuotationSubmissionController.php` | **new** — public, guest-only |
| `routes/workshop.php` | **new** public route file |
| `routes/web.php` | requires `workshop.php` |
| `routes/admin.php` | `admin.orders.workshop-quotations.store` / `.revoke` |
| `app/Http/Middleware/HandleInertiaRequests.php` | shares one new flash key, `workshop_link` |
| `app/Modules/UserProfile/Admin/Services/AdminQueryService.php` | service injected; `workshop_quotations` on `orderDetail()` |
| `resources/js/pages/Workshop/Quotation.vue`, `QuotationThanks.vue` | **new** public pages |
| `resources/js/components/admin/AdminWorkshopQuotationsCard.vue` | **new** issue + comparison UI |
| `resources/js/pages/Admin/Orders/Show.vue` | card mounted inside `#order-section-angebote` |
| `resources/js/types/admin.ts` | `AdminWorkshopQuotation`, `AdminWorkshopComparisonRow`, one field on `AdminOrderDetail` |

**Schema decisions.**

```
b2b_workshop_quotations
  id uuid pk | order_id → leasyback_orders.id (cascade) | auftragsnummer
  token_hash varchar(64) UNIQUE | workshop_label | invited_email
  show_appraisal_amounts bool | company_name, contact_person, contact_email, contact_phone
  earliest_repair_start | processing_days | total_net decimal(10,2)
  cannot_repair_for_amount bool | cannot_repair_note
  expires_at | submitted_at | revoked_at | created_by | revoked_by | timestampsTz

b2b_workshop_quotation_items
  id uuid pk | quotation_id → cascade | appraisal_position_id → b2b_appraisal_positions (cascade)
  amount_net decimal(10,2) nullable | repair_method | not_repairable bool
  UNIQUE(quotation_id, appraisal_position_id)
```

- **Positions are referenced, never copied.** An item is an FK to an appraisal position,
  so the appraisal stays the single source of truth for *what* is being repaired.
- **Net only.** No gross column exists on either table.
- **Status is derived, not stored** — `status()` computes `revoked | submitted | expired |
  invited` from the timestamps, so a row cannot contradict itself.
- **`leasyback_offers` was deliberately not extended.** It is the B2C offer entity, it is
  a flat per-offer aggregate, and it carries gross columns that §9 forbids here. Phase 10
  will turn a chosen quotation into a customer offer; the two stay separate.

**Public link security (§9, §19).**
- `Str::random(64)`, returned **once** at creation; only `hash('sha256', …)` is stored.
  Verified the plaintext is not in the table.
- `expires_at` (default 14 days, admin-settable 1–90) plus `revoked_at`. A signed URL was
  rejected precisely because it cannot be revoked without a side store.
- `findOpenByToken()` returns null for unknown / expired / revoked / already-submitted
  alike, so the endpoint cannot be used to probe which tokens exist; the controller turns
  every one of those into the same 404.
- Routes are throttled (`30,1` read, `10,1` submit) and the token is constrained to
  `[A-Za-z0-9]{64}`, so malformed tokens never reach the controller.
- Submission is single-use and re-checked **inside** a `lockForUpdate()` transaction, so
  two concurrent posts cannot both succeed.
- The public payload carries only the vehicle basics and the positions to price — no
  customer identity, no internal notes, no order status, no other quotation. Verified by
  asserting the exact key list.

**Business flow.** Admin issues a link per workshop → workshop opens it as a guest, prices
each position net, may flag individual positions "not repairable" and the job as a whole
"not doable for the requested amount" → submission stores items, computes the net total
(excluding not-repairable positions) and stamps `submitted_at`, which closes the link →
Admin expands the quotation to compare appraisal vs workshop vs difference per position.
Submitted quotations are never removed from the list (§9).

**`show_appraisal_amounts` — a flagged interpretation.** §9 requires the workshop to be
able to indicate it "cannot complete the repair for **the requested amount**", which
implies an amount is disclosed, but the spec never says the appraisal figure is shown.
Rather than silently pick one reading, this is a per-link admin toggle (default **on**,
exposing the position's chargeable amount as "Angefragt netto"). Flip the default if the
business wants prices withheld — see unresolved decision 13.

**B2C protections.**
1. Both admin routes resolve the order's **persisted** vehicle and 404 unless it is B2B;
   `invite()` independently re-asserts and throws 404 (verified).
2. The public route accepts a token only — no order or vehicle id — and a token can only
   ever exist for an order an admin created it for.
3. `orderDetail()` sets `workshop_quotations` to `null` for B2C, so the card cannot render.
4. `leasyback_offers` and the whole B2C offer path are untouched.

**Verified** with rolled-back probes: token is 64 chars and absent from the table in
plaintext; valid token resolves and a bogus one does not; public payload exposes exactly
`workshop_label, expires_at, shows_appraisal_amounts, vehicle, positions`; submission
totalled 310.00 against an appraisal total of 600.00 with a per-position difference of
90.00; a not-repairable position stored a null amount and was excluded from the total;
the token stopped resolving after submission; a revoked link stopped resolving while the
already-submitted one stayed listed; `show_appraisal_amounts=false` nulled every
`requested_amount_net`; default TTL was 14 days; a B2C invite was blocked with 404.

### Phase 9.1 — `request_workshop_quotations` re-pointed at quotations (2026-08-05)

Approved explicitly by the user, which is the §14 gate ("do not change the underlying task
logic without explicit approval"). This closes the last open piece of phase 9 and
unresolved decision 14.

| File | Change |
|---|---|
| `app/Modules/UserProfile/Order/Services/OrderTaskResolver.php` | new `has_submitted_quotation` context key; `request_workshop_quotations` now keys off it instead of `has_offer`; title and description updated |

- New context key: `has_submitted_quotation` — true when any row in
  `$order['workshop_quotations']` has `status === 'submitted'`.
- `done: $context['has_submitted_quotation'] || $rank >= 5`,
  `open: $rank === 4 && ! $context['has_submitted_quotation']`.
- Because `WorkshopQuotation::status()` evaluates `revoked` before `submitted`, a
  submitted-then-revoked quotation reports `revoked` and correctly does **not** satisfy
  the task. `invited` and `expired` never counted.
- Title changed to **"Werkstattangebote anfordern"** (was "Werkstattangebote einholen")
  and the description now points at workshop links rather than an Angebotsentwurf.
- **No other task rule changed.** `prepare_customer_offer`, `await_customer_approval` and
  `commission_workshop` still key off `leasyback_offers`, which is correct — phase 10 is
  what turns a chosen quotation into a customer offer.
- B2C is untouched: `forOrderDetail()` still returns `null` before reading any of this,
  and `workshop_quotations` is `null` on a B2C payload (both re-verified).

**Ordering dependency worth knowing:** `AdminQueryService::orderDetail()` assigns
`$order['workshop_quotations']` *before* `$order['tasks']`. If that ever gets reordered,
the task silently reverts to "always open" via the `?? []` fallback.

**Consequence to be aware of:** between "a quotation has been submitted" and "an offer
draft exists", the queue now has no `next` task — `prepare_customer_offer` still requires
a `leasyback_offers` draft. Previously `request_workshop_quotations` stayed open and
filled that gap. Phase 10 closes it by deriving the offer from a selected quotation.
Verified: with one submitted quotation and no offer, `next` is null and
`request_workshop_quotations` sits in `history`.

**Verified** with a rolled-back probe across all five states, at `rank === 4`
(`inspected`): no quotations → task open; invited/unsubmitted → open; revoked → open;
expired → open; one submitted → done and moved to `history`. Full suite after the change:
406 passed, 4 skipped, 2 failed (the two baseline ones), 1603 assertions. Pint passed.

### Phase 10 — Customer offer presentation (§10)

| File | Change |
|---|---|
| `database/migrations/2026_08_05_000006_create_b2b_offer_presentations_table.php` | **new** |
| `app/Modules/UserProfile/Order/Models/B2bOfferPresentation.php` | **new** (+ `app/Models` re-export) |
| `app/Modules/UserProfile/Order/Services/B2bOfferService.php` | **new** — create from quotation, snapshot, reject, customer payload |
| `app/Modules/UserProfile/Offer/Services/OfferService.php` | `publishOffer()` calls `snapshotOnPublish()`; new constructor dep |
| `app/Http/Controllers/OfferController.php` | new `reject()` |
| `app/Http/Controllers/Admin/WorkshopQuotationController.php` | new `createOffer()` |
| `app/Policies/OfferPolicy.php` | new `reject()` ability, delegating to `select()` |
| `routes/orders.php` | `offers.reject` |
| `routes/admin.php` | `admin.orders.b2b-offer.store` |
| `app/Modules/UserProfile/Vehicle/Services/VehicleService.php` | includes `rejected` offers; strips gross for B2B; attaches `presentation`; new constructor dep |
| `app/Modules/UserProfile/Admin/Services/AdminQueryService.php` | attaches `presentation` to B2B offers; new constructor dep |
| `resources/js/components/vehicle/VehicleExpandedPanel.vue` | B2B offer block (totals, per-position table, accept/reject/comment); net instead of gross for B2B |
| `resources/js/components/admin/AdminWorkshopQuotationsCard.vue` | "Als Kundenangebot übernehmen" |
| `resources/js/lib/customerOrderFlow.ts` | rejection wording on `quotations_preparing` |
| `resources/js/types/order.ts` | `rejected` status, optional gross keys, `B2bOfferPresentationData` |

**Schema.** `b2b_offer_presentations` is 1:1 with a `leasyback_offers` row
(`offer_id` unique FK, cascade): `workshop_quotation_id`, `lines` (json snapshot),
`appraisal_total_net` / `repair_total_net` / `saving_net`, `valid_until`,
`customer_note`, `presented_at`, `rejected_at` / `rejected_by_user_id` /
`customer_comment`.

**Decision 1 — offer rejection (RESOLVED).** `offer_status` is `varchar(20)` with no
check constraint, so `rejected` was added as a value. **Rejection does not touch
`order_status`, and the transition graph was not modified at all.** This follows the rule
`OfferService::selectOffer()` already documents — offer selection never moves the order —
so acceptance and rejection stay symmetric. The order remains at `inspected` and
`prepare_customer_offer` re-opens by itself. Verified: after a rejection the order is
still `inspected`.

**Decision 9 — snapshot, not lock (RESOLVED).** `snapshotOnPublish()` freezes the
presented lines and totals into the presentation row at the moment of publication.
Positions stay editable for other purposes; what was presented is immutable. Verified:
after publishing, rewriting the positions to a single "GEAENDERT / 9999.00" row left the
snapshot at its original two lines and 600.00 total.

**Business flow.** Admin picks a *submitted* quotation → "Als Kundenangebot übernehmen"
creates a **draft** `leasyback_offers` row plus its presentation → the existing publish
action makes it customer-visible and snapshots it → customer sees appraisal vs repair vs
saving per position and accepts (existing `offers.select`) or rejects with an optional
comment (`offers.reject`).

**Gotcha found and fixed:** `LeasybackOffer::saving()` derives `final_total_net` by
summing *all four* net columns. Setting both `repair_cost_net` and
`workshop_repair_quote_net` double-counted the total (600.00 for a 300.00 offer). Only
`repair_cost_net` now carries the B2B total; every other amount is written as `'0'`
explicitly, which also silences `bcadd(null)` deprecations from that same hook.

**B2C protections.**
1. `snapshotOnPublish()` returns early when there is no presentation row, so the B2C
   publish path is unchanged.
2. Gross keys are omitted **only** for a B2B vehicle; a B2C payload still carries all five
   (verified both directions).
3. `presentation` is absent from a B2C offer payload (verified).
4. `rejected` is added to the customer offer filter, but a B2C offer can never reach that
   status — no B2C code path calls `B2bOfferService::reject()`.
5. `OfferPolicy::reject()` delegates to `select()`, so the owner-only BOLA fix covers it.
6. The B2C offer card still renders `final_total_gross`; only the B2B branch switched to
   net.

**Verified** with rolled-back probes: draft creation totals `final_total_net=300.00` /
`final_total_gross=0.00`; publish sets `presented_at` and 2 snapshot lines with a
not-repairable line carrying a null repair amount; snapshot survives later position
edits; rejection sets `offer_status=rejected`, stamps `rejected_at`, stores the comment
and writes a `rejected_by_customer` audit row alongside `published`; double rejection
blocked (400); selecting a rejected offer blocked (400); customer payload contains **no**
gross key, **no** `service_fee`, and **no** `internal_note` (planted `GEHEIM-INTERN` in
`leasyback_order_logistics` and asserted absence across the whole serialized payload).

**Not done in this phase:** no offer-available email or 24 h reminders (§18, phase 13);
damage images are referenced by id in the snapshot but not rendered as thumbnails; a
rejection does not notify anyone yet.

### Phase 10.1 — Expired offers can no longer be accepted (2026-08-05)

Closes unresolved decision 18. Acceptance only; rejection is deliberately untouched.

| File | Change |
|---|---|
| `app/Modules/UserProfile/Order/Models/B2bOfferPresentation.php` | `isExpired()` now compares against **today**, not `isPast()` |
| `app/Modules/UserProfile/Order/Services/B2bOfferService.php` | new `expiredOn()` returning the lapsed validity date or null |
| `app/Modules/UserProfile/Offer/Services/OfferService.php` | `selectOffer()` rejects an expired offer with a 422 |

**Off-by-one fixed along the way.** `isExpired()` used `$this->valid_until->isPast()`, but
`valid_until` is a **date** cast anchored at 00:00 — so an offer valid *through today*
counted as expired from one second after midnight. It now uses
`lt(now()->startOfDay())`, so the offer is good for the whole of its last day. This also
corrects the `is_expired` flag that phase 10 already ships to the customer UI.

**Where the guard lives.** In `OfferService::selectOffer()`, the single writer for
selection, so the customer route (`offers.select`) and Admin's accept-on-behalf route
(`admin.orders.offers.select`) are both covered by one rule. Placed with the existing
pre-transaction guards, matching how the `already selected` check is done.

**Message.** 422 with
`Dieses Angebot war bis zum TT.MM.JJJJ gültig und kann nicht mehr freigegeben werden.
Bitte fordern Sie ein neues Angebot an.` — `HandlesServiceValidationErrors` turns that
into a normal validation error on the `offer` field, so the customer sees it inline.
German, matching the rest of `B2bOfferService` and the customer UI; note the surrounding
`OfferService` messages are still English, which is pre-existing inconsistency.

**B2C unaffected.** `expiredOn()` reads `b2b_offer_presentations`, and a B2C offer has no
row there, so it always returns null. Verified directly: a published B2C offer with no
presentation is still accepted and `expiredOn()` returns null. No B2C expiry behaviour
existed before and none was added.

**Verified** with a rolled-back probe, one freshly published offer per case:
`valid_until` yesterday → **blocked, 422** with the dated message; today → **accepted**
(the boundary fix); tomorrow → accepted; no validity → accepted; rejecting an expired
offer → still allowed and lands in `rejected` (unchanged); B2C offer → accepted.
Full suite: 406 passed, 4 skipped, 2 failed (the two baseline ones), 1603 assertions.
Pint passed.

### Phase 11 — Repair appointment (§11)

| File | Change |
|---|---|
| `database/migrations/2026_08_05_000007_add_repair_appointment_to_order_logistics_table.php` | **new** — 2 columns |
| `app/Modules/UserProfile/Order/Models/OrderLogistics.php` | fillable + casts |
| `app/Modules/UserProfile/Order/Services/OrderCollectionService.php` | `repairAppointmentRules()`, `updateRepairAppointment()`, two new keys on `forOrders()`; `TransitionOrderStatus` injected |
| `app/Modules/UserProfile/Order/Services/OrderTaskResolver.php` | `SECTION_REPAIR`; `enter_repair_appointment` completes from appointment data; `monitor_repair` dated from the repair start |
| `app/Http/Controllers/Admin/OrderController.php` | `updateRepairAppointment()`, 404s on non-B2B |
| `routes/admin.php` | `admin.orders.repair-appointment` |
| `app/Modules/UserProfile/Order/Services/B2bOfferService.php` | `workshop_quotation_id` added to the presentation payload (seeds the form) |
| `resources/js/components/admin/AdminRepairAppointmentCard.vue` | **new** |
| `resources/js/pages/Admin/Orders/Show.vue` | card + `#order-section-reparatur` anchor + seed lookup |
| `resources/js/lib/customerOrderFlow.ts` | repair business info on the B2B timeline |
| `resources/js/types/order.ts`, `types/admin.ts` | appointment + presentation fields |

**Schema (approved decision 4).** Two nullable columns on the existing
`leasyback_order_logistics` — **no new table** (§7):

```
confirmed_repair_start_date  date
estimated_processing_days    unsignedSmallInteger
```

Chosen over a date range because §9 already collects the workshop's
`earliest_repair_start` and `processing_days`, so start + duration is exactly what the
source data provides and needs no derivation.

**Business flow.** Admin opens the card (seeded from the quotation the presented offer was
built on) → confirms or edits start + duration → saving writes the appointment **and**, if
the order is at `workshop_commissioned`, transitions it to `workshop` via
`TransitionOrderStatus`. Rescheduling later updates the dates and leaves the status alone.

**Why the transition lives in the service.** `updateRepairAppointment()` performs it, not
the controller, so §11's "when the appointment is saved the status changes to In repair"
cannot be bypassed by another caller. It fires only from `workshop_commissioned`;
`workshop → workshop` is not a legal edge and `TransitionOrderStatus` would reject it
anyway. `OrderCollectionService` remains the single writer of the logistics row, and
injecting `TransitionOrderStatus` there is cycle-free (it depends only on
`VehicleScopeService`, `Notifier`, `OrderMailer`).

**No duplicate task (§11, §14).** `enter_repair_appointment` is now
`done: repair_start_date !== null || rank >= 6` and
`open: rank === 5 && repair_start_date === null`. It completes from the *appointment data*
rather than only the status move, and the resolver's existing `open && !done` invariant
makes a duplicate structurally impossible. Verified: the task appears exactly once in
every state probed.

**New `SECTION_REPAIR = 'reparatur'` anchor.** The task previously pointed at
`SECTION_STATUS`, which is the timeline card and contains no form — jumping there would
have broken §14's "clicking a task must navigate to its relevant section". The card
carries `id="order-section-reparatur"`.

**Timeline (§15).** `workshop_commissioned` and `vehicle_in_repair` render
`Bestätigter Reparaturbeginn: …` and `Voraussichtliche Dauer: N Arbeitstage` as labelled
subtitle lines, kept separate from the stage's own status-change timestamp.

**B2C protections.**
1. `updateRepairAppointment()` returns early for a non-B2B vehicle — verified it writes
   **no** logistics row at all for B2C, and performs no transition.
2. The admin route 404s on the persisted vehicle type, as `updateCollection()` does.
3. `OrderTaskResolver` still returns `null` for B2C, so the task never appears.
4. The card is gated on `vehicle_belongs === 'B2B'`.
5. The two new keys ride on the existing `forOrders()` payload, which B2C orders never
   receive (`collection` is null for them in `orderDetail()`).

**`internal_note` stays admin-only.** The new fields are customer-visible business dates
and sit alongside it in the same reader, so this was re-verified explicitly: the customer
payload returns `requested_collection_date, confirmed_collection_date,
confirmed_repair_start_date, estimated_processing_days, collection_address,
collection_note` and **no** `internal_note`; the `includeInternal: true` read still has it.

**Verified** with rolled-back probes: task open at `workshop_commissioned` with
`section=reparatur`; saving moved the order to `workshop`, moved the task into `history`
and made `monitor_repair` next; appointment read back as 2026-08-08 / 5 days; rescheduling
updated to 2026-08-14 / 2 days with the status still `workshop`; duplicate count of
`enter_repair_appointment` was **1** in every state; with no appointment saved the task
stayed open; B2C wrote no row and resolved `tasks` to null.

**Not done in this phase:** no notification when the vehicle enters repair — §11's last
line is deferred to phase 13 (§18) as planned, so **§11 is not fully satisfied until
phase 13 lands**.

### Phase 12 — Minimal internal billing & completion gate (§13, §21)

> **Product decision (2026-08-05, user):** *Stripe will be integrated in the future.
> There is currently no billing or payment integration.* Phase 12 was therefore
> **re-scoped away from Lexware** to the minimum internal workflow needed to complete an
> order safely. **No Lexware. No Stripe. No payment collection, checkout, refunds,
> webhooks, subscriptions or external accounting integration.**

| File | Change |
|---|---|
| `database/migrations/2026_08_05_000008_create_b2b_order_billing_table.php` | **new** |
| `app/Modules/UserProfile/Order/Models/OrderBilling.php` | **new** (+ `app/Models` re-export) |
| `app/Modules/UserProfile/Order/Services/B2bBillingService.php` | **new** — the only reader/writer |
| `app/Modules/UserProfile/Order/Actions/TransitionOrderStatus.php` | `guardBillingBeforeCompletion()` |
| `app/Modules/UserProfile/Order/Services/OrderTaskResolver.php` | `SECTION_BILLING`; `prepare_invoice` rebuilt on billing state; `mark_invoice_processed` and `complete_order` gated on it |
| `app/Http/Controllers/Admin/OrderBillingController.php` | **new**, 404s on non-B2B |
| `routes/admin.php` | `admin.orders.billing` |
| `app/Modules/UserProfile/Admin/Services/AdminQueryService.php` | `billing` on `orderDetail()`; new constructor dep |
| `resources/js/components/admin/AdminBillingCard.vue` | **new** |
| `resources/js/pages/Admin/Orders/Show.vue` | card + `#order-section-abrechnung` anchor |
| `resources/js/types/admin.ts` | `AdminOrderBilling` + one field on `AdminOrderDetail` |

**Schema.** One row per B2B order, `b2b_order_billing`:

```
id uuid pk | order_id uuid UNIQUE → leasyback_orders.id (cascade) | auftragsnummer
billing_status varchar(20) default 'pending'   ('pending' | 'processed')
invoice_reference varchar nullable             optional invoice number/reference
invoice_document_id uuid nullable → vehicle_report_documents.id (nullOnDelete)
processed_at timestampTz nullable | processed_by_user_id
created_by_user_id | updated_by_user_id | timestampsTz
```

- A **separate table**, not columns on `leasyback_orders`, so B2C is untouched by
  construction and the future Stripe work has a home that does not widen the shared
  orders table.
- `billing_status` is a plain **varchar, not a DB enum or check constraint**, precisely so
  Stripe states (`awaiting_payment`, `paid`, …) can be added later without altering the
  column.
- **No Stripe columns exist.** They are not added until they are used.

**The §21 completion gate.** `guardBillingBeforeCompletion()` lives in
`TransitionOrderStatus` — the single writer of `order_status` — inside the locked
transaction, so no controller, task action or future caller can route around it. It reads
the billing record directly, matching how `isB2bOrder()` resolves the vehicle, rather than
taking a constructor dependency. A **missing** billing record counts as not processed, so
an order with no billing at all can never complete. `isProcessed()` requires *both*
`billing_status === 'processed'` and a non-null `processed_at`, so a half-written row
cannot open the gate.

**`prepare_invoice` is no longer a placeholder.** It was derived from "a published
`rechnung` document exists", which is not the fact §21 gates on. Now:

| Task | done | open |
|---|---|---|
| `prepare_invoice` | `billing_processed \|\| rank >= 11` | `rank >= 9 && ! billing_processed` |
| `mark_invoice_processed` | `rank >= 10` | `rank === 9 && billing_processed` |
| `complete_order` | `rank >= 11` | `rank === 10 && billing_processed` |

`prepare_invoice` uses `rank >= 9` rather than `rank === 9` deliberately: an order that
somehow reached `invoice_processed` without billing surfaces this task again instead of
dead-ending on a completion the gate will refuse. Verified — that exact case returns
`prepare_invoice` as `next`.

**Marking processed is one-way.** The service never clears `processed_at`; re-saving with
the box unticked updates the reference but leaves the record processed. Un-marking would
let an already-completed order lose the justification it was completed on. Verified.

**B2C protections.**
1. `B2bBillingService::update()` returns early for a non-B2B vehicle — verified it writes
   **no** row at all.
2. The route 404s on the persisted vehicle type.
3. `orderDetail()` sets `billing` to `null` for B2C; the card cannot render.
4. The gate is a no-op for B2C: `completed` is a B2B-only status, so `guardChannel()`
   already rejects it before the billing guard runs. Verified a B2C order still moves
   `reinspection → delivered` normally.

**Verified** with rolled-back probes: completing without billing → **blocked** with
`Der Auftrag kann nicht abgeschlossen werden, solange die Abrechnung nicht als verarbeitet
markiert ist.` and the status unchanged at `invoice_processed`; billing saved but not
marked → still blocked; marked processed → **completed**, record reads
`status=processed / ref=RE-2026-002 / processed_at set`; at `vehicle_returned` the next
task is `prepare_invoice`, and after marking it becomes `mark_invoice_processed` with
`prepare_invoice` in history exactly **once**; re-saving with `mark_processed=false` keeps
`is_processed=true` while still updating the reference; B2C wrote no row, resolved
`billing` and `tasks` to null, and still reached `delivered`.

**Future Stripe integration note.** When Stripe lands: add its columns to
`b2b_order_billing` and new `billing_status` values; the completion gate reads
`OrderBilling::isProcessed()`, so a payment-driven flow can satisfy §21 without the gate
itself changing. Webhooks should write to this same record rather than a parallel one.
Nothing in this phase presumes a payment provider.

### Phase 13 — B2B notifications (§18)

Lexware remains **blocked and untouched**. No payment/accounting integration was added.

| File | Change |
|---|---|
| `database/migrations/2026_08_05_000009_add_reminder_tracking_to_b2b_offer_presentations_table.php` | **new** — 2 columns |
| `app/Modules/UserProfile/Order/Models/B2bOfferPresentation.php` | fillable + casts |
| `app/Modules/UserProfile/Order/Services/B2bOfferService.php` | `offersDueForReminder()`, `markReminderSent()` |
| `app/Console/Commands/SendB2bOfferReminders.php` | **new** |
| `routes/console.php` | daily schedule, `withoutOverlapping()->onOneServer()` |
| `app/Mail/Orders/OfferApprovalReminderMail.php` | **new** |
| `app/Mail/Orders/OrderCompletedMail.php` | **new** |
| `app/Services/Mail/OrderMailer.php` | `offerApprovalReminder()`; `completed` added to `STATUS_MAILABLES` |
| `app/Enums/NotificationType.php` | `CustomerActionRequired` case + variant/icon arms |

**What already existed — and was verified rather than rebuilt.** Most of §18 was already
served by machinery from earlier work, so this phase deliberately added very little:

| §18 requirement | Status |
|---|---|
| Invitation emails | already `B2bInvitationNotification` |
| Password reset | already Laravel's |
| Important status changes | already `TransitionOrderStatus::notifyStatusChange()` |
| Offer available | already `OfferService::notifyOfferPublished()` |
| Appointment confirmed | already — B2B `confirmed` maps to `AppointmentConfirmedMail` |
| **Repair started** | already — `workshop` maps to `VehicleInRepairMail`; **phase 11's outstanding item was in fact already satisfied** the moment the appointment save transitioned the order. Verified by assertion, not assumed. |
| Order completed | **added** — `completed` previously fell through to the generic `OrderStatusUpdatedMail` |
| **Customer action required + 24 h reminders** | **added** — the one genuine gap |

*(Corrects the phase 11 note and unresolved item 15's implication that no repair-start
notification existed: the mail was already wired; what was missing was the verification.)*

**Reminder design.** `offersDueForReminder()` expresses every stop condition as an
exclusion, so an offer that is no longer actionable cannot produce a reminder:

- **accepted** → `offer_status` becomes `selected`, dropped by the `published` filter
- **rejected** → becomes `rejected`, likewise dropped
- **cancelled** → the offer's own `cancelled` status is dropped, *and* a
  cancelled/discarded/completed **order** is excluded explicitly
- **expired** → `valid_until` before today is excluded

Spacing: first reminder falls due 24 h after `presented_at`, each later one 24 h after
`last_reminder_sent_at`. `markReminderSent()` stamps the timestamp and increments
`reminder_count` immediately after each send.

**Duplicate prevention.** Two independent guarantees, both verified:
1. The 24 h window plus the immediate stamp — a second run inside the window selects
   nothing. Proven by running the command twice and asserting the mail was queued exactly
   **once** in total.
2. Everything else is already once-only by construction: `publishOffer()` requires
   `draft`, `selectOffer()` requires `published`, `reject()` requires `published`, and
   `notifyStatusChange()` fires only behind `$realTransition` — so a same-status write is
   a silent no-op. `workshop → workshop` is not a legal edge, so repair-start cannot fire
   twice.

**Asynchronous delivery.** Unchanged and confirmed: `OrderMailer::dispatch()` uses
`Mail::to()->queue()` and `SystemNotification implements ShouldQueue`, with
`queue.default = database`. `notifyStatusChange()` runs **after** the transaction commits
and is wrapped so a failed send can never surface as a failed transition. The probe
asserts with `Mail::assertQueued`, which would fail for a synchronous send.

**B2C protections.**
1. Reminders are driven by `b2b_offer_presentations`; a B2C offer has no row, so it can
   never be selected. Verified: a published B2C offer, back-dated, yielded `due=0` and the
   command queued **nothing**.
2. `completed` added to `STATUS_MAILABLES` is B2B-only — `delivered` remains the B2C
   terminal and its mapping is untouched.
3. The new `NotificationType` case is additive; existing cases keep their variant/icon.
4. No existing mailable, recipient resolver or B2C trigger was modified.

**Verified** with rolled-back probes: all five stop conditions returned `due=0`
(accepted / rejected / offer cancelled / order cancelled / expired) while a live offer
returned `due=1`; run 1 queued exactly 1 reminder, run 2 queued 0 more,
`reminder_count = 1`, and the offer became due again once `last_reminder_sent_at` was
back-dated past 24 h; saving a repair appointment queued `VehicleInRepairMail` and moved
the order to `workshop`; completing a billed order queued `OrderCompletedMail`; B2C
produced no presentation row, `due=0`, and nothing queued.

**Not done in this phase:** no per-user notification preferences (§18 says "default user
notification settings" — there is no settings surface); no leasing-end alerts (§18
optional, unresolved item 8); no reminder for any customer action other than offer
approval.

---

### Phase 14 — Company statistics & Excel export (§17)

Lexware remains **blocked and untouched**. No new composer or npm dependency was added.

| File | Change |
|---|---|
| `app/Modules/UserProfile/B2B/Services/B2bStatisticsService.php` | **new** — every §17 figure + the export rows |
| `app/Support/XlsxWriter.php` | **new** — dependency-free `.xlsx` writer |
| `app/Http/Controllers/B2b/StatisticsController.php` | **new** — `index` (Inertia) + `export` (download) |
| `routes/b2b.php` | `b2b.statistics.index`, `b2b.statistics.export`, both behind `b2b.can:analytics.view` |
| `app/Enums/B2bPermission.php` | `ViewAnalytics::page()` now returns `b2b.statistics.index` (was `null`) |
| `resources/js/pages/b2b/Statistics.vue` | **new** — the page |
| `resources/js/types/b2b.ts` | `B2bStatistics` + 4 supporting interfaces |
| `resources/js/components/AppSidebar.vue` | "Statistik" nav entry, `analytics.view` |
| `resources/js/layouts/app/AppSidebarLayout.vue` | same entry, mirrored |

**No migration.** Every amount §17 needs already exists: `b2b_offer_presentations`
carries `appraisal_total_net`, `repair_total_net` and `saving_net` per accepted offer,
and `leasyback_order_status_updates` already dates every completion. Nothing needed
storing, so nothing was stored — the statistics are derived on read, like the task queue.

**No spreadsheet dependency.** `composer.json` was inspected first: there is no
PhpSpreadsheet, no `laravel-excel`, and no existing export utility anywhere in `app/`.
Rather than add one, `App\Support\XlsxWriter` writes the six XML parts of an xlsx into a
zip with `ZipArchive` (a core extension, confirmed present). A real `.xlsx` — not a CSV
rename — so §17's "as an Excel file" is met literally. It is intentionally narrow: flat
grids, inline strings, three cell styles, no formulas. **If a future export needs
formulas, images or multiple fonts, add a real library instead of growing this file.**

**Decision 6 resolved — which orders count toward savings.** Only orders where the
customer *accepted* a presented offer (`leasyback_offers.offer_status = 'selected'` with
a `presented_at` snapshot), and which were not later cancelled or discarded.

- An order with no offer, a rejected offer or an expired one contributes **nothing at
  all — not a zero**. §17's subtraction has no "accepted repair amount" for it, so it is
  undefined, and counting it as a zero saving would drag the average down with orders
  that were never in scope. `orders_counted` / `vehicles_counted` are returned alongside
  the totals so the basis is always visible rather than implied.
- A cancelled/discarded order is excluded even if an offer had been accepted earlier —
  the repair it priced never happened.
- "Partially approved" **does not exist**: acceptance is all-or-nothing over the whole
  presented offer; there is no per-line approval anywhere in the schema. The nearest
  thing is a position the workshop marked `not_repairable`, which is a snapshot concern
  — see unresolved item 31.

**Accepted repair amount comes from the SNAPSHOT, not live positions.** `b2b_appraisal_positions`
stays editable after publication by design (§8), so recomputing from it would let a later
correction silently rewrite a figure the customer was already shown. `b2b_offer_presentations`
is frozen at publish (phase 10), so the statistics, the Excel export and the offer the
customer accepted can never disagree.

**How the final appraisal is excluded — structurally, not by a filter.** A Nachgutachten
is a document in `vehicle_report_documents` carrying no amounts, and
`b2b_appraisal_positions` holds initial-appraisal positions only (stated in
`AppraisalPosition`'s own docblock since phase 8). Nothing this service reads can carry a
final-appraisal amount, so there is no query condition that could be forgotten or
regressed. The page states the exclusion to the customer in plain German under the
saving card.

**The nine §17 figures.**

| Figure | Source |
|---|---|
| Active / completed orders | status counts bucketed via `OrderStatus::completedValues()` and a derived cancelled set (`closedValues() − completedValues()`) — no hardcoded list, so a new closed status cannot be missed the way phase 7.1 found |
| Total initial appraisal amount | Σ `appraisal_total_net` over accepted offers |
| Total accepted repair amount | Σ `repair_total_net` |
| Total savings | Σ `saving_net` |
| Average saving per vehicle | total ÷ **distinct vehicles** with an accepted offer (§17 says per vehicle, and one vehicle can carry several orders); `null` when none |
| Saving percentage | saving ÷ appraisal × 100; `null` when the appraisal total is 0 |
| Processing time | avg calendar days from `leasyback_orders.created_at` to the **earliest** completed status in `leasyback_order_status_updates` — the audit trail, not `updated_at`, which a later edit would stretch. In-flight orders are not measured rather than counted as 0; `measured_orders` is reported |
| Status distribution | non-zero counts in `OrderStatus` declaration order, labelled via `OrderStatusLabel` |
| Monthly order volume | last 12 months including the current one, bucketed **in PHP** so SQLite and Postgres produce identical buckets; empty months kept so the chart axis stays even |

All money is computed with `bcadd`/`bcdiv`/`bcmul` and travels to the frontend as decimal
**strings**; only the percentage and the day average — already approximations — are
numbers. Net only (§9). The Vue page formats but never re-derives.

**Company scoping — one boundary, server-side.** Every read in `B2bStatisticsService`
starts from `scopedOrders()`, which joins `vehicles` and pins both
`vehicle_belongs = 'B2B'` and the active membership's `b2b_id`. There is no code path
that reaches an order without it, and no company id is accepted from the request — the
company comes from `B2bContext::activeMembership()`. The channel therefore comes from the
persisted vehicle, exactly as §7 requires.

A member whose `vehicle_scope` is `own` is narrowed to the vehicles they created, matching
`VehicleScopeService::scopeQuery()`, so the export can never hand them a row the fleet
table would have hidden. `scope.company_wide` is returned so the page can say so rather
than presenting a partial figure as the company's. **Note this is stricter than the
existing `FleetOverview` panel**, which is company-wide for own-scope members too — see
unresolved item 32.

**Permission.** Both routes reuse the existing `analytics.view` (`B2bPermission::ViewAnalytics`),
the permission that already governs every company-level figure in the app. No new
permission was introduced. `ViewAnalytics::page()` now returns the route it unlocks,
which the enum's docblock always promised but had no page to point at.

**B2C protections.**
1. `vehicle_belongs = 'B2B'` is pinned in the base query, so a B2C vehicle cannot appear
   even though it has no `b2b_id` to match on anyway. Stated as a rule, not left to the data.
2. No B2C statistics surface exists and none was added. Nothing in `VehicleController`,
   `B2bAnalyticsService` or the B2C dashboard was touched.
3. `EnsureB2bPermission` passes non-Firmenkunde accounts through by design; the controller
   then refuses them because no membership resolves. Verified: a Privatkunde reaches the
   controller and gets **403**.
4. The nav entries are permission-filtered and `can()` is true for non-company accounts —
   but the entries live in the `Firmenkunde` list only, so no other role sees them.

**Verified** with a rolled-back probe (see §6 for the table). Two companies, a B2C order
and an own-scope member in one dataset; the arithmetic itself proves the isolation —
company B's 5 000 € order would have moved every total.

**Not done in this phase:** no date-range or per-vehicle filtering of the statistics (§17
does not ask for it); no scheduled/emailed report; no Admin-side cross-company statistics;
no CSV alternative (the xlsx is a real one, so there is nothing to fall back to).

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
| ~~`request_workshop_quotations`~~ | **resolved in phase 9.1** — now counts submitted workshop quotations | — |
| ~~`enter_repair_appointment`~~ | **resolved in phase 11** — completes from the saved `confirmed_repair_start_date` | — |
| ~~`prepare_invoice`~~ | **resolved in phase 12** — driven by the `b2b_order_billing` record | — |
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
9. `B2bStatisticsService::scopedOrders()` pins `vehicles.vehicle_belongs = 'B2B'`, so no
   B2C order can reach a statistic or an export row. No B2C statistics surface exists and
   none was added; `VehicleController`, `B2bAnalyticsService` and the B2C dashboard are
   untouched. A Privatkunde passes `EnsureB2bPermission` by design and is then refused by
   `StatisticsController` with 403, because no membership resolves.

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

### Verification results — phase 9

| Check | Result |
|---|---|
| `php artisan test --compact` | 406 passed, 4 skipped, **2 failed** (the two baseline ones), 1603 assertions |
| `php artisan migrate` | `2026_08_05_000005_create_b2b_workshop_quotation_tables` DONE (batch 12) |
| `vendor/bin/pint --dirty --format agent` | `passed` (after one auto-fix pass on the service) |
| `npm run build` | `✓ built in 17.40s`, exit 0 |
| `npx eslint <5 changed files>` | clean, no output |
| `php artisan route:list --path=werkstatt` | 3 public routes registered |

**`HandleInertiaRequests.php` was modified this phase, and one of the two baseline
failures lives in `HandleInertiaRequestsTest`** — so the failure was re-verified rather
than assumed: with that single file stashed
(`git stash push -- app/Http/Middleware/HandleInertiaRequests.php`) the test still fails
identically with *Not a valid Inertia response* at `AssertableInertia.php:84`, which is
the response-shape gate, reached long before any prop is inspected. The added flash key
is not the cause. Total stayed at 2 failures, not 3.

Untested by automation, same caveat as phase 8. Highest-value cases if tests are ever
allowed: the 404 on every unusable token state, the single-use guard under concurrency,
and the B2C 404 on both admin routes.

### Verification results — phase 10

| Check | Result |
|---|---|
| `php artisan test --compact` | 406 passed, 4 skipped, **2 failed** (the two baseline ones), 1603 assertions |
| `php artisan migrate` | `2026_08_05_000006_create_b2b_offer_presentations_table` DONE (batch 13) |
| `vendor/bin/pint --dirty --format agent` | `passed` |
| `npm run build` | `✓ built in 14.47s`, exit 0 |
| `npx eslint <4 changed files>` | clean, no output |

Untested by automation, same caveat as phases 8 and 9 — and the exposure is larger here,
because this phase touches `OfferService::publishOffer()` and `VehicleService`'s customer
payload, both of which B2C depends on. The B2C-facing behaviour was therefore checked
directly by probe in both directions (gross keys present for B2C, absent for B2B). If
tests are ever allowed, start with: gross keys present on a B2C offer payload, the
snapshot surviving a position edit, and `OfferPolicy::reject` refusing a non-owner.

### Verification results — phase 11

| Check | Result |
|---|---|
| `php artisan test --compact` | 406 passed, 4 skipped, **2 failed** (the two baseline ones), 1603 assertions |
| `php artisan migrate` | `2026_08_05_000007_add_repair_appointment_to_order_logistics_table` DONE (batch 14) |
| `vendor/bin/pint --dirty --format agent` | `passed` |
| `npm run build` | `✓ built in 17.39s`, exit 0 |
| `npx eslint <5 changed files>` | clean, no output |
| `php artisan route:list --path=repair-appointment` | `PATCH admin.orders.repair-appointment` registered |

Untested by automation, same caveat as phases 8–10. This phase writes a status
transition from a non-status endpoint, so if tests are ever allowed the first case should
be: saving an appointment at `workshop_commissioned` lands the order in `workshop` and
leaves exactly one `enter_repair_appointment` entry.

### Verification results — phase 12

| Check | Result |
|---|---|
| `php artisan test --compact` | 406 passed, 4 skipped, **2 failed** (the two baseline ones), 1603 assertions |
| `php artisan migrate` | `2026_08_05_000008_create_b2b_order_billing_table` DONE (batch 15) |
| `vendor/bin/pint --dirty --format agent` | `passed` (caught a genuine parse error from a bad edit first — see below) |
| `npm run build` | `✓ built in 19.46s`, exit 0 |
| `npx eslint <3 changed files>` | clean, no output |

Pint's first run failed with `Unclosed '(' on line 330` — an edit had left an orphaned
`$this->definition(` above the new comment block. Fixed and re-verified with `php -l`
before proceeding. Worth noting because Pint, not the test suite, is what caught it.

**This is the highest-risk phase to leave untested**, because the completion gate lives in
`TransitionOrderStatus` — the shared status writer for both channels. The B2C no-op was
therefore probed directly (a B2C order still moves `reinspection → delivered`). If tests
are ever allowed, start with: completing a B2B order without billing is refused; the same
order completes once billing is marked; a B2C order reaches `delivered` unaffected.

### Verification results — phase 13

| Check | Result |
|---|---|
| `php artisan test --compact` | 406 passed, 4 skipped, **2 failed** (the two baseline ones), 1603 assertions |
| `php artisan migrate` | `2026_08_05_000009_add_reminder_tracking_to_b2b_offer_presentations_table` DONE (batch 16) |
| `vendor/bin/pint --dirty --format agent` | `passed` |
| `npm run build` | `✓ built in 18.88s`, exit 0 (regression check — phase 13 is backend-only) |
| `npx eslint` | not run — no frontend file changed; notification variant/icon travel in the payload, so no TS map needed |
| `php artisan schedule:list` | `0 8 * * * php artisan b2b:send-offer-reminders` registered |

Probes used `Mail::fake()` with **`Mail::assertQueued`** (not `assertSent`), which fails
for a synchronous send — so the async requirement is asserted, not assumed.

Untested by automation, same caveat as phases 8–12. If tests are ever allowed, the three
highest-value cases are: two consecutive command runs queue exactly one reminder; each of
the four stop conditions removes the offer from the due set; a B2C offer is never
selected by the command.

### Verification results — phase 14

| Check | Result |
|---|---|
| `php artisan test --compact` | 406 passed, 4 skipped, **2 failed** (the two baseline ones), 1603 assertions — identical to the phase 13 baseline |
| `php artisan migrate` | not run — **phase 14 adds no migration** |
| `vendor/bin/pint --dirty --format agent` | `passed` |
| `npm run build` | `✓ built in 41.14s`, exit 0 |
| `npx eslint` (4 changed frontend files) | exit 0, no output |
| `php artisan route:list --path=company` | `b2b.statistics.index` + `b2b.statistics.export` registered |

Rolled-back probe results, one dataset holding two companies, a B2C order and an
own-scope member:

| Check | Expected | Result |
|---|---|---|
| Orders active / completed / cancelled | 2 / 1 / 1 | 2 / 1 / 1 |
| Total appraisal net | 1500.00 | 1500.00 |
| Total accepted repair net | 600.00 | 600.00 |
| Total saving net | 900.00 | 900.00 |
| Orders counted toward savings | 2 (published-but-unaccepted excluded, cancelled excluded) | 2 |
| Average saving per vehicle | 450.00 | 450.00 |
| Saving percentage | 60.00 | 60.00 |
| Processing time | 14 days over 1 measured order | 14 over 1 |
| Monthly buckets | 12 | 12 |
| Export rows | 4 (company A only) | 4 |
| Export contains the B2C plate | false | false |
| Export carries an `internal_note` key | false | false |
| Export carries any `gross` key | false | false |
| Own-scope member: orders / saving / export rows | 0 / 0.00 / 0 | 0 / 0.00 / 0 |
| Own-scope member: `scope.company_wide` | false | false |
| Workbook opens as a zip with all 7 parts | yes | yes |
| Rows sheet mentions the B2C plate | false | false |
| Rows sheet `<row>` count | 5 (heading + 4) | 5 |
| Member **without** `analytics.view` at the route | refused | 403 |
| Owner at the route | allowed | OK |
| Privatkunde at the middleware → at the controller | passes → refused | OK → 403 |

Cross-company isolation is proven by the arithmetic, not only by the plate check: company
B's fleet carried a 5 000 € accepted offer, which would have moved every total and added a
fifth export row.

Untested by automation, same caveat as phases 8–13. If tests are ever allowed, the three
highest-value cases are: company B's order never appears in company A's totals or export;
an own-scope member's export is empty while the owner's is not; a Privatkunde gets 403.

## 6a. Current working-tree state (after phase 14, 2026-08-05)

Branch **`feat/b2b-flow`**. *(Corrected 2026-08-05: every earlier revision of this section
said `feat/admin-chat`. The branch was renamed/switched during phase 14 — check
`git rev-parse --abbrev-ref HEAD` rather than trusting a branch name written here.)*

All nine migrations are applied locally (`migrate:status` batches 8–16). **Phase 14 adds
no migration**, so the count is unchanged.

**Phases 1–8 are committed.** Commit `d79a473` (57 files, +4584/−84) contains *all* of
phases 1 through 8 plus the 7.1 correction. Its message —
`feat: add service fee fields to b2b table and update existing records` — describes only
phase 1, so do **not** go looking for phases 2–8 in later commits; they are all in that
one. *(Corrected 2026-08-05: an earlier revision of this section said nothing had been
committed. That was true when written and is now wrong.)*

**Phases 9 through 14 are uncommitted.** `git status --porcelain` → **29 modified,
31 untracked**, nothing staged.

Untracked (phases 9–11) — additionally
`database/migrations/2026_08_05_000007_add_repair_appointment_to_order_logistics_table.php`
and `resources/js/components/admin/AdminRepairAppointmentCard.vue`:

```
app/Http/Controllers/Admin/WorkshopQuotationController.php
app/Http/Controllers/Workshop/QuotationSubmissionController.php
app/Models/{WorkshopQuotation,WorkshopQuotationItem,B2bOfferPresentation}.php
app/Modules/UserProfile/Order/Models/{WorkshopQuotation,WorkshopQuotationItem,B2bOfferPresentation}.php
app/Modules/UserProfile/Order/Services/{WorkshopQuotationService,B2bOfferService}.php
database/migrations/2026_08_05_000005_create_b2b_workshop_quotation_tables.php
database/migrations/2026_08_05_000006_create_b2b_offer_presentations_table.php
resources/js/components/admin/AdminWorkshopQuotationsCard.vue
resources/js/pages/Workshop/{Quotation.vue,QuotationThanks.vue}
routes/workshop.php
```

Modified: this handoff, `HandleInertiaRequests.php`, `AdminQueryService.php`,
`OrderTaskResolver.php`, `OfferService.php`, `OfferController.php`, `OfferPolicy.php`,
`VehicleService.php`, `Admin/Orders/Show.vue`, `VehicleExpandedPanel.vue`,
`customerOrderFlow.ts`, `types/admin.ts`, `types/order.ts`, `types/components.d.ts`
(generated), `routes/admin.php`, `routes/web.php`, `routes/orders.php`.

Phase 10.1 added no files; it edited `B2bOfferPresentation.php`, `B2bOfferService.php`
and `OfferService.php`, all already listed above.

Phase 11 additionally modified `OrderLogistics.php`, `OrderCollectionService.php`,
`OrderTaskResolver.php`, `Admin/OrderController.php` and `types/order.ts`.

Phase 13 added `2026_08_05_000009_add_reminder_tracking_to_b2b_offer_presentations_table.php`,
`app/Console/Commands/SendB2bOfferReminders.php`,
`app/Mail/Orders/{OfferApprovalReminderMail,OrderCompletedMail}.php`; and modified
`B2bOfferPresentation.php`, `B2bOfferService.php`, `OrderMailer.php`,
`NotificationType.php` and `routes/console.php`.

Phase 14 added `app/Modules/UserProfile/B2B/Services/B2bStatisticsService.php`,
`app/Support/XlsxWriter.php`, `app/Http/Controllers/B2b/StatisticsController.php`,
`resources/js/pages/b2b/Statistics.vue`; and modified `routes/b2b.php`,
`app/Enums/B2bPermission.php`, `resources/js/types/b2b.ts`,
`resources/js/components/AppSidebar.vue` and
`resources/js/layouts/app/AppSidebarLayout.vue`. No migration.

Phase 12 added `2026_08_05_000008_create_b2b_order_billing_table.php`,
`{app/Models,app/Modules/UserProfile/Order/Models}/OrderBilling.php`,
`B2bBillingService.php`, `Admin/OrderBillingController.php`, `AdminBillingCard.vue`;
and modified `TransitionOrderStatus.php`, `OrderTaskResolver.php`,
`AdminQueryService.php`, `routes/admin.php`, `Admin/Orders/Show.vue`, `types/admin.ts`.

Phase 10 is the first phase to modify files B2C depends on at runtime
(`OfferService::publishOffer`, `VehicleService`'s customer payload, `OfferPolicy`), so
review those three with more care than the additive B2B-only files.

Note `b2b.txt` and this handoff are now tracked, so spec/handoff edits show as
modifications rather than untracked files.

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

Phases 1–14 plus the 7.1, 9.1 and 10.1 correction passes are done. All three
`OrderTaskResolver` placeholders are resolved, the B2B workflow runs end to end, and the
company statistics surface plus its Excel export exist.
**Phase 15 has not been started** — vehicles can only be created one at a time.

| # | Phase | Spec | Why here |
|---|---|---|---|
| 15 | **Excel vehicle import** — per-row validation, keep valid rows | §5 | Independent. `App\Support\XlsxWriter` (phase 14) writes but does not **read** — see the prompt below |
| 16 | **Notes** — internal vs customer-visible note types, explicit marking before save | §16 | Independent |
| 17 | **Acceptance audit** — role matrix, cross-company isolation, audit-log coverage against §20 | §19, §20 | Final gate |

---

## 9. Exact next phase

**Phase 15 — Excel vehicle import (§5).** §5's one hard rule is the whole phase: *"The
import must validate required fields and show errors per row without discarding valid
rows."* That is a partial-success contract, and it is the thing most likely to be got
wrong — a single `DB::transaction()` around the whole file would satisfy every other
sentence in §5 and violate this one.

**Read this before starting: `App\Support\XlsxWriter` (phase 14) only WRITES.** Reading an
xlsx is a materially harder problem than writing one — shared-string tables, cell-format
resolution, date serial numbers, streaming large sheets — and the phase-14 writer offers
none of it. Do **not** extend it into a reader; decide honestly between a real dependency
(needs approval) and a CSV-only import, and say which and why *before* building.

Ready-to-use prompt:

```
Proceed with the next B2B-only implementation phase: Excel vehicle import (b2b.txt §5).

Read B2B_IMPLEMENTATION_HANDOFF.md first — especially §7's architectural rules and the
phase 14 section's note on App\Support\XlsxWriter — then inspect:
StoreVehicleRequest (the existing per-vehicle validation rules, and its documented
Admin-only `vehicle_belongs` exception), VehicleService::createVehicle(),
VehicleScopeService, B2bContext, B2bPermission::CreateVehicles, and the phase 2 B2B
fleet columns on `vehicles` (contract_number, cost_centre, driver_name, driver_contact,
collection_address_profile_id, leasing_end_date, mileage).

Requirements:
- Company users with `vehicles.create` may import vehicles from a file.
- §5's fields: registration number, VIN, manufacturer, model, first registration date,
  current mileage, leasing company, contract number, leasing end date (optional), cost
  centre, driver/contact person, collection address.
- CRITICAL — partial success: validate per row and report errors PER ROW, keeping every
  valid row. Do NOT wrap the whole import in one transaction that rolls back valid rows
  because of an invalid one. State explicitly how a row that fails mid-file is isolated.
- Reuse StoreVehicleRequest's rules rather than restating them, so a manually created
  vehicle and an imported one can never diverge. If reuse is impossible, say why.
- Every imported vehicle belongs to the caller's OWN company, taken from B2bContext.
  A b2b_id, vehicle_belongs or owner column in the uploaded file must be ignored, not
  trusted — the channel comes from the caller, never the file.
- Duplicate handling: decide and state what happens when an imported VIN or registration
  number already exists in the company (skip / error / update). This is a product
  decision — record it as a resolved decision in the handoff.
- Keep B2C completely unchanged: no import surface exists for Privatkunde and none
  should appear.
- Report the result back to the user: rows imported, rows rejected, and the reason per
  rejected row. A silent partial import is a failure of this phase.
- Do not touch statistics, notifications, billing or Stripe. Lexware stays blocked.
- Do not add comments. Do not add tests; update existing tests only when old
  expectations directly conflict.
- Do not modify unrelated files.

DEPENDENCY DECISION — report before building, do not assume:
App\Support\XlsxWriter writes xlsx but cannot read it, and must not be extended into a
reader. Report which you propose and why:
  (a) a real spreadsheet dependency (needs explicit approval — name it, say what it costs)
  (b) CSV-only import (no dependency; say plainly what the user loses versus §5's
      "Excel import", and how they would produce the CSV from Excel)
Do NOT add any dependency without approval.

Before implementation, report:
1. Your dependency recommendation, (a) or (b), with the trade-off
2. How per-row validation isolates a failing row while keeping valid ones
3. How you reuse the existing vehicle validation rules
4. Your duplicate-handling decision
5. How company ownership is forced from B2bContext rather than read from the file

After implementation, report: Files changed; the import flow; how partial success is
guaranteed; duplicate handling; how B2C was protected; how to verify.

Update B2B_IMPLEMENTATION_HANDOFF.md as part of this task, before declaring the phase
complete: add phase 15 to section 1 with its files, migrations and schema decisions,
business-flow changes, B2C protections, placeholders and unresolved decisions; re-point
sections 8 and 9 to phase 16; record test, build, Pint and ESLint results; refresh the
working-tree state in 6a; and correct in place, with a dated note, any statement in the
document you find to be inaccurate.
```

---

## 10. Unresolved product decisions

1. ~~**Offer rejection.**~~ **Resolved 2026-08-05 (phase 10)** — `rejected` added to
   `offer_status`; rejection is an offer-level event that deliberately leaves
   `order_status` untouched, so the transition graph was not modified.
2. **`discarded`.** No endpoint exercises it; the Admin reject action was never
   confirmed as wanted (`docs/B2C_ADMIN_IMPLEMENTATION_PLAN.md` §13).
3. ~~**Completion gate.**~~ **Resolved 2026-08-05 (phase 12)** — "billing processed" means
   an admin explicitly marked the `b2b_order_billing` record processed. Not a published
   `rechnung` document, and not a Lexware transmission (Lexware was dropped). Enforced in
   `TransitionOrderStatus`.
4. ~~**Repair appointment shape.**~~ **Resolved 2026-08-05 (phase 11)** — approved by the
   user as `confirmed_repair_start_date` + `estimated_processing_days` on
   `leasyback_order_logistics`. No date range, no new table.
5. **Workshop link lifetime and revocation UX** — §9/§19 require time-limited and
   revocable; no TTL or revocation surface agreed.
6. ~~**Saving statistics denominator.**~~ **Resolved 2026-08-05 (phase 14)** — only orders
   with a customer-**accepted** offer (`offer_status = 'selected'` + a `presented_at`
   snapshot) that were not later cancelled or discarded count. Orders with no offer, a
   rejected offer or an expired one contribute **nothing at all, not a zero** — §17's
   subtraction is undefined without an accepted repair amount, and a zero would drag the
   average down with orders that were never in scope. "Partially approved" turned out not
   to exist: acceptance is all-or-nothing over the whole presented offer, with no per-line
   approval anywhere in the schema. `orders_counted` and `vehicles_counted` are returned
   alongside the totals so the basis is always visible. The per-vehicle average divides by
   **distinct vehicles**, since one vehicle can carry several orders.
7. **Service fee in offers** — §13 says it must not appear in the repair offer before
   final billing; where exactly it surfaces at billing time is undefined. **Still open
   after phase 12:** the offer correctly excludes it (verified in phase 10), but the
   minimal billing record does **not** carry or apply it either — there is no invoice
   generation to put it on. It lives on the `b2b` company master data and waits for the
   Stripe/invoicing phase.
8. **Leasing-end alerts** — §18 optional per-vehicle alerts with configurable lead
   time; not designed.

Opened by phase 8:

9. ~~**Are positions editable after an offer is published or accepted?**~~
   **Resolved 2026-08-05 (phase 10)** — snapshot, not lock. Positions stay editable;
   `snapshotOnPublish()` freezes what was presented into `b2b_offer_presentations.lines`.
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

Opened by phase 9:

13. **Does the workshop see the appraisal amounts?** §9 lets a workshop declare it
    "cannot complete the repair for **the requested amount**", implying an amount is
    disclosed, but never says it is the appraisal figure. Implemented as a per-link admin
    toggle, `show_appraisal_amounts`, **defaulting to on**. If the business does not want
    workshops anchored to the appraisal price, flip the default in
    `WorkshopQuotationService::invite()` — one line, no migration.
14. ~~**Re-pointing the `request_workshop_quotations` task.**~~ **Resolved 2026-08-05** —
    approved by the user and implemented in phase 9.1; the task now counts submitted
    quotations. A follow-on gap it exposes (no `next` task between a submitted quotation
    and an offer draft) is closed by phase 10.
15. **No workshop invitation email.** Phase 9 issues the link and shows it once for the
    admin to copy; `invited_email` is stored but nothing is sent. **Still open after
    phase 13** — deliberately left alone, because §18's list covers *customer*
    notifications only and emailing a single-use token needs its own decision about
    re-sending (see item 16). Admin still copies the link by hand.
16. **Quotation revision.** A submitted quotation is final: the token stops working. If a
    workshop needs to correct a submission, Admin must issue a new link today. Confirm
    that is the wanted behaviour.

Opened by phase 10:

17. **Nothing happens automatically after a rejection.** **Partially addressed in phase
    13:** rejection now reliably *stops* the reminder cycle (verified). Still open:
    **nobody is notified that the customer rejected.** §18's list is customer-facing and
    names no admin alert, so none was invented — but an admin currently only learns of a
    rejection by opening the order. Decide whether admins should be told, and separately
    whether a rejected quotation may be re-presented.
18. ~~**Offer validity is recorded but not enforced.**~~ **Resolved 2026-08-05
    (phase 10.1)** — an expired offer is blocked at `selectOffer()` with a 422; the offer
    stays valid through the whole of its last day. Rejection is unaffected. Still open as
    a *product* question: nothing expires the offer proactively (no status change, no
    notification) — it simply stops being acceptable.
19. **Damage images are referenced but not shown.** The snapshot carries
    `damage_image_document_ids` per line; the customer offer view does not yet render
    thumbnails, though §10 lists "original appraisal PDF and damage images". Needs a
    signed-URL surface for those documents in the customer payload.
20. **Multiple published offers.** `selectOffer()` already closes competing published
    siblings, but nothing stops Admin publishing two B2B offers at once, which would show
    the customer two "Freigabe erforderlich" cards. Confirm whether B2B should allow only
    one open offer at a time.

Opened by phase 11:

21. **The repair appointment is not validated against the workshop's own terms.** Admin may
    confirm a start date earlier than the quotation's `earliest_repair_start`, or a
    duration different from its `processing_days`; the form seeds from them but nothing
    enforces them. Deliberate — Admin may have agreed something else by phone — but
    confirm no warning is wanted.
22. **Rescheduling is silent and unversioned.** Changing a confirmed appointment
    overwrites the previous values with no history entry, so "when was it moved, and from
    what" is unanswerable. The status change is audited; the date change is not. §15 says
    changes should be stored as historical events, so this may need an audit row.
23. **`estimated_processing_days` implies an end date nobody computes.** Neither the
    customer timeline nor Admin shows a projected completion date, and doing so needs a
    working-day calendar (holidays) that does not exist in this codebase. Confirm whether
    a projected end date is wanted before someone naively adds calendar days.

Opened by phase 12:

24. **Billing is invisible to the customer.** `b2b_order_billing` is Admin-only; the
    customer's `billing_completed` timeline stage still keys off a published `rechnung`
    document, so an order can be billed-and-completed internally while the customer sees
    no invoice. Decide whether the customer should see the invoice reference/document.
25. **Nothing bills anything.** There is no invoice generation, no amounts on the billing
    record, and the annual service fee (§13) is never applied — it stays on the company
    master data. This is intentional for the minimal scope, but §13's "invoice contains
    accepted repair positions, service fee, transport positions" is **not** satisfied and
    waits for the Stripe/invoicing phase.
26. **Marking processed is irreversible with no correction path.** If an admin marks
    billing processed by mistake, there is no supported way back — deliberate, so a
    completed order cannot lose its justification, but it means a mistake needs a DB fix.
    Confirm whether a supervisor-level correction is wanted.

Opened by phase 13:

27. **Reminders require a running scheduler.** `Schedule::command('b2b:send-offer-reminders')`
    is registered in `routes/console.php`, but nothing sends reminders unless
    `php artisan schedule:run` is wired up on the server (cron) **and** a queue worker is
    draining the `database` queue. Neither is configured in this repo. **Reminders will
    silently never fire in an environment without both.**
28. **No per-user notification preferences.** §18 says "default user notification settings
    should include…", implying users can change them. There is no settings surface and no
    opt-out — every owner user of the vehicle receives everything. `user_preferences`
    exists as a table but is not consulted for this.
29. **Reminder cadence is fixed at daily 08:00.** §18 says "may be sent every 24 hours";
    the schedule runs once a day, so the effective spacing is 24 h but the send time is
    not configurable, and an offer published at 09:00 waits until 08:00 two days later
    for its first reminder (the 24 h check plus the daily tick). Confirm that is
    acceptable, or move to hourly ticks with the 24 h check doing the real spacing.
30. **Reminders have no upper bound.** They repeat every 24 h until acceptance, rejection,
    cancellation or expiry. An offer with no `valid_until` reminds forever.
    `reminder_count` is recorded but never enforced against a maximum.

Opened by phase 14:

31. **A `not_repairable` position inflates the reported saving.** `B2bOfferService::totals()`
    adds such a position's full appraisal amount to `appraisal_total_net` but adds nothing
    to `repair_total_net`, so its entire amount lands in `saving_net` — even though the
    damage was not repaired and will presumably still be charged at return. Phase 14 uses
    the snapshot **verbatim** and did not correct this: the snapshot is what the customer
    was shown and accepted, and rewriting `totals()` would alter presented history
    (phase 10's immutability rule). Decide whether an unrepairable position should be
    excluded from both sides of the subtraction, and if so, whether the fix applies to new
    offers only or is backfilled.
32. **Own-scope members see a narrower statistic than the existing fleet panel.**
    Phase 14 narrows both the statistics and the export to a member's own vehicles when
    `vehicle_scope = 'own'`, matching `VehicleScopeService::scopeQuery()`. The older
    `B2bAnalyticsService` (dashboard `FleetOverview`, team page) does **not** — it is
    company-wide for every member with `analytics.view`. Both are defensible; having both
    is not. §17 says "company-level", §19 says a user may only access their own permitted
    data. `FleetOverview` was deliberately left untouched (out of scope). Decide which
    reading wins and align the other.
33. **The statistics have no date range.** Every figure covers all time; monthly volume is
    fixed at the last 12 months. §17 does not ask for filtering, but "total savings" grows
    forever and will eventually stop being a useful headline. No filter UI, no
    year-to-date, no comparison to a previous period.

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
