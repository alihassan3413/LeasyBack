# B2B Implementation Handoff

Working document for continuing the LeasyBack B2B leasing-return portal.
Branch: `feat/b2b-flow` *(renamed from `feat/admin-chat` during phase 14 — verify with
`git rev-parse --abbrev-ref HEAD` rather than trusting this line)*.
Stack: Laravel 12 / PHP 8.4 / Inertia v2 / Vue 3 / **Tailwind 4**.

⚠ *(Corrected 2026-08-06: this line and `CLAUDE.md` both say Tailwind 3. The repository
actually runs **Tailwind v4** — `package.json` has `tailwindcss ^4.2.4` with
`@tailwindcss/vite`, not the v3 PostCSS setup. Trust `package.json`, not either document,
when writing styles. `CLAUDE.md` was deliberately left unchanged: it is generated
Laravel Boost guidance covering the whole project, not a B2B artefact, and correcting it
is a separate call — see unresolved item 39.)*

**Requirement source: `b2b.txt`** (repo root, 355 lines). It is the authoritative
specification — section numbers below (§n) refer to it. Where it conflicts with
existing code, the code path must be reported before business logic changes (§21).

**Sections 1–11 cover the B2B portal. Section 12 covers the Partner API**, a separate
workstream (machine-to-machine integration under `/api/v1/partner`) that reuses the
portal's company/membership/permission machinery but changes none of it. `b2b.txt` does
not specify it; if you are here for the portal, stop at §11.

---

## 1. Completed phases

Phases 1–17 are implemented and verified — **all planned phases are done**, and both
defects the phase 17 audit found are fixed (17.1, 17.2). Phases 1–8 are in commit
`d79a473` and phases 9–14 in `d792020`; **phases 15 through 17.2 are uncommitted**
(see §6a). Each was delivered under the standing constraints in §7 of this document.

⚠ **Done does not mean §20 is fully satisfied.** Phase 17's audit found one criterion
structurally unmet (Lexware, dropped by product decision), seven proven only by probe, one
medium-severity defect (fixed — 17.1) plus an analytics inconsistency (fixed — 17.2), and
three unmet §19 security requirements. See the phase 17 section
and §10 items 44–49 before treating this as shippable.

*(Corrected 2026-08-06: revisions before phase 15 said phases 9–14 were uncommitted, with
a file-by-file list. That was already wrong by the start of phase 15 — see §6a.)*

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
| `database/migrations/2026_08_05_000002_add_b2b_fleet_fields_to_vehicles_table.php` | `mileage`, `contract_number`, `cost_centre`, `driver_name`, `driver_contact`, `collection_address_profile_id` — **six columns, and only these six** |
| `app/Modules/UserProfile/Vehicle/Models/Vehicle.php` | `B2B_ONLY_ATTRIBUTES` + `toArray()` strip |
| `Vehicle/Http/Requests/{Store,Update}VehicleRequest.php`, `Requests/Concerns/` | shared B2B rules |
| `Vehicle/Services/VehicleService.php` | persistence |
| `resources/js/components/vehicle/AddVehicleModal.vue`, `VehicleRow.vue`, `pages/Dashboard.vue`, `types/vehicle.ts` | UI |

B2B-only fields are stripped at **serialization** level, so a B2C payload can never
carry them even if a row somehow has values.

⚠ **`leasing_end_date` is not a phase 2 column.** It predates this phase and is a shared
B2C/B2B column, sitting on `vehicles` alongside `license_plate`, `vin`, `make` and `model`.
*(Corrected 2026-08-06: §9's phase 15 prompt listed it among "the phase 2 B2B fleet
columns". Confirmed against the migration, which adds the six columns above and not this
one. It is validated by `VehicleRules::commonFields()`, not `b2bFields()` — so unlike the
six, it is **not** `prohibited` on a B2C payload.)*

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
10. `VehicleRules` (phase 15) is the single rule set for both channels. Its
    `b2bFields(false)` branch — the six `prohibited` rules that keep a B2C payload from
    carrying a fleet field — is byte-unchanged from the trait it replaced. The four
    vehicle test files pass unmodified, which is the evidence. `VehicleRules::messages()`
    is applied by the **import only**, so B2C and Admin validation messages are untouched.
    `VehicleImportController` refuses every non-`Firmenkunde` with 403, and the
    dashboard's import button is gated on `isCompanyUser && can('vehicles.create')` —
    `can()` alone returns true for a Privatkunde.
11. `B2bOrderNoteService::forCustomerOrders()` (phase 16) applies `customerVisible()`
    internally and takes no flag that could widen it, so an internal note cannot reach a
    customer payload. Nothing in `app/Mail` or `B2bStatisticsService` reads
    `b2b_order_notes` at all, so emails and the export are covered structurally. The
    customer payload attaches `notes` only inside the existing `vehicle_belongs === 'B2B'`
    branch, so a B2C order has no `notes` key. `order_messages` and its routes are
    untouched.

**Verified** with a rolled-back probe (see §6 for the table). Two companies, a B2C order
and an own-scope member in one dataset; the arithmetic itself proves the isolation —
company B's 5 000 € order would have moved every total.

**Not done in this phase:** no date-range or per-vehicle filtering of the statistics (§17
does not ask for it); no scheduled/emailed report; no Admin-side cross-company statistics;
no CSV alternative (the xlsx is a real one, so there is nothing to fall back to).

---

### Phase 15 — Excel/CSV vehicle import (§5)

Lexware remains **blocked and untouched**. Statistics, notifications, billing and Stripe
were not touched.

| File | Change |
|---|---|
| `composer.json` / `composer.lock` | **`openspout/openspout` ^4** added — approved dependency exception, see below |
| `app/Modules/UserProfile/Vehicle/Support/VehicleRules.php` | **new** — the single definition of a valid vehicle payload |
| `app/Support/SpreadsheetReader.php` | **new** — xlsx/csv → headings + numbered rows |
| `app/Modules/UserProfile/Vehicle/Services/VehicleImportService.php` | **new** — the only importer |
| `app/Http/Controllers/B2b/VehicleImportController.php` | **new** — `store()` + `template()`, 403s on non-Firmenkunde |
| `Vehicle/Http/Requests/StoreVehicleRequest.php`, `UpdateVehicleRequest.php` | rules now delegate to `VehicleRules` |
| `Vehicle/Http/Requests/Concerns/ValidatesB2bVehicleFields.php` | **deleted** — its rules moved to `VehicleRules`; the directory is now empty |
| `Vehicle/Services/VehicleService.php` | address-profile lookup memoised per company |
| `routes/vehicles.php` | `vehicles.import`, `vehicles.import.template` |
| `app/Http/Middleware/HandleInertiaRequests.php` | one new flash key, `vehicle_import` |
| `resources/js/components/vehicle/ImportVehiclesModal.vue` | **new** — upload + per-row result report |
| `resources/js/pages/Dashboard.vue` | "Fahrzeuge importieren" button + modal |
| `resources/js/types/vehicle.ts` | `VehicleImportResult`, `VehicleImportRowError` |
| `resources/js/types/index.ts` | `FlashBag` gains `vehicle_import` (and `workshop_link`, missing since phase 9) |

**No migration.** Every §5 field already exists on `vehicles` — the six phase-2 fleet
columns plus `license_plate`, `vin`, `make`, `model`, `first_registration_date`,
`leasing_end_date` and `leasinggeber`. Importing is a second way to call
`VehicleService::createVehicle()`, not a new entity.

**Dependency exception (approved 2026-08-06, user).** §7 says "do not add dependencies".
`openspout/openspout` ^4 is an explicit, recorded exception to that rule, for one reason:
`App\Support\XlsxWriter` writes xlsx but cannot read it, and reading is materially harder
— the shared-string table, date serial numbers and cell-format resolution. Writing a
reader by hand was rejected: distinguishing a date cell from a number requires resolving a
style-format id through `styles.xml`, and getting that heuristic subtly wrong corrupts
first-registration and leasing-end dates **silently**.

- MIT, no transitive Composer dependencies. Needs `ext-zip`, `ext-xmlreader`, `ext-dom`,
  `ext-fileinfo`, `ext-filter`, `ext-libxml` — all confirmed present. `ext-zip` was
  already a de-facto requirement because phase 14's `XlsxWriter` uses `ZipArchive`.
- Streams row by row, so a large fleet file does not load into memory.
- **`XlsxWriter` was not extended and must not be.** It still writes; openspout reads.
  The import template download (`vehicles.import.template`) deliberately uses the existing
  `XlsxWriter`, because writing was already solved.
- ⚠ **Platform caveat:** openspout v4.32 requires PHP `~8.3 || ~8.4 || ~8.5`, but
  `composer.json` still declares `"php": "^8.2"`. Composer resolved against the local 8.4,
  so `composer install` would **fail on a PHP 8.2 host**. `CLAUDE.md` states the project is
  8.4. Either tighten the `php` constraint or confirm no 8.2 environment exists —
  see unresolved item 34.

**How partial success is guaranteed (§5's one hard rule).** There is deliberately **no**
transaction around the loop.

1. `VehicleService::createVehicle()` already opens its own `DB::transaction()` for the
   vehicle plus its audit row, so each row is atomic on its own. `VehicleImportService`
   adds no outer transaction — one would turn those into savepoint-nested transactions and
   let a late failure unwind rows already committed.
2. Validation is done with `Validator::make()` per row, **not** a FormRequest. A
   FormRequest aborts the whole request with a 422 on the first invalid payload, which is
   precisely the discard-everything behaviour §5 forbids.
3. Rows are validated **and written sequentially**, not validated in bulk then written.
   `license_plate` carries `unique:vehicles`; validating the whole file up front would let
   two identical plates inside one file both pass, because nothing is committed between
   the two checks. Row by row, the second sees the first one's insert.
4. Every per-row failure mode is contained in `persist()`, which catches
   `ValidationException` (via `$validator->fails()`) and `QueryException`. A row that
   fails is recorded with its **file row number** and the loop continues.

**Rule reuse.** `VehicleRules` is now the single definition. `StoreVehicleRequest::rules()`
is `VehicleRules::forCreation(...) + VehicleRules::ownership(...)`;
`UpdateVehicleRequest::rules()` is `VehicleRules::forUpdate(...)`; the import calls
`VehicleRules::forCreation(true)`. A manually created vehicle and an imported one cannot
diverge, because there is only one rule set. The old `ValidatesB2bVehicleFields` trait was
deleted rather than left delegating — after the extraction it had no callers and was pure
indirection.

German messages (`VehicleRules::messages()`) are wired into the **import only**. The app
locale is `en` and the existing forms rely on the default messages, so applying them to
`StoreVehicleRequest` would have changed what a B2C or Admin caller sees.

**Duplicate handling — decision recorded (approved 2026-08-06, user).** A row whose
registration number already exists is **rejected**. Never skipped silently, never used to
update the existing vehicle.

The deciding factor is that `vehicles_license_plate_unique` is a **global** index, not
per-company: a collision may involve another company's vehicle or a B2C one. "Update the
existing" would therefore be a potential cross-company write, and "skip silently" would
hide that from the user. The message is the generic
`Dieses Kennzeichen ist bereits vergeben.` — it does **not** disclose whether the
conflicting vehicle belongs to another company or a B2C customer (§19).

**Duplicate VIN is permitted**, matching manual creation: there is no unique index on
`vin`, so inventing an import-only constraint would have made imported and hand-entered
vehicles diverge. See unresolved item 35.

**Ownership comes from the caller, never the file.**
`VehicleService::resolveOwnership()` → `resolveFirmenkundeOwnership()` →
`B2bContext::activeCompanyIdForUserId()`. On top of that, `vehicle_belongs`, `b2b_id`,
`b2c_user_id`, `created_by_user_id`, `assigned_profile_id` and `vehicle_id` are in
`OWNERSHIP_KEYS`: they are never mapped from a heading **and** are re-stripped from every
row before validation. A file carrying those columns has them reported as ignored, not
honoured. Verified with a hostile file naming another company's `b2b_id` and
`vehicle_belongs=B2C`: the vehicle was stored as B2B under the *importer's* company.

**Column mapping.** Headings are matched case- and umlaut-insensitively
(`Straße`/`strasse`, `FIN`/`vin`). Ambiguous headings are deliberately **not** aliased:
"Telefon" and "E-Mail" both plausibly mean `driver_contact`, so neither is mapped — an
unmapped column is reported back to the user, whereas guessing would silently drop one of
two. Two headings claiming the same field is a **file-level** rejection, not a silent
first-wins.

**Value normalisation — format only, never the rule.** The rules are untouched; only the
accepted input *format* is widened before they run.

| Field | Accepts |
|---|---|
| `license_plate` | Uppercased, whitespace collapsed — exactly what `normalizePlate()` does in `LicensePlateInput` before the manual form submits, so an imported and a hand-typed plate are byte-identical and the unique index catches a case-only duplicate |
| dates | A real xlsx date cell, `15.03.2022`, `2022-03-15`, `d/m/Y`, `d-m-Y`, or a raw Excel serial. Round-tripped through the format so `32.01.2022` is rejected rather than rolled into February |
| `mileage` | `12345`, `12.345`, `12 345 km`, or a numeric cell. A non-numeric value is handed to the `integer` rule unchanged, so the row is rejected instead of silently importing `0` |

**CSV handling.** Delimiter is sniffed from the first line, counting candidates outside
quotes so a comma inside `"Musterstraße 1, Halle B"` cannot outvote a real `;`. Encoding
is sniffed from a **larger sample than the delimiter** — a heading row is very often pure
ASCII, which is valid UTF-8, so testing only the first line cleared a Windows-1252 file
whose umlauts all lived in the data rows. *(This was a real bug caught by the probe, not a
hypothetical: the first CSV run crashed on `Malformed UTF-8 characters`.)*

**Collection-address optimisation.** `resolveCollectionAddressProfileId()` used to load
**every** profile for the company and compare in PHP, once per vehicle — invisible at one
vehicle at a time, quadratic on a 200-row file where every row repeats the same depot
address. The collection is now memoised per company for the request, and newly created
profiles are pushed onto it so a later row still matches an address an earlier row just
created. **The dedupe comparison is unchanged** — still an exact match on the whole
`details` array. Verified: 3 addressed vehicles across 2 distinct addresses produced
exactly 2 profiles, with rows 2 and 5 sharing one.

**Upload validation (§19: "file type, file signature and malware validation").** The
upload is checked three ways: `max:5120` (5 MB), `extensions:xlsx,csv` (the claimed
extension) and `mimetypes:…` (the **detected** content type, via `finfo`). The last is the
signature half — verified directly: a PHP web shell saved as `evil.csv` is detected as
`text/x-php`, which is not in the allowlist, and is rejected even though its extension is
legitimate. Real `.csv` (`text/plain`) and `.xlsx`
(`application/…spreadsheetml.sheet`) files pass.

⚠ **Malware scanning is *not* implemented** — §19's third clause is unmet here, as it is
everywhere else in the app that accepts a file. Nothing in this phase makes it worse: the
upload is parsed by openspout and discarded, never stored, never served and never
executed. Flagged rather than quietly treated as covered.

**Reporting.** `import()` returns `total`, `imported`, `rejected`, `truncated`,
`ignored_columns` and `errors[]` (each with the file row number, the plate and every
message). The modal renders the counts and a per-row error table; a partial import is
shown as a partial import and flashes `warning` rather than `success` when nothing
imported. `MAX_ROWS = 2000` and anything beyond it is reported as `truncated` rather than
silently cut.

**B2C protections.**
1. The controller refuses any non-`Firmenkunde` with **403**, and refuses a Firmenkunde
   with no active membership. This is the real boundary: `EnsureB2bPermission` waves
   Privatkunde, Werkstatt and Admin **through** by design, exactly as phase 14's
   `StatisticsController` documents. Verified: Privatkunde 403, Admin 403,
   company-less Firmenkunde 403, view-only member blocked 403 by the middleware.
2. There is no Admin import surface, so `resolveOwnership()` can only take the
   Firmenkunde branch and `VehicleRules::ownership()` is never applied to an imported row.
3. The import always validates with `forCreation(true)` — the B2B rule set — because the
   caller is always a company user. The B2C `prohibited` branch is byte-unchanged.
4. The dashboard button is gated on `isCompanyUser && can('vehicles.create')`, not on
   `can()` alone: `can()` returns **true** for non-Firmenkunde accounts by design, so
   `can()` on its own would have shown the button to a Privatkunde.
5. `StoreVehicleRequest`/`UpdateVehicleRequest` behaviour is unchanged — the rules moved,
   they did not change. Proven by the four vehicle test files passing unmodified.

**Verified** with rolled-back probes. An 8-row xlsx (valid / missing plate / case-only
duplicate / shared address / short VIN / foreign-company plate / valid / non-numeric
mileage) gave **3 imported, 5 rejected**, each rejection carrying its file row number and
a German message; the duplicate row did not overwrite row 2, which kept its own
`contract_number`; `12.345 km` stored as `12345` and `15.03.2022` as `2022-03-15`; every
created vehicle was `B2B` under the importer's company with `created_by_user_id` set to
the importer. A German semicolon-delimited Windows-1252 CSV imported 2 of 3 rows with
`Käfer` and `Grünstraße` intact and a blank line skipped. The foreign company's single
vehicle was untouched throughout.

**Not done in this phase:** no dry-run/preview before committing rows; no undo of an
import; no column-mapping UI (headings must match the template's names); no update-existing
mode; no background/queued import for very large files — 2000 rows run synchronously
inside the request.

---

### Phase 16 — Order notes: internal vs customer-visible (§16)

Lexware remains **blocked and untouched**. No new dependency; the openspout exception
(§7) is for the vehicle import only and was not extended.

| File | Change |
|---|---|
| `database/migrations/2026_08_06_000001_create_b2b_order_notes_table.php` | **new** |
| `app/Modules/UserProfile/Order/Models/B2bOrderNote.php` | **new** (+ `app/Models` re-export) |
| `app/Modules/UserProfile/Order/Services/B2bOrderNoteService.php` | **new** — the only reader/writer |
| `app/Http/Controllers/Admin/OrderNoteController.php` | **new** — store/destroy, 404s on non-B2B |
| `routes/admin.php` | `admin.orders.notes.store` / `.destroy` |
| `app/Modules/UserProfile/Admin/Services/AdminQueryService.php` | `notes` on `orderDetail()`; new constructor dep |
| `app/Modules/UserProfile/Vehicle/Services/VehicleService.php` | customer-visible `notes` on the B2B order payload; new constructor dep |
| `resources/js/components/admin/AdminOrderNotesCard.vue` | **new** — write + list, audience required |
| `resources/js/components/vehicle/VehicleExpandedPanel.vue` | customer "Hinweise von Leasyback" block |
| `resources/js/pages/Admin/Orders/Show.vue` | card + `#order-section-notizen` anchor |
| `resources/js/types/admin.ts`, `types/vehicle.ts` | `AdminOrderNote`, `CustomerOrderNote` |

**Storage decision — a new table, not a discriminator on `order_messages`.**

```
b2b_order_notes
  id uuid pk | order_id uuid → leasyback_orders.id (cascade) | auftragsnummer text idx
  visibility varchar(16) default 'internal'   ('internal' | 'customer')
  body text | author_user_id → users.id (nullOnDelete) | author_name | timestampsTz
  index (order_id, visibility)
```

The three candidates and why this one:

- **`order_messages` + a `visibility` column — rejected.** It is a two-way customer↔Admin
  thread with read state, unread counts, a broadcast event and a notification path that
  emails the *other side*. An "internal message" would be an invisible turn inside a
  conversation: `unreadCount()` would have to learn to skip it, `notifyRecipients()` would
  otherwise mail the internal note straight to the customer, and every future reader of
  that table would inherit the trap. A note is one-way and has no reply.
- **Generalising `leasyback_order_logistics.internal_note` — rejected.** It is one column
  about the *collection appointment*, not attributable, not timestamped, and cannot hold
  more than one note. It is left exactly as it is.
- **A new table — chosen.** Nothing existing can hold an attributable, timestamped,
  audience-scoped list of order annotations. Same bar phase 8 cleared for
  `b2b_appraisal_positions`.

**§21 compliance — this is not a second messaging system.** Notes have no read state, no
unread count, no reply path and no notification, deliberately. `OrderMessage`,
`OrderMessageService`, `OrderMessageController` and `routes/orders.php` were **not
touched**. Notes are Admin-authored annotations; the thread remains the two-way channel.

**Authorship survives account deletion.** `author_name` is a snapshot taken at write time
and `author_user_id` is `nullOnDelete` — the same pattern `order_messages.sender_name`
already uses, rather than a second approach to the same problem.

**How isolation is enforced — separate methods, not a flag.** `B2bOrderNoteService` gives
the two audiences different entry points:

| Reader | Method | Guarantee |
|---|---|---|
| Admin API | `forOrder()` | both audiences; each row carries `visibility` |
| Customer API | `forCustomerOrders()` | `scopeCustomerVisible()` applied **inside**; no parameter exists that could widen it, and the presented row omits `visibility` entirely |
| Emails | — | nothing in `app/Mail` or `OrderMailer` reads this table |
| Excel export | — | `B2bStatisticsService::exportRows()` does not read this table |

This is deliberately *not* the `$includeInternal` boolean that
`OrderCollectionService::forOrders()` uses. A boolean defaults, gets forwarded and is
eventually passed wrong; there is no argument to `forCustomerOrders()` that returns an
internal note. Emails and the export are covered **structurally** — a new table nothing
else reads — rather than by a filter that could be forgotten.

**Default visibility: none.** `visibility` is `required` in
`B2bOrderNoteService::rules()`, so a payload that omits it is **rejected**, and the form's
submit button stays disabled until an audience is picked. §16's "must be clearly marked as
customer-visible before saving" is therefore enforced server-side, not just in the UI. The
column's own `default('internal')` exists only as a database-level backstop: the
recoverable failure is a note the customer cannot see, never an internal remark that
leaks.

**Who may write.** Admin only. §16 gives company users the right to *see* customer-visible
notes, not to author them, and their own writing surface is the existing `order_messages`
thread. No new `B2bPermission` was introduced.

**B2C protections.**
1. `OrderNoteController` resolves the order's **persisted** vehicle and 404s unless it is
   B2B, on both routes — matching `AppraisalPositionController`.
2. `B2bOrderNoteService::create()` returns null for a non-B2B vehicle, so even a direct
   service call writes nothing. Verified: **0 rows** written for a B2C order.
3. `orderDetail()` sets `notes` to `null` for B2C, so the Admin card cannot render.
4. The customer payload attaches `notes` only inside the existing
   `vehicle_belongs === 'B2B'` branch, so a B2C order has no `notes` key at all.
5. `delete()` is scoped by `order_id` **and** `id`, so a note id from another order is not
   deletable. Verified.

**Verified** with a rolled-back probe planting `GEHEIM-INTERN-XYZZY` in an internal note:
the Admin payload contains it and lists both notes with their visibilities; the **customer
payload does not contain it** while still carrying the customer-visible note, whose keys
are exactly `id, body, author_name, created_at` — **no `visibility`**; the Excel export
contains neither the sentinel nor a `notes` key; **6 rendered mails** contained no
sentinel; a B2C order wrote 0 rows, resolved `notes` to null and carried no `notes` key;
validation rejected a missing visibility, a bogus `public` visibility and an empty body,
and accepted both legal values; deleting a note id against the wrong order returned false.

**Not done in this phase:** notes cannot be edited after saving (delete and rewrite);
changing a note's visibility after the fact is not offered, so an internal note cannot be
"promoted" to customer-visible; no notification when a customer-visible note is added (§18
lists no such trigger); notes do not appear on the timeline or in the task queue; the
customer sees notes on the vehicle panel only, not per timeline stage.

---

### Phase 17 — Acceptance audit (§19, §20)

The final gate. Unlike phases 1–16 this one **adds almost no behaviour** — its deliverable
is automated coverage plus an honest report of what is still unproven or unmet. **No
business logic was changed.** Two defects were found and are reported, not silently fixed.

| File | Change |
|---|---|
| `tests/Feature/B2b/Concerns/BuildsB2bCompanies.php` | **new** — company/member/vehicle/order scaffolding |
| `tests/Feature/B2b/CrossCompanyIsolationTest.php` | **new** — 7 tests (1 skipped, see finding 1) |
| `tests/Feature/B2b/B2bPermissionMatrixTest.php` | **new** — 7 tests |
| `tests/Feature/B2b/B2bCompletionGateTest.php` | **new** — 5 tests |
| `tests/Feature/B2b/B2bChannelSeparationTest.php` | **new** — 7 tests |
| `tests/Feature/B2b/B2bNoteIsolationTest.php` | **new** — 9 tests |
| `tests/Feature/B2b/B2bVehicleImportTest.php` | **new** — 8 tests |
| `tests/Feature/B2b/B2bAuditTrailTest.php` | **new** — 5 tests |

**No migration, no dependency, no application file touched.**

Suite went from **406 passed / 4 skipped / 2 failed / 1603 assertions** to
**453 passed / 5 skipped / 2 failed / 1745 assertions**. The two failures are the
documented baseline ones (§6); the fifth skip is finding 1 below.

#### §20 acceptance criteria — coverage

`T` = automated test (this phase). `P` = phase probe only, proven once, not regression-protected.

| # | Criterion | State |
|---|---|---|
| 1 | A company can contain multiple users with different roles | **T** `B2bPermissionMatrixTest` |
| 2 | Users can access only their own company's information | **T** `CrossCompanyIsolationTest` — but see **finding 1** |
| 3 | A customer can create a complete B2B leasing-return order | **P** (phase 4) |
| 4 | The preferred collection date can be confirmed or rescheduled | **P** (phases 3, 11) |
| 5 | Customer and administrator see a synchronized timeline | **not covered** — the shared implementation is `customerOrderFlow.ts`; there is no frontend test harness in this repo |
| 6 | An appraisal can be uploaded and reviewed | **P** (phase 8) |
| 7 | Workshop links can collect itemized net quotations | **P** (phase 9) |
| 8 | Administrator can compare appraisal and workshop prices | **P** (phase 9) |
| 9 | The selected offer can be presented to the customer | **P** (phase 10) |
| 10 | The customer can accept or reject it | **P** (phases 10, 10.1) |
| 11 | Approval triggers the correct next admin task | **P** (phases 7, 9.1) |
| 12 | Workshop commissioning and repair appointments without duplicate tasks | **P** (phase 11) |
| 13 | Mandatory billing prevents premature completion | **T** `B2bCompletionGateTest` |
| 14 | **Lexware receives an editable invoice draft** | ❌ **NOT SATISFIED** — Lexware was dropped by product decision (phase 12). Deliberate and approved, but §20 is not met as written |
| 15 | Internal notes never appear to customers | **T** `B2bNoteIsolationTest` (both note stores) |
| 16 | Statistics and Excel exports use company-scoped data | **T** `CrossCompanyIsolationTest`, `B2bNoteIsolationTest` |
| 17 | All critical actions recorded in the audit history | **T** `B2bAuditTrailTest` — status changes and vehicle creation. **Offer approval/rejection auditing is `P` only** (`OfferService::auditOffer`) |
| 18 | Existing B2C behavior remains completely unchanged | **T** `B2bChannelSeparationTest`, plus all 406 pre-existing tests still green |
| 19 | Regression, authorization and cross-company isolation tests pass | **T** — genuinely true for the first time |

Seven criteria (3, 4, 6–12) remain probe-only. They are the multi-step workflow ones,
each needing a long fixture chain; they were left for a follow-up rather than rushed.

#### Findings — defects (reported, NOT fixed)

**Finding 1 — `vehicle_scope = 'own'` is not enforced on the vehicle listing.** Severity:
**medium**, data disclosure inside a company. ✅ **RESOLVED 2026-08-06 — see phase 17.1
below.** The description that follows is the defect as originally found.

`VehicleScopeService::scopeQuery()`, `findVehicleWithAccess()` and `VehiclePolicy` all
narrow correctly to the member's own vehicles. `VehicleService::scopedVehicleQuery()` —
which backs `paginateVehiclesWithOrders()` (the dashboard) and `listVehiclesWithOrders()`
— filters on `b2b_id` **alone** and never consults the membership. An own-scope member is
therefore listed every vehicle in the company, with plate, VIN, make, model, orders,
offers and documents, while `Dashboard.vue` tells them *"Sie sehen nur die Fahrzeuge, die
Sie selbst angelegt haben."* Opening a colleague's vehicle is still correctly refused, so
this is a **listing** leak, not a detail-access one. Cross-company isolation is unaffected.

Verified by probe: `scopeQuery` → `A-MINE 1`; `findVehicleWithAccess` on a colleague's
vehicle → null; `VehiclePolicy::view` → false; **dashboard list → `A-MINE 1, A-THEIRS 2`**.

`CrossCompanyIsolationTest::test_an_own_scope_member_sees_only_the_vehicles_they_registered`
carries the correct expectation and is **skipped** with a reference to this finding. Fixing
it means teaching `scopedVehicleQuery()` about the membership — which needs a decision,
because it currently takes an `$ownerId` string and has no access to the acting user.
Related: unresolved item 32, which flagged the same inconsistency in the opposite
direction for `B2bAnalyticsService`.

**Finding 2 — canonical/shim model hints are inconsistent, and it is a latent TypeError.**
Severity: **low**, code health.

`B2bBillingService::update()`, `OrderCollectionService::updateByAdmin()` and
`VehiclePolicy::view()` type-hint `App\Models\Vehicle` (the shim). `$order->vehicle`,
every factory and every relation return `App\Modules\...\Vehicle` (the canonical). The
shim **extends** the canonical, so canonical does **not** satisfy a shim hint: passing
`$order->vehicle` to any of those throws a `TypeError`. Today's controllers happen to load
the shim directly, so nothing is broken in production — but this bit three separate times
while writing phase 16 and 17, and the next caller that reaches for `$order->vehicle` will
hit it. `VehicleScopeService::resolveOwnerUsers()` already documents the correct pattern
(hint the canonical, which accepts both). Recommend standardising on that.

#### §19 security requirements — audit

| Requirement | State |
|---|---|
| Secure password hashing | ✅ `'password' => 'hashed'` cast on `User` |
| Secure password-reset tokens, expiring + single use | ✅ Laravel's broker, `expire 60`, `throttle 60` |
| Time-limited, single-use invitation tokens | ✅ `Str::random(64)`, only `hash('sha256', …)` stored, `expires_at`, `accepted_at` guard |
| Role-based authorization on every endpoint | ✅ now tested (`B2bPermissionMatrixTest`) |
| Strict company-level data isolation | ⚠️ cross-company ✅ tested; **intra-company own-scope broken — finding 1** |
| Audit logs for status changes and approvals | ✅ status changes tested; offer approvals audited but untested |
| File type, file signature and malware validation | ⚠️ type ✅ and signature ✅ (a PHP shell renamed `.csv` is detected as `text/x-php` and rejected). **Malware scanning is NOT implemented** anywhere in the app |
| Private document storage with signed download URLs | ✅ `documents` disk is `visibility: private`; `temporaryUrl(…, 3h)` |
| Rate limiting for login and public endpoints | ✅ `LoginRequest::ensureIsNotRateLimited()`; workshop `30,1`/`10,1`; invitations `30,1`/`10,1`; api `5,1`/`10,1` |
| CSRF protection | ✅ Laravel `web` middleware group |
| Secure cookies and HTTPS only | ⚠️ `session.secure` resolves to **null** (not enforced). `ValidateProductionConfig` warns, so it is caught at deploy time rather than by default |
| No secrets or credentials in frontend code | ⚠️ **`VITE_GOOGLE_PLACES_API_KEY` is a real-looking Google key committed in `.env`.** `VITE_*` vars are compiled into the client bundle by design, so it is public by construction — it must be restricted by HTTP referrer on the Google console, and it should not be in a committed file. Nothing else was found |
| Generic public error messages, no stack traces | ⚠️ branded error pages exist (commits `0822a22`, `844ca59`); `APP_DEBUG=true` in the local `.env`, flagged **critical** by `ValidateProductionConfig` |
| Revocable public workshop links | ✅ `revoked_at` + `findOpenByToken()` returns null for revoked/expired/submitted alike |
| GDPR-compliant retention and deletion workflows | ❌ **NOT IMPLEMENTED** — no retention policy, no purge, no anonymisation anywhere in `app/` |
| Hosting/data storage in AWS Frankfurt "where configured" | ⚠️ **`AWS_DEFAULT_REGION=us-east-1`** in both `.env` and `.env.example`. Wherever S3 is switched on, it currently points at N. Virginia, not `eu-central-1` |

**Three §19 items are unmet and need a product/infra decision, not a code change here:**
malware scanning, GDPR retention/deletion, and the AWS region default. The Google Maps key
and `session.secure` are configuration hygiene.

**Not done in this phase:** the seven probe-only §20 criteria were not converted to tests;
no frontend/TS test harness was introduced (criterion 5 stays uncovered); neither finding
was fixed; no §19 gap was implemented.

### Phase 17.1 — Finding 1 fixed: own-scope narrowing on the vehicle listing (2026-08-06)

Scoped strictly to finding 1. Finding 2 and every §19 gap are untouched.

| File | Change |
|---|---|
| `Vehicle/Services/VehicleScopeService.php` | **new** `ownVehicleRestrictionFor(User): ?int`; `scopeQuery()` now uses it instead of inlining the same test |
| `Vehicle/Services/VehicleService.php` | `scopedVehicleQuery()` applies the restriction; `paginateVehiclesWithOrders()`, `listVehiclesWithOrders()`, `findVehicleWithOrders()` take an optional `?User $viewer`; `VehicleScopeService` injected |
| `app/Http/Controllers/VehicleController.php` | both call sites pass the acting user |
| `tests/Feature/B2b/CrossCompanyIsolationTest.php` | the skipped test is **un-skipped**; 3 tests added guarding against over-narrowing |

**Approach — one rule, two applications (option (c) adapted).** Routing the listing through
`VehicleScopeService::scopeQuery()` outright was rejected after inspection: `scopeQuery()`
takes an **Eloquent** builder, while `scopedVehicleQuery()` is a `DB::table('vehicles as v')`
query-builder statement whose `v.`-aliased columns `applyVehicleFilters()` and
`hydrateVehicles()` both depend on. Converting it would have been a broad refactor of code
the fix does not otherwise need to touch.

Instead the *decision* was extracted into `VehicleScopeService::ownVehicleRestrictionFor()`
and both mechanisms now ask it — `scopeQuery()` for policies and detail lookups, the
listing for the dashboard. There is one definition of "is this member restricted to their
own vehicles, and to whose id", which is what the defect was missing; the two query
builders keep their own way of applying it.

**Why `$viewer` is nullable.** Admin-side callers have no member scope to apply, and the
company/owner filter is applied regardless of it, so null never *widens* access beyond the
company. Both production call sites (`VehicleController::index()` and `::show()`) pass the
acting user. ⚠ **Residual risk, stated plainly:** a future direct service call that omits
`$viewer` silently gets the company-wide list. That is the same "a boolean defaults and
gets forgotten" trap phase 16 avoided by using separate methods, and it was accepted here
only because a required parameter cannot follow the existing optional ones without
reordering three public signatures. If a third caller appears, reorder them.

**B2C untouched.** `ownVehicleRestrictionFor()` returns null for any non-`Firmenkunde`, so
the Privatkunde path (`b2c_user_id`) and Admin (`ALL`) are unreachable by the new clause.
Covered by a test.

**Verified.** The original probe now reports, for the same own-scope member:
`scopeQuery` → `A-MINE 1`; detail access to a colleague's vehicle → refused;
`ownVehicleRestrictionFor` → the member's user id; **dashboard list → `A-MINE 1` (total 1)**,
previously `A-MINE 1, A-THEIRS 2`. Four tests cover it end to end over HTTP: own-scope
narrowed, company-wide member *not* narrowed, owner *not* narrowed, Privatkunde unaffected.

**⚠ Consequence — unresolved item 32 is now a visible inconsistency, not a latent one.**
*(Closed 2026-08-06 by phase 17.2, below.)*
`B2bAnalyticsService::summary(string $b2bId)` takes only a company id and has no member
scope at all. The dashboard's `FleetOverview` tiles are therefore still company-wide while
the vehicle table beneath them is now narrowed, so an own-scope member can see
"12 Fahrzeuge" above a list of 3. **Deliberately not fixed here** — it is a different
service and outside "finding 1 only" — but it should be the next thing decided, and the
direction is now set by this fix.

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

### Verification results — phase 15

| Check | Result |
|---|---|
| `php artisan test --compact` | 406 passed, 4 skipped, **2 failed** (the two baseline ones), 1603 assertions — identical to the phase 14 baseline, no third failure |
| `php artisan test --compact` (4 vehicle files) | 54 passed (439 assertions) — `Api/VehicleControllerTest`, `Admin/VehicleControllerTest`, `VehicleDashboardControllerTest`, `OnboardingControllerTest` |
| `php artisan migrate` | not run — **phase 15 adds no migration** |
| `vendor/bin/pint --dirty --format agent` | `passed` |
| `npm run build` | `✓ built in 50.15s`, exit 0 |
| `npx eslint` (4 changed frontend files) | exit 0, no output |
| `php artisan route:list --path=vehicles/import` | both routes registered |

The four vehicle test files are the ones that exercise `StoreVehicleRequest`/
`UpdateVehicleRequest`/`createVehicle` — the existing behaviour the rule extraction could
have broken. They passed **unmodified**, which is the evidence that moving the rules
changed nothing for B2C or Admin. No test was added or changed, per the standing brief.

**`HandleInertiaRequests.php` was modified this phase, and one of the two baseline
failures lives in `HandleInertiaRequestsTest`** — so it was re-verified rather than
assumed, exactly as in phase 9. With that single file stashed
(`git stash push -- app/Http/Middleware/HandleInertiaRequests.php`) the test still fails
identically with *Not a valid Inertia response* at `AssertableInertia.php:84`. The added
flash key is not the cause.

Untested by automation for the import itself, same caveat as phases 8–14. If tests are
ever allowed, the three highest-value cases are: a file with one invalid row still commits
the valid ones; a Privatkunde gets 403 while a company owner does not; a file naming
another company's `b2b_id` still stores the vehicle under the caller's company.

### Verification results — phase 16

| Check | Result |
|---|---|
| `php artisan test --compact` | 406 passed, 4 skipped, **2 failed** (the two baseline ones), 1603 assertions — no third failure |
| `php artisan test --compact` (5 focused files) | 57 passed (476 assertions) — `Admin/OrderControllerTest`, `Admin/VehicleControllerTest`, `Api/Admin/AdminControllerTest`, `Api/Admin/AdminOrdersTest`, `VehicleDashboardControllerTest` |
| `php artisan migrate` | `2026_08_06_000001_create_b2b_order_notes_table` DONE (batch 17) |
| `vendor/bin/pint --dirty --format agent` | `passed` |
| `npm run build` | `✓ built in 48.05s`, exit 0 |
| `npx eslint` (5 changed frontend files) | exit 0, no output |
| `php artisan route:list --path=notes` | both routes registered |

The five focused files are the ones exercising `AdminQueryService::orderDetail()` and
`VehicleService::listVehiclesWithOrders()` — the two payload builders this phase adds a
key to, and the two whose constructors gained a dependency. They passed unmodified.

Untested by automation, same caveat as phases 8–15. If tests are ever allowed, the three
highest-value cases are: an internal note is absent from the customer payload; the store
route 404s on a B2C order; a note saved without `visibility` is rejected.

### Phase 17.2 — Item 32 closed: analytics aligned with the vehicle scope (2026-08-06)

Scoped strictly to unresolved item 32. Phase 14's `B2bStatisticsService`, the Excel
export, offers, billing, notifications and Lexware were **not** touched.

| File | Change |
|---|---|
| `B2B/Services/B2bAnalyticsService.php` | `summary()` takes a **required** `User $viewer`; `totals()` and `vehicleStates()` narrow by the viewer's vehicle scope; `VehicleScopeService` injected |
| `app/Http/Controllers/VehicleController.php` | dashboard passes the acting user |
| `app/Http/Controllers/B2b/MemberController.php` | team page passes the acting user |
| `tests/Feature/B2b/B2bAnalyticsScopeTest.php` | **new** — 8 tests |

**The three surfaces now agree.** All of them answer "which vehicles count for this
member" through the one decision source, `VehicleScopeService::ownVehicleRestrictionFor()`:

| Surface | Before | After |
|---|---|---|
| `B2bStatisticsService` (phase 14) | narrowed ✅ | narrowed ✅ |
| `VehicleService` listing (phase 17.1) | narrowed ✅ | narrowed ✅ |
| `B2bAnalyticsService` (this phase) | **company-wide ❌** | narrowed ✅ |

**`$viewer` is required here, and that is deliberate.** Phase 17.1 made the equivalent
parameter optional and recorded the trap it leaves — a caller that forgets it silently
gets company-wide data. There are only two call sites here and both have the request user
to hand, so the trap is **closed rather than repeated**. Phase 17.1's own residual risk is
unchanged and still stands.

**Narrowed by the vehicle's creator, not the order's.** `totals()` counts orders joined to
vehicles and filters on `v.created_by_user_id`, so the figures describe exactly the
vehicles the viewer's dashboard list shows. Filtering on `lo.created_by_user_id` would
have produced a third, subtly different set — orders *this member booked*, which is the
per-member breakdown's question, not the tiles'.

**`memberBreakdown()` is deliberately NOT narrowed.** It groups *by* member and exists to
answer "who is contributing what"; narrowing it to the viewer would collapse the panel to
a single row. It is gated on `members.view`, a separate permission from the vehicle scope.
Covered by a test that asserts both members still appear with their own counts.

**Note on the team page.** For an own-scope member with `analytics.view` the team page's
summary cards now show *their* figures while the per-member table below still lists
everyone. That is the intended reading — cards describe the viewer, the table describes
the company — and it is a non-event for the common case, since owners are never own-scope.

**Cross-company isolation unchanged.** Every query still pins `b2b_id`; the new clause only
ever narrows further. No company id or user id is accepted from request input — the company
comes from `B2bContext::activeMembership()` and the viewer from `$request->user()`.

**B2C unchanged.** `ownVehicleRestrictionFor()` returns null for any non-`Firmenkunde`, and
a Privatkunde has no membership so no analytics object is built at all. Asserted directly:
their dashboard still returns `analytics === null` and their own vehicle.

**Verified** by 8 tests: own-scope totals (1 of 3 vehicles, 1 of 2 open orders), company-wide
member (3 / 2), owner (2 / 1), a second company's vehicles excluded, the per-member
breakdown still reporting both members, and two HTTP tests asserting the FleetOverview
buckets **sum to the number of vehicles actually listed** — the specific contradiction item
32 described.

### Verification results — phase 17.2

| Check | Result |
|---|---|
| `php artisan test --compact` | **465 passed, 4 skipped, 2 failed**, 1778 assertions — the 2 are the documented baseline, the 4 skips are the baseline set |
| `php artisan test --compact` (focused: `tests/Feature/B2b`, `VehicleDashboardControllerTest`, `DashboardTest`) | 70 passed (255 assertions) |
| `vendor/bin/pint --dirty --format agent` | `passed` |
| `npm run build` / `npx eslint` | not run — phase 17.2 changes no frontend file (`FleetOverview.vue` consumes the same payload shape) |
| `php artisan migrate` | not run — no migration |

### Verification results — phase 17.1

| Check | Result |
|---|---|
| `php artisan test --compact` | **457 passed, 4 skipped, 2 failed**, 1755 assertions. The skip count is back to the documented baseline of 4 — finding 1's skip is gone |
| `php artisan test --compact` (focused: `tests/Feature/B2b`, `VehicleDashboardControllerTest`, `Policies/VehiclePolicyTest`, `Api/VehicleControllerTest`) | 78 passed (265 assertions) |
| `vendor/bin/pint --dirty --format agent` | `passed` |
| `npm run build` / `npx eslint` | not run — phase 17.1 changes no frontend file |
| `php artisan migrate` | not run — no migration |

### Verification results — phase 17

| Check | Result |
|---|---|
| `php artisan test --compact` | **453 passed, 5 skipped, 2 failed**, 1745 assertions — was 406/4/2/1603 before this phase. The 2 failures are the documented baseline ones; the 5th skip is finding 1 |
| `php artisan test --compact tests/Feature/B2b` | 47 passed, 1 skipped (142 assertions) |
| `php artisan migrate` | not run — **phase 17 adds no migration** |
| `vendor/bin/pint --dirty --format agent` | `passed` (after one auto-fix pass on import ordering) |
| `npm run build` / `npx eslint` | not run — **phase 17 changes no frontend file** |

**The "untested by automation" caveat carried since phase 8 is now partially discharged.**
47 tests cover cross-company isolation, the permission matrix, the completion gate,
channel separation, note isolation, the vehicle import and the audit trail. Seven §20
criteria remain probe-only (see the table above) — that is the honest remaining gap.

## 6a. Current working-tree state (after phase 17.2, 2026-08-06)

Branch **`feat/b2b-flow`**. *(Corrected 2026-08-05: every earlier revision of this section
said `feat/admin-chat`. The branch was renamed/switched during phase 14 — check
`git rev-parse --abbrev-ref HEAD` rather than trusting a branch name written here.)*

All ten B2B migrations are applied locally (`migrate:status` batches 8–17). Phases 14 and
15 add none; **phase 16 adds one**, `b2b_order_notes` (batch 17).

**Phases 15, 16, 17, 17.1 and 17.2 are uncommitted.** Everything through phase 14 is
committed (below); the working tree holds phase 15's vehicle import, phase 16's order
notes, phase 17's test suite, phase 17.1's listing-scope fix, phase 17.2's analytics-scope
fix, and this document. The per-phase file tables in §1 are the list of what each touched
— check `git status --porcelain` rather than trusting a file list written here, which is
the mistake every earlier revision of this section made.

**Phases 1–8 are in `d79a473`** (57 files, +4584/−84), together with the 7.1 correction.
Its message — `feat: add service fee fields to b2b table and update existing records` —
describes only phase 1.

**Phases 9–14 are in `d792020`** (61 files, +5591/−143), together with the 9.1 and 10.1
corrections. Its message — `feat: add workshop quotations functionality` — describes only
phase 9.

⚠ **Both commit messages name only their first phase.** Do not go looking for phases 2–8
or 10–14 in later commits; they are inside those two. If you are bisecting or reviewing,
read the diffs, not the subjects.

*(Corrected 2026-08-06: every revision of this section before now claimed phases 9–14 were
uncommitted, with a file-by-file list of 29 modified and 31 untracked paths. That was true
when written and was already wrong by the start of phase 15 — the work had been committed
in `d792020` in the meantime. The stale list has been removed rather than left to mislead;
the per-phase file tables in §1 are the authoritative record of what each phase touched.)*

Phase 10 is the first phase to modify files B2C depends on at runtime
(`OfferService::publishOffer`, `VehicleService`'s customer payload, `OfferPolicy`), so
review those three with more care than the additive B2B-only files. Phase 15 is the
second: it rewrites `StoreVehicleRequest`/`UpdateVehicleRequest`'s rule sources, which
both channels use. Phase 16 touches `VehicleService`'s customer payload again, but only
inside its existing `vehicle_belongs === 'B2B'` branch.

Note `b2b.txt` and this handoff are tracked, so spec/handoff edits show as modifications
rather than untracked files.

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
  **One approved exception exists: `openspout/openspout` ^4** (phase 15, approved by the
  user 2026-08-06) — the only way to *read* an xlsx, since `App\Support\XlsxWriter` only
  writes and must not be extended into a reader. This exception is for that package and
  that purpose; it is not a general relaxation of the rule.
- **One vehicle rule set: `VehicleRules`** (phase 15). `StoreVehicleRequest`,
  `UpdateVehicleRequest` and `VehicleImportService` all derive their rules from it, so a
  manually created and an imported vehicle cannot diverge. Add a vehicle field there, not
  in a request class.
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

**No planned phases remain.** Phases 1–17 plus the 7.1, 9.1 and 10.1 correction passes are
done: the B2B workflow runs end to end, statistics and the Excel export exist, vehicles
can be created manually or imported in bulk, orders carry both note types, and 47
automated tests cover the isolation, permission and gate boundaries.

What remains is **remediation, not new features** — everything below came out of phase
17's audit and each needs a decision before this is shippable:

| Priority | Item | Source |
|---|---|---|
| ~~—~~ | ~~`vehicle_scope = 'own'` not enforced on the vehicle listing~~ | ✅ **fixed in phase 17.1** |
| ~~—~~ | ~~`FleetOverview` tiles company-wide for own-scope members~~ | ✅ **fixed in phase 17.2** (item 32) |
| 1 | GDPR retention and deletion workflows — not implemented at all | §19 / item 47 |
| 2 | `AWS_DEFAULT_REGION=us-east-1`, not Frankfurt | §19 / item 48 |
| 3 | Malware scanning on uploads — not implemented | §19 / item 46 |
| 4 | Google Places API key committed in `.env` and shipped in the bundle | §19 / item 49 |
| 5 | Seven §20 criteria still probe-only | phase 17 coverage table |
| 6 | Lexware (§20 #14) structurally unmet — confirm the product decision stands | item 25 |

**Every known correctness defect is now fixed.** What remains is security/compliance
configuration (1–4), test coverage depth (5) and one confirmed product decision (6).

---

## 9. Exact next phase

**None. Phases 1–17 are complete and every known correctness defect is fixed**
(finding 1 in 17.1, item 32 in 17.2).

What remains is in §8 and is no longer development work: three security/compliance gaps,
one credential to rotate, coverage depth, and one product decision to confirm. None
depends on another, so they can be taken in any order — the numbering in §8 is by risk.

The highest-value next action is **item 47, GDPR retention and deletion**. It is the
largest outright §19 gap, the only one with a legal dimension, and unlike the others it
cannot be closed by a configuration change — it needs a decision on retention periods
before any code is written.

Ready-to-use prompt:

```
Design and implement GDPR retention and deletion for the B2B portal (b2b.txt §19,
handoff unresolved item 47).

Read B2B_IMPLEMENTATION_HANDOFF.md's phase 17 §19 audit table first, then inspect what
personal data actually exists: users, user_b2b, b2b_invitations, vehicles
(driver_name/driver_contact), logistics_address_profiles, order_messages
(sender_name snapshots), b2b_order_notes (author_name snapshots),
b2b_workshop_quotations (contact_person/contact_email/contact_phone),
vehicle_documents and vehicle_report_documents (files on the `documents` disk).

STOP AND ASK BEFORE BUILDING. This phase cannot be specified from the code alone:
retention periods are a legal decision, not an engineering one. Report first:
1. A complete inventory of personal data: table, column, whose data it is (company user /
   driver / workshop contact / B2C customer), and why it is held.
2. For each, the candidate retention trigger (account deletion, membership ended, order
   completed + N years, invitation expired) — as OPTIONS, not decisions.
3. Which data cannot simply be deleted because it is load-bearing: the sender_name and
   author_name snapshots exist precisely so a record stays readable after an account
   goes, and order/billing history likely has a statutory retention period of its own
   that CONFLICTS with erasure. Name every such conflict explicitly.
4. Whether deletion should be hard delete, anonymisation, or per-table a mix — with a
   recommendation.
Then WAIT for the retention periods to be confirmed before implementing anything.

When approved, implement:
- A documented retention policy (periods per data class) in one place, not scattered.
- A scheduled command that applies it, following SendB2bOfferReminders' shape
  (withoutOverlapping()->onOneServer(), a dry-run/report mode FIRST).
- A per-subject erasure path for an explicit deletion request, distinct from scheduled
  retention.
- Documents on the `documents` disk deleted alongside their database rows — an orphaned
  file on disk is still personal data.
- Tests: data past its retention is removed/anonymised; data inside it is untouched; a
  statutory-hold record survives erasure; cross-company isolation holds throughout.

Constraints:
- Do NOT delete anything without a dry-run mode proving what it would touch.
- Do not break the sender_name/author_name readability guarantee without saying so.
- Keep B2C behaviour unchanged unless the policy explicitly covers B2C subjects.
- Do not touch statistics, offers, billing, notifications or Lexware.
- Do not add dependencies.

Report after: the policy as implemented, what the dry run reports on a seeded dataset,
every conflict between erasure and statutory retention, and what a data subject's
erasure request does and does not remove.

Update B2B_IMPLEMENTATION_HANDOFF.md: add the phase to section 1, mark item 47 resolved
or partially resolved with a dated note, refresh §8, re-point §9, and record test and
Pint results.
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

    **Escalated 2026-08-06 (phase 17.1), then RESOLVED 2026-08-06 (phase 17.2).**
    `B2bAnalyticsService::summary()` now takes a required `User $viewer` and narrows
    `totals` and `states` through `VehicleScopeService::ownVehicleRestrictionFor()` — the
    same decision source as the listing and phase 14's statistics. All three surfaces now
    agree. `memberBreakdown()` is deliberately left company-wide because it groups *by*
    member; see the phase 17.2 section for that reasoning and for the team-page nuance.
33. **The statistics have no date range.** Every figure covers all time; monthly volume is
    fixed at the last 12 months. §17 does not ask for filtering, but "total savings" grows
    forever and will eventually stop being a useful headline. No filter UI, no
    year-to-date, no comparison to a previous period.

Opened by phase 15:

34. **`composer.json` still declares `"php": "^8.2"`, but openspout v4.32 requires
    `~8.3 || ~8.4 || ~8.5`.** Composer resolved against the local PHP 8.4 and installed
    happily, so this is invisible until someone runs `composer install` on an 8.2 host,
    where it will **fail to resolve**. `CLAUDE.md` states the project is PHP 8.4, so the
    declared constraint is probably just stale. Decide whether to tighten it to `^8.3`
    (accurate, and makes the failure a clear message instead of a surprise) or to confirm
    no 8.2 environment exists. Left alone here because `composer.json`'s `php` constraint
    governs the whole project, not this phase.
35. **Duplicate VIN is permitted, and nothing warns.** There is no unique index on
    `vehicles.vin`, so manual creation has always allowed two vehicles to share a VIN, and
    the import matches that deliberately — inventing an import-only constraint would make
    imported and hand-entered vehicles diverge. But a VIN is physically unique to a
    vehicle, so a duplicate almost always means a data-entry error. Decide whether VIN
    should become unique (a migration plus a decision about existing duplicates), or
    whether the import should merely *warn* on a VIN that already exists in the company
    while still importing the row.
36. **A rejected row cannot be corrected in place.** The result panel reports the row
    number and the reason, but the user must fix the source file and re-upload it — and
    re-uploading the whole file re-rejects every already-imported row as a duplicate
    plate, which is noisy even though it is harmless and correct. An "import only the rows
    that failed" affordance, or a downloadable error file containing just the rejected
    rows, would close this. Not built because §5 asks for per-row errors, not a repair
    workflow.
37. **The import is synchronous and capped at 2000 rows.** A file at the cap runs entirely
    inside the request, which is fine at a few hundred rows but will eventually meet a PHP
    execution timeout. `MAX_ROWS` is reported as `truncated` rather than silently applied,
    so nothing is hidden, but a genuinely large fleet needs a queued import with a progress
    surface. Decide whether that is wanted before someone raises the cap.
38. **Column headings must match the template.** There is no mapping UI: an unrecognised
    heading is reported as ignored and its data is dropped. Ambiguous headings ("Telefon",
    "E-Mail") are deliberately unmapped for that reason. If customers routinely send
    leasing-company exports with their own column names, a per-company saved mapping is the
    natural next step.
39. **`CLAUDE.md` states Tailwind v3; the repo runs v4.** `package.json` has
    `tailwindcss ^4.2.4` and `@tailwindcss/vite`. `CLAUDE.md` is Laravel Boost's generated
    project-wide guidance, so correcting it is outside any B2B phase's scope, but it will
    keep misleading anyone writing styles — v3 and v4 differ in config format and several
    utility names. Decide whether to regenerate or hand-correct that line. Not a phase 15
    finding as such — noticed while inspecting the repo for it.

Opened by phase 16:

40. **A note cannot be edited, and its audience cannot be changed.** The only correction
    path is delete-and-rewrite, which loses the original timestamp and author. That is
    deliberate for the audience — silently promoting an internal note to customer-visible
    would disclose something written under the assumption it was private — but editing a
    typo in a customer note is a reasonable thing to want. Decide whether an edit window
    (with the audience locked) is wanted.
41. **Deleting a note leaves no trace.** `delete()` is a hard delete with no audit row, so
    "there used to be a note here" is unanswerable. §15 says changes should be stored as
    historical events; that rule is about status changes, but a customer-visible note the
    customer has already read and that then vanishes is arguably the same class of
    problem. Consider a soft delete.
42. **Nobody is notified about a customer-visible note.** §18's notification list names no
    such trigger, so none was invented — but a note the customer never opens the portal to
    see does nothing. The `order_messages` thread *does* notify. Decide whether adding a
    customer-visible note should behave like a message in that respect, and note that
    doing so starts to blur the two entities this phase deliberately kept apart.
43. **Two surfaces now carry Admin→customer text.** A customer-visible note and an Admin
    message in the thread look very similar to a customer, and nothing guides an admin on
    which to use. The distinction is real (a note annotates the order record; a message is
    conversation) but it is not explained anywhere in the UI. Worth a one-line hint in
    both cards, or an explicit product decision to merge them.

Opened by phase 17 (audit findings — see the phase 17 section for detail):

44. ~~**`vehicle_scope = 'own'` is not enforced on the vehicle listing.**~~ **Resolved
    2026-08-06 (phase 17.1)** — the decision moved into
    `VehicleScopeService::ownVehicleRestrictionFor()` and both the Eloquent scope and the
    dashboard's query-builder listing now ask it. The skipped test is un-skipped and three
    over-narrowing guards were added. One residual risk is recorded in the phase 17.1
    section: `$viewer` is optional, so a future direct service caller that omits it gets
    the company-wide list.
45. **Canonical/shim model type hints are inconsistent.** *(Finding 2, low severity.)*
    Several services hint `App\Models\Vehicle` while every relation and factory returns the
    canonical class, so `$order->vehicle` cannot be passed to them without a `TypeError`.
    Harmless today because controllers load the shim directly; it cost time three times
    during phases 16–17. Standardise on hinting the canonical class, as
    `VehicleScopeService::resolveOwnerUsers()` already documents.
46. **Malware scanning is not implemented.** §19 requires "file type, file signature and
    malware validation". Type and signature are done and tested; scanning is absent
    everywhere in the app, not just in B2B. Needs an infra decision (ClamAV sidecar, an
    S3-side scanner, or an accepted risk).
47. **GDPR retention and deletion workflows do not exist.** §19 requires them. There is no
    retention policy, no purge job and no anonymisation path anywhere in `app/`. This is
    the largest outright gap in §19 and needs a legal/product decision on retention periods
    before anything is built.
48. **`AWS_DEFAULT_REGION` is `us-east-1`, not Frankfurt.** §19 requires AWS Frankfurt
    "where configured". Both `.env` and `.env.example` default to N. Virginia, so any S3
    switch-on would store documents outside the EU. Change to `eu-central-1` and confirm
    the bucket's actual region.
49. **The Google Places API key is committed and shipped to the browser.**
    `VITE_GOOGLE_PLACES_API_KEY` is in `.env` and, being a `VITE_*` var, is compiled into
    the client bundle by design — so it is public by construction and must be restricted by
    HTTP referrer on the Google console. It should also not live in a committed file.
    Rotate it, restrict it, and move it out of version control.

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
php artisan route:list --path=vehicles/import
php artisan config:show database.default
```

Partner API credential commands are listed in §12.7 — they are deliberately not repeated
here, because getting a `--force` or an `--environment` wrong on one of them issues or
kills a live third-party credential.

Note `npm run build` runs **Tailwind v4**, not v3 — see the corrected stack line at the
top of this document.

Verification technique used throughout: rolled-back
`DB::beginTransaction()` / `DB::rollBack()` tinker scripts with `Http::fake()` /
`Mail::fake()` for backend flows, and esbuild-bundled TypeScript probes run under
node for frontend logic. Keep probe files in the session scratchpad, never in the repo.

---

## 12. Partner API — Phase 1 (2026-08-06)

A **separate workstream** from phases 1–17. Those built the B2B *portal* (humans in a
browser); this builds the B2B *integration surface* (partner systems over HTTP). It
reuses the portal's company, membership, permission and B2bContext machinery rather than
duplicating it, but it adds no behaviour to any existing B2B or B2C path — every file
below is new except four wiring edits (`bootstrap/app.php`, `AppServiceProvider`,
`config/scribe.php`, `.env.example`).

Phase 1 is the reusable foundation only. **No vehicles, orders, documents, offers,
webhooks, OAuth, GDPR or Lexware** — those are phase 2 onwards. The only endpoints are
`GET /health` and `GET /me`.

### 12.1 Architecture

Nothing in the code names a partner. An integration is a **database row**, so onboarding
the next one is a `partner:provision` run, not a deployment.

Three layers:

1. **Identity** — `partner_integration_clients`. One row binds one partner, one
   environment, one B2B company and one dedicated integration user. `user_id` is unique,
   so a credential cannot be re-pointed at a second company by adding a membership.
   Sandbox and production are *separate rows*, not a flag, which is what makes a sandbox
   token structurally incapable of reaching production data.
2. **Credential** — `partner_api_tokens`. SHA-256 hash only, ability-scoped, revocable,
   rotatable, optional expiry, `last_used_at`/`last_used_ip`.
3. **Request context** — `PartnerContext`, a scoped singleton established by the
   authentication middleware and read by everything downstream. It is the *only* source
   of the acting company; no controller or service derives it from input.

Why a dedicated token table rather than Sanctum's `personal_access_tokens`:
`config('sanctum.expiration')` is 24 hours application-wide, so a partner credential
stored there would silently die every day, and raising the global value would extend
every human session token with it. The second reason is blast radius — a partner token is
only accepted by the Partner API middleware and is worthless against `auth:sanctum`
(asserted by a test), and vice versa.

Why a dedicated integration user rather than reusing an employee account: every partner
write goes through the same services a human member's request does, and those ask "what
may this user do in this company". A purpose-built account with an explicit, narrower
permission set keeps the audit trail honest and the scope bounded. It has a random
unusable password, and there is no login endpoint.

**Defence in depth.** A partner request is checked twice: the *token ability* gates the
route, and the *integration user's B2B permissions* still gate the underlying service.
A mis-scoped token therefore cannot exceed what the company itself may do.

### 12.2 Files

| File | Purpose |
|---|---|
| `config/partner_api.php` | Token format, rate limits, idempotency TTL, request-id rules, rejected input keys |
| `routes/partner.php` | Versioned route group; mounted at `/api/v1/partner` by `bootstrap/app.php` |
| `app/Modules/PartnerApi/Enums/PartnerEnvironment.php` | `sandbox` \| `production`, token segment |
| `app/Modules/PartnerApi/Enums/PartnerAbility.php` | The 11 scopes + `*` wildcard constant |
| `app/Modules/PartnerApi/Enums/PartnerIdempotencyState.php` | fresh / replay / conflict / in_progress |
| `app/Modules/PartnerApi/Models/PartnerIntegrationClient.php` | Client; `b2b_id`/`user_id` deliberately **not** fillable |
| `app/Modules/PartnerApi/Models/PartnerApiToken.php` | Token; `can()`, `isRevoked()`, `isExpired()`, `resolvedAbilityValues()` |
| `app/Modules/PartnerApi/Models/PartnerExternalReference.php` | Partner id ↔ our id mapping |
| `app/Modules/PartnerApi/Models/PartnerIdempotencyKey.php` | Recorded key + stored response |
| `app/Modules/PartnerApi/Data/IssuedPartnerToken.php` | The one moment a plaintext exists |
| `app/Modules/PartnerApi/Data/IdempotencyResult.php` | Claim outcome |
| `app/Modules/PartnerApi/Services/PartnerContext.php` | Scoped singleton: client, user, company, environment, abilities, request id |
| `app/Modules/PartnerApi/Services/PartnerTokenService.php` | Issue / rotate / revoke / match / record usage |
| `app/Modules/PartnerApi/Services/PartnerClientProvisioner.php` | Atomic user + membership + client + first token |
| `app/Modules/PartnerApi/Services/PartnerExternalReferenceRegistry.php` | Two-way lookup, both directions unique |
| `app/Modules/PartnerApi/Services/PartnerIdempotencyService.php` | Claim / complete / release |
| `app/Modules/PartnerApi/Support/PartnerApiResponse.php` | The `{data,request_id}` / `{error,request_id}` envelope |
| `app/Modules/PartnerApi/Exceptions/PartnerApiException.php` | Self-rendering domain error with a code |
| `app/Modules/PartnerApi/Exceptions/PartnerApiExceptionRenderer.php` | Path-scoped renderer for everything else |
| `app/Modules/PartnerApi/Http/Middleware/AssignPartnerRequestId.php` | `X-Request-ID` in, echoed out, into `Context` |
| `app/Modules/PartnerApi/Http/Middleware/AuthenticatePartner.php` | Token → context; state checks; auth-failure throttle |
| `app/Modules/PartnerApi/Http/Middleware/ThrottlePartnerRequests.php` | Per-token budget with per-client override |
| `app/Modules/PartnerApi/Http/Middleware/EnsurePartnerAbility.php` | Scope gate, fails closed on a typo |
| `app/Modules/PartnerApi/Http/Middleware/RejectOwnershipInput.php` | Refuses `b2b_id`, `user_id`, … in query or body |
| `app/Modules/PartnerApi/Http/Middleware/EnforcePartnerIdempotency.php` | `Idempotency-Key`, optional or `:required` |
| `app/Modules/PartnerApi/Http/Controllers/HealthController.php` | `GET /health` (+ Scribe attributes) |
| `app/Modules/PartnerApi/Http/Controllers/MeController.php` | `GET /me` (+ Scribe attributes) |
| `app/Console/Commands/Partner/*.php` | 5 commands + 2 shared/abstract bases |
| `database/factories/PartnerIntegrationClientFactory.php`, `PartnerApiTokenFactory.php` | Test/seed support |
| `tests/Feature/PartnerApi/**` | 8 test files + `Concerns/BuildsPartnerClients` |

Modified: `bootstrap/app.php` (route mount, 6 middleware aliases, renderer),
`app/Providers/AppServiceProvider.php` (scoped `PartnerContext`), `config/scribe.php`
(second route group), `.env.example` (6 documented knobs, no secrets).

> Phase 2 adds 10 files and a 7th middleware alias — see **§12.12.2**.

### 12.3 Migrations

All four applied locally (SQLite, batch 18); Postgres in production.

| Migration | Table | Notes |
|---|---|---|
| `2026_08_06_000002` | `partner_integration_clients` | `unique(slug, environment)`, `unique(user_id)`, FKs `b2b_id`/`user_id` restrict-on-delete |
| `2026_08_06_000003` | `partner_api_tokens` | `unique(token_hash)`, cascade on client delete |
| `2026_08_06_000004` | `partner_external_references` | Unique in **both** directions per client + type |
| `2026_08_06_000005` | `partner_idempotency_keys` | `unique(client, key)`, `expires_at` index |

### 12.4 Authentication design

No login endpoint. `Authorization: Bearer lbp_{sbx|live}_{64 hex}`.

Middleware order is load-bearing (see the comment block in `routes/partner.php`):
`partner.request-id` → `partner.auth` → `partner.throttle` → `partner.no-ownership`.
`partner.ability:…` is applied **per route**, not on the group, so `/health` and `/me`
stay scope-free and a partner can verify a fresh credential before any feature is enabled
for them.

Every refusal carries a specific machine-readable `code` — partners branch on it, so
changing one is a breaking change, and a test asserts each:

| Situation | Status | `error.code` |
|---|---|---|
| No `Authorization` header | 401 | `missing_token` |
| Unknown token, or client deleted | 401 | `invalid_token` |
| Revoked (`revoked_at` reached) | 401 | `token_revoked` |
| Past `expires_at` | 401 | `token_expired` |
| Client deactivated | 403 | `client_inactive` |
| Integration user deactivated | 403 | `integration_user_inactive` |
| Company deactivated | 403 | `company_inactive` |
| Client not a member of its company | 403 | `client_misconfigured` |
| Token lacks the route's scope | 403 | `insufficient_scope` |
| `b2b_id`/`user_id`/… in the request | 400 | `ownership_input_not_allowed` |
| Per-token budget exhausted | 429 | `rate_limit_exceeded` |
| Too many failed auths from one IP | 429 | `rate_limit_exceeded` |
| Idempotency key reused differently | 409 | `idempotency_key_conflict` |
| Original request still running | 409 | `idempotency_key_in_progress` |

Two distinct limiters, deliberately: the **per-token** budget
(`partner_integration_clients.rate_limit_per_minute`, else config) bills real traffic and
runs after authentication; the **auth-failure** budget bills only 401s per source IP and
runs before it, because a credential-guessing request has no token to charge. 403s are
never charged — a suspended partner polling `/me` must keep learning *why*.

`revoked_at` in the **future** means "still valid, briefly": that is how
`partner:token:rotate --grace-minutes` gives a partner a deployment window.
`partner:token:revoke` pulls any such schedule forward to now.

### 12.5 Routes

```
GET /api/v1/partner/health   partner.v1.health   auth required, no scope
GET /api/v1/partner/me       partner.v1.me       auth required, no scope
```

> Phase 2 adds 8 feature routes — see **§12.12.3**.

Registered from `routes/partner.php` rather than `routes/api.php` on purpose:
`routes/api.php` is loaded **twice** (once at `/api/*`, once unprefixed as the
`frontend.*` alias for the legacy SPA), and a partner endpoint must exist at exactly one
URL. Verified: `route:list --path=api/v1/partner` shows 2 routes, and `route:cache`
succeeds.

Success envelope `{"data":{…},"request_id":"…"}`; error envelope
`{"error":{"type","code","message","details?"},"request_id":"…"}`. Deliberately **not**
the legacy `{ok,data,message}` shape — that contract is frozen for `leasyback_web`, and a
public API needs a stable code partners can branch on without string-matching prose. The
renderer is scoped to `api/v1/partner/*`, so no other module's error behaviour changed.
The catch-all never leaks an exception message or trace regardless of `APP_DEBUG`.

### 12.6 Scopes

`vehicles.read`, `vehicles.write`, `orders.read`, `orders.write`, `timeline.read`,
`documents.read`, `documents.write`, `offers.read`, `offers.accept`, `webhooks.read`,
`webhooks.manage`, plus the `*` wildcard (expanded in `/me`, never shown as `*`).

The integration user's B2B permission set
(`PartnerClientProvisioner::INTEGRATION_USER_PERMISSIONS`) is `vehicles.view`,
`vehicles.create`, `vehicles.update`, `vehicles.documents.upload`, `orders.create`,
`offers.select`, `company.view` — no member management, no company master-data edits, no
analytics.

### 12.7 Commands

```bash
php artisan partner:provision {slug} --company={b2b_id} [--environment=sandbox|production]
    [--name=] [--user-email=] [--contact-email=] [--abilities=a,b] [--expires-in-days=]
    [--issued-by=] [--force]
php artisan partner:token:rotate {slug} [--environment=] [--abilities=] [--expires-in-days=]
    [--grace-minutes=] [--force]
php artisan partner:token:revoke {slug} [--environment=] [--force]
php artisan partner:activate     {slug} [--environment=] [--force]
php artisan partner:deactivate   {slug} [--environment=] [--force]
```

The token is printed **once**, to stdout only, never logged, and is unrecoverable.
`--force` is required when the app **or** the target partner environment is production —
stricter than Laravel's default, because these commands get run from a staging box
pointed at the production database.

Deactivate ≠ revoke: deactivating suspends reversibly and the *same* token works again
after `partner:activate`; revocation is irreversible and needs a rotation to restore
access.

### 12.8 Tests

`tests/Feature/PartnerApi/` — **91 tests, 321 assertions, all passing.**

| File | Tests | Covers |
|---|---|---|
| `PartnerAuthenticationTest` | 15 | valid / missing / invalid / revoked / expired / inactive client / inactive user / inactive company, `last_used_at`, hash-only storage, env segment, 404 envelope |
| `PartnerAbilityTest` | 9 | granted, missing scope, empty scope set, all-of semantics, unknown-ability fail-closed, wildcard, `/me` expansion, scope-free endpoints |
| `PartnerCompanyIsolationTest` | 6 | two partners, input ignored, stale `active_b2b_id` overridden, sandbox vs production, context throws outside a request, partner token rejected by `auth:sanctum` |
| `PartnerProvisioningCommandTest` | 18 | provision / rotate / revoke / activate / deactivate, grace window, scope narrowing, duplicate slug, reused account, unknown company/ability/environment |
| `PartnerRequestIdTest` | 6 | echo, generation, present on a 401, malformed replaced, over-long replaced, uniqueness |
| `PartnerRateLimitTest` | 8 | limit, headers, client override, per-token isolation, fresh budget after rotation, auth-failure bound, 403s not charged |
| `PartnerIdempotencyTest` | 14 | replay without re-running, payload conflict, endpoint conflict, key ordering, per-partner isolation, in-progress, stale-lock takeover, failure releases, expiry, `:required`, over-long, safe methods |
| `PartnerExternalReferenceTest` | 11 | both directions, idempotent re-register, both uniqueness directions, DB-level enforcement, cross-partner independence and isolation, type separation, cascade |

Ability and idempotency have no phase-1 endpoint, so those tests register routes with the
same middleware stack phase 2 will declare. Idempotency tests count **handler
invocations**, not just response bodies — a replay that returned the right JSON but ran
the handler twice would have created two orders in production.

> Phase 2 adds 2 files and 32 tests — see **§12.12.6**. One phase-1 test was
> **repointed**, not removed: `PartnerAuthenticationTest > an unknown partner endpoint
> returns the partner error envelope` used `/vehicles` as its stand-in for an unknown
> path, and phase 2 implemented it. It now targets `/no-such-endpoint`.

### 12.9 Verification

| Check | Result |
|---|---|
| `php artisan test --compact tests/Feature/PartnerApi` | **91 passed** (321 assertions) |
| `php artisan test --compact` (full suite) | **556 passed, 4 skipped, 2 failed** (2099 assertions) — the two documented §6 baseline failures, **no third** |
| `vendor/bin/pint --dirty --format agent` | `passed` |
| `php artisan migrate` | 4 partner migrations DONE (batch 18) |
| `php artisan migrate:status` | all Ran, none pending |
| `php artisan route:list --path=api/v1/partner` | 2 routes, correct middleware order; `route:cache` succeeds |
| `php artisan list partner` | 5 commands discovered |
| `php artisan scribe:generate` | Both partner endpoints extracted into `.scribe/endpoints/01.yaml` with descriptions and example responses. The command then **fails** at `Writer.php:242` — `rename(public/docs/, public/vendor/scribe): Access is denied` — a Windows filesystem issue in Scribe's asset move. **Confirmed pre-existing**: reproduced identically with `config/scribe.php` stashed to its previous state. Not caused by this work; expected to pass on the Linux deploy target. |
| `npm run build` | not run — PHP-only phase, no frontend file touched |

### 12.10 Risks and open points

1. **Postgres vs SQLite.** Migrations were applied on SQLite locally; production is
   Postgres. `json` columns, `timestampTz` and the composite uniques are all standard,
   but `PartnerExternalReferenceRegistry` relies on a unique-violation SQLSTATE check
   (`23000`/`23505`) — verify on Postgres before the first partner write path (phase 2)
   lands.
2. **`PartnerApiResponse::requestId()` resolves `PartnerContext` from the container.**
   Correct for HTTP, but if a later phase renders this envelope from a queued job there is
   no scoped instance and the id will be null. Pass it explicitly if that arises.
3. **Idempotency prunes expired rows on the claiming path**, scoped to the one client. No
   scheduled sweeper. If a partner stops calling, their expired rows linger — harmless,
   but a low-volume partner's table never self-cleans.
4. **Idempotent replay does not re-emit the original response's headers**, only status and
   JSON body. If a phase-2 create returns a `Location` header, the replay will not carry
   it.
5. **The auth-failure limiter is keyed on `$request->ip()`.** Behind a load balancer that
   is only meaningful if `TrustProxies` is configured for the deploy target — otherwise
   every partner shares one bucket. Verify before production.
6. **No usage/audit log yet.** `last_used_at` and `last_used_ip` are the only trace. If
   partner activity needs to be reconstructible per request, that is a phase-2 decision,
   not a retrofit.
7. **`/health` requires a token.** Deliberate — it doubles as a credential smoke test —
   but it is therefore unsuitable as an uptime probe; `/up` remains that.
8. **B2B/B2C unchanged.** No existing route, controller, service, policy or migration was
   modified. The four edited files are additive wiring, and the error renderer is
   path-scoped to `api/v1/partner/*`. The full suite shows no new failure.

### 12.11 Phase 2 — the prompt that was used (historical)

```
Implement Phase 2 of the LeasyBack Partner API: vehicles.

Read section 12 of B2B_IMPLEMENTATION_HANDOFF.md first, then inspect what exists:
app/Modules/PartnerApi/** (the phase 1 foundation), and on the portal side
VehicleService, VehicleScopeService, StoreVehicleRequest/UpdateVehicleRequest,
VehicleRules and the Vehicle model — the Partner API must go through those services,
not around them.

Build, under /api/v1/partner:
- GET   /vehicles          list the token's company fleet, paginated, filterable
- GET   /vehicles/{id}     one vehicle
- POST  /vehicles          create            (Idempotency-Key required)
- PATCH /vehicles/{id}     update
Gate each on the right PartnerAbility (vehicles.read / vehicles.write) with
'partner.ability:…' on the route, exactly as PartnerAbilityTest already exercises.

Constraints — all already enforced by phase 1; do not re-implement or bypass them:
- Scope every query to PartnerContext::companyId(). Never accept a company or ownership
  field; 'partner.no-ownership' already refuses them.
- Reuse the existing vehicle services so the integration user's B2B permissions apply on
  top of the token scope. Do not query the vehicles table directly from a controller.
- Accept and return external_vehicle_id via PartnerExternalReferenceRegistry
  (TYPE_VEHICLE). An external id already mapped to a different vehicle is a 409.
- Use PartnerApiResponse for every response and PartnerApiException for every failure —
  no new envelope, no raw abort().
- Add the routes to routes/partner.php with 'partner.idempotent:required' on the POST.
- Add Scribe #[Endpoint]/#[Response] attributes; the 'Partner API' group already exists.
- Do NOT touch orders, documents, offers, webhooks, OAuth, GDPR or Lexware.
- Do NOT change any B2C path or any existing portal behaviour.

Tests required:
- ability enforcement per endpoint (a read-only token cannot write)
- cross-company isolation: another company's vehicle is 404, never 403
- create + retry with the same Idempotency-Key creates exactly one vehicle
- external_vehicle_id round-trips; a duplicate one is 409
- validation errors render as the partner envelope with field details
- the integration user sees the whole company fleet, not a member-scoped subset
- existing B2B and B2C vehicle tests still pass

Verify: focused tests, full suite once, Pint, route:list, migrate:status.
Update section 12 of B2B_IMPLEMENTATION_HANDOFF.md: add a 12.12 for phase 2, extend the
files/routes/scopes/tests tables, record results, and re-point the next-phase prompt.
Stop after Phase 2.
```

The delivered scope was **widened** on instruction to cover orders as well as vehicles,
and narrowed to exclude nothing else: no timeline, documents, offers, webhooks or Scribe
branding was touched.

---

## 12.12 Partner API — Phase 2: vehicles and orders (2026-08-06)

Eight feature endpoints. **No new migration, no new table, no schema change** — phase 1
built every storage primitive this needed, and phase 2 is the first phase that actually
writes through them.

### 12.12.1 The one rule everything follows

Nothing in this phase decides who owns what, what a valid vehicle is, or what an order may
do next. Every such question is delegated to the service the portal already asks:

| Question | Answered by | Not by |
|---|---|---|
| Which company is this? | `PartnerContext`, from the token | any request field |
| Which vehicles may I see? | `VehicleScopeService::scopeQuery()` | a `b2b_id` filter in a controller |
| Is this vehicle payload valid? | `VehicleRules` | a partner-specific copy |
| Who owns a new vehicle? | `VehicleService::createVehicle()` | this API |
| May this vehicle be ordered? | `OrderService::createB2bCollectionOrder()` | this API |
| What order payload is valid? | `OrderCollectionService::b2bOrderRules()` | a partner-specific copy |
| What status may follow? | `TransitionOrderStatus` | **no partner endpoint at all** |

The consequence worth stating plainly: a vehicle created by a partner is written by the
same code path as one a member typed into the portal — same `VehicleAuditLog` INSERT row,
same `created_by_user_id` attribution, same collection-address de-duplication — so it
appears on the company's B2B dashboard with no dashboard-aware code in this module. There
is a test that asserts exactly that by calling
`VehicleService::paginateVehiclesWithOrders()` after a partner create.

### 12.12.2 Files

| File | Purpose |
|---|---|
| `app/Modules/PartnerApi/Http/Controllers/VehicleController.php` | list / show / create / update, + Scribe attributes |
| `app/Modules/PartnerApi/Http/Controllers/OrderController.php` | list / show / per-vehicle list / create, + Scribe attributes |
| `app/Modules/PartnerApi/Http/Controllers/Concerns/TranslatesServiceFailures.php` | `HttpResponseException` → partner envelope |
| `app/Modules/PartnerApi/Http/Requests/StorePartnerVehicleRequest.php` | `VehicleRules::forCreation(true)` + `external_vehicle_id` |
| `app/Modules/PartnerApi/Http/Requests/UpdatePartnerVehicleRequest.php` | `VehicleRules::forUpdate(true)`, plate/owner prohibited |
| `app/Modules/PartnerApi/Http/Requests/StorePartnerOrderRequest.php` | `OrderCollectionService::b2bOrderRules()` + `external_order_id` |
| `app/Modules/PartnerApi/Http/Resources/PartnerVehicleResource.php` | allow-listed vehicle shape, status + label |
| `app/Modules/PartnerApi/Http/Resources/PartnerOrderResource.php` | allow-listed order shape, no TÜV SÜD payload |
| `app/Modules/PartnerApi/Http/Middleware/EnsurePartnerCompanyPermission.php` | `partner.company-can:…`, the B2B-permission half of the gate |
| `app/Modules/PartnerApi/Services/PartnerResourceLocator.php` | every scoped lookup + external-id conflict handling |
| `app/Modules/PartnerApi/Support/PartnerPagination.php` | the one list-response pagination shape |
| `tests/Feature/PartnerApi/PartnerVehicleEndpointTest.php` | 18 tests |
| `tests/Feature/PartnerApi/PartnerOrderEndpointTest.php` | 14 tests |

Modified: `routes/partner.php` (8 routes), `bootstrap/app.php` (7th alias,
`partner.company-can`), `PartnerExternalReferenceRegistry` (**additive only** — one new
`externalIdsFor()` batch reader; no existing method changed),
`tests/…/Concerns/BuildsPartnerClients.php` (one helper), `PartnerAuthenticationTest`
(one test repointed, see §12.8).

### 12.12.3 Routes

```
GET    /api/v1/partner/vehicles                   vehicles.read  + vehicles.view
POST   /api/v1/partner/vehicles                   vehicles.write + vehicles.create   [Idempotency-Key required]
GET    /api/v1/partner/vehicles/{vehicle}         vehicles.read  + vehicles.view
PATCH  /api/v1/partner/vehicles/{vehicle}         vehicles.write + vehicles.update   [Idempotency-Key optional]
GET    /api/v1/partner/orders                     orders.read    + vehicles.view
GET    /api/v1/partner/orders/{order}             orders.read    + vehicles.view
GET    /api/v1/partner/vehicles/{vehicle}/orders  orders.read    + vehicles.view
POST   /api/v1/partner/vehicles/{vehicle}/orders  orders.write   + orders.create     [Idempotency-Key required]
```

The first column of each pair is the **token ability**, the second the **integration
account's B2B permission**. Both are required; neither is sufficient. Order matters —
ability, then company permission, then idempotency — so a call that fails the scope gate
never consumes an `Idempotency-Key`, which a test asserts.

`route:list --path=api/v1/partner` shows 10 routes; `route:cache` succeeds.

Ids are constrained with `whereUuid` and resolved through `PartnerResourceLocator`, **not**
route model binding: binding fetches by primary key first and authorises second, so a
mis-wired route would load another company's row before anything checked it.

### 12.12.4 Design decisions worth not re-litigating

1. **There is no status-update endpoint, and this is deliberate.** An order advances by
   what happens to the vehicle, and every legal edge belongs to `TransitionOrderStatus`. A
   partner-writable status would be a second, weaker copy of that graph, and the first
   thing it would permit is skipping a stage. Partners read status; they do not write it.
   `PATCH /orders/{order}` answers 405 `method_not_allowed`, asserted by a test.
2. **404, never 403, for anything outside the token's company.** A 403 confirms the id
   exists, which is the disclosure cross-company isolation exists to prevent. Applied to
   vehicles *and* orders — but note `GET /vehicles/{unknown}/orders` is a 404 rather than
   an empty list, because "no orders" and "not your vehicle" are different facts a partner
   reconciling needs to tell apart.
3. **`EnsurePartnerCompanyPermission` exists rather than reusing `b2b.can`.** That
   middleware speaks to a browser: it *redirects* an account with no membership to the
   onboarding page, and its `abort(403, '…')` reaches a partner as the renderer's
   `request_failed` catch-all. The permission *decision* is not duplicated — both
   middlewares ask the same `B2bPermissionSet`. Only the rendering differs.
4. **New records and their external-id mapping are written in one transaction.** A
   pre-check answers the common duplicate cleanly; the transaction covers the race the
   pre-check cannot close, so a losing request leaves no vehicle or order the partner has
   no id for. Asserted for both entities.
5. **Duplicate VIN is accepted; duplicate plate is a 422.** That is the existing schema:
   `vehicles.license_plate` is uniquely indexed, `vin` is not. Both behaviours are pinned
   by tests so a future change to either is a deliberate one. The plate's uniqueness is
   global, so a collision may involve a vehicle the token cannot see — a test asserts the
   response never leaks anything about it.
6. **`request_payload` / `response_body` are absent from the order resource.** They hold
   the TÜV SÜD request and raw reply. Exposing them would make a third party's response
   format our public contract by accident.

### 12.12.5 New error codes

| Situation | Status | `error.code` |
|---|---|---|
| Vehicle absent, or outside the token's company | 404 | `vehicle_not_found` |
| Order absent, or outside the token's company | 404 | `order_not_found` |
| Integration account lacks the B2B permission | 403 | `insufficient_company_permission` |
| `external_*_id` already mapped elsewhere | 409 | `external_reference_conflict` |
| Vehicle already has an order that has not closed | 409 | `order_already_open` |
| Same-day repeat order (see risk 2) | 409 | `order_reference_conflict` |
| Vehicle is not eligible for the collection flow | 422 | `vehicle_not_eligible` |

These join the phase-1 table in §12.4. As there: **changing one is a breaking change**,
and each is asserted by a test.

### 12.12.6 Tests

`tests/Feature/PartnerApi/` — **123 tests, 501 assertions, all passing** (91 from phase 1,
32 new).

| File | Tests | Covers |
|---|---|---|
| `PartnerVehicleEndpointTest` | 18 | create; dashboard visibility via `VehicleService`; ownership fields refused (400) and `vehicle_belongs` refused (422); duplicate plate (422, nothing leaked); duplicate VIN accepted; external-id uniqueness per integration and independence across integrations; idempotent retry and payload conflict; missing key; cross-company 404 on show and update; B2C vehicle invisible; list isolation, pagination, external-id filter incl. unmapped→empty; update incl. plate refused; read-only vs write-only scope; withdrawn company permission |
| `PartnerOrderEndpointTest` | 14 | create incl. logistics row + audit row + no `sent_at`; another company's vehicle 404; B2C vehicle 404; open-order restriction 409; same-day repeat 409; later-day repeat succeeds; B2C inspection fields refused; external-order-id uniqueness with rollback; idempotent retry; list/show isolation; per-vehicle list; status and external-id filters; **no status-update endpoint** (405); scope and company permission both required |

**B2C regression:** `OrderControllerTest` + `VehicleDashboardControllerTest` pass unchanged
(13 tests), and two dedicated partner tests assert a B2C vehicle is neither readable nor
orderable through this API.

### 12.12.7 Verification

| Check | Result |
|---|---|
| `php artisan test --compact tests/Feature/PartnerApi` | **123 passed** (501 assertions) |
| `php artisan test --compact` (full suite) | **588 passed, 4 skipped, 2 failed** (2279 assertions) — the two documented §6 baseline failures, **no third** |
| `php artisan test --compact tests/Feature/OrderControllerTest.php tests/Feature/VehicleDashboardControllerTest.php` | 13 passed (89 assertions) |
| `vendor/bin/pint --dirty --format agent` | `fixed`, then clean |
| `php artisan migrate:status` | all Ran, **none pending** — phase 2 added no migration |
| `php artisan route:list --path=api/v1/partner` | 10 routes, correct middleware order; `route:cache` succeeds |
| `php artisan scribe:generate` | **All done** — all 10 partner endpoints extracted into `.scribe/endpoints/01.yaml`. The Windows `rename()` failure recorded in §12.9 did **not** recur (`public/vendor/scribe/` now exists, which was the cause). Artefacts are gitignored. |
| `npm run build` | not run — PHP-only phase, no frontend file touched |

**Driver verification (§12.10 risk 1, discharged for SQLite).** The task required
confirming `PartnerExternalReferenceRegistry`'s unique-violation check on the actual
supported driver before relying on it for a write path. Probed directly:

```
driver=sqlite  code='23000'  sqlstate='23000'
```

`isUniqueViolation()` checks `['23000','23505']`, so it is correct on the configured
driver (`DB_CONNECTION=sqlite`, `database.default=sqlite`). **Postgres remains
unverified** — `23505` is handled and is the correct SQLSTATE, but no Postgres instance
was available here. Re-run the probe on the deploy target before the first production
partner write.

### 12.12.8 Risks and open points

1. **`external_*_id` filters cost one extra query each.** Resolved through the registry
   before the main query. Fine at list scale; if a partner polls a filter every second it
   is two queries where one would do.
2. **A vehicle cannot be re-ordered on the same calendar day.** `auftragsnummer` is
   `license_plate + ymd` and uniquely indexed application-wide, so a second order for one
   vehicle on one day collides — reachable only once the first order has closed, since an
   open one is refused earlier. **This is pre-existing and equally true in the portal**,
   where it surfaces as an unhandled 500. The Partner API translates the collision into
   409 `order_reference_conflict` so a partner is not handed an opaque server error, but
   the underlying generator is untouched and remains the real fix. Both behaviours are
   pinned by tests. **Recommend fixing the generator in a later phase** (a sequence suffix
   is the obvious shape) — at which point the 409 translation can go.
3. **The status filter is "has an order in this status", not "its current order is".**
   Identical in practice, because a B2B vehicle may hold at most one non-closed order, and
   documented as such in the endpoint description. It would diverge if that restriction
   were ever lifted.
4. **Idempotent replay still does not re-emit headers** (phase-1 risk 4, unchanged). The
   creates return no `Location` header, so nothing is currently lost — do not add one
   without fixing the replay path.
5. **`PATCH` carries `partner.idempotent` (optional), not `:required`.** An update is
   naturally idempotent in its effect; a partner that wants replay protection can still
   send a key.
6. **No usage/audit log for partner reads** (phase-1 risk 6, unchanged). Writes are
   traceable — they land in `vehicle_audit_log` / `leasyback_order_audit_log` attributed to
   the integration account — but reads leave only `last_used_at`.
7. **B2B/B2C unchanged.** No existing controller, service, policy, model or migration was
   modified. `PartnerExternalReferenceRegistry` gained one new method and lost none.
   `bootstrap/app.php` and `routes/partner.php` are additive. The full suite shows no third
   failure.

### 12.13 Next phase — ready-to-use prompt

```
Implement Phase 3 of the LeasyBack Partner API: order timeline and documents.

Read section 12 of B2B_IMPLEMENTATION_HANDOFF.md first — especially 12.12, which is the
phase 2 record and establishes the patterns you must follow. Then inspect only what you
need: app/Modules/PartnerApi/** (foundation + phase 2), and on the portal side
OrderTaskResolver, TransitionOrderStatus, OrderStatusUpdate, VehicleDocument,
VehicleDocumentController and VehicleReportDocument. Section 3 of this document maps the
15-stage timeline; section 4 documents OrderTaskResolver.

Build, under /api/v1/partner:
- GET  /orders/{order}/timeline          the 15-stage timeline for one order
- GET  /orders/{order}/documents         documents attached to an order
- GET  /vehicles/{vehicle}/documents     documents attached to a vehicle
- GET  /documents/{document}/download    a time-limited signed URL, not the bytes
- POST /vehicles/{vehicle}/documents     upload   (Idempotency-Key required)
Gate each on the right PartnerAbility (timeline.read / documents.read / documents.write)
AND the right B2B permission via partner.company-can, exactly as phase 2's routes do.

Reuse, do not reimplement:
- PartnerResourceLocator for every lookup — extend it for documents rather than querying
  from a controller. Anything outside the token's company is 404, never 403.
- VehicleService::uploadDocument() and generateSignedUrl() for the write and the URL.
  The storage path is always server-derived; a partner never supplies or sees one.
- OrderTaskResolver / the existing timeline construction for stage data. Do not build a
  second timeline.
- PartnerApiResponse, PartnerApiException, PartnerPagination and the Resource pattern
  from phase 2 (explicit allow-list, never $model->toArray()).
- PartnerExternalReferenceRegistry if documents need external ids (add a TYPE_DOCUMENT).

Constraints:
- Only customer-visible documents and only published report documents. Admin-only
  document types and internal notes must not be reachable — check VehicleDocument's
  category/type rules and B2bOrderNoteService's visibility scope before exposing anything.
- Signed URLs must be short-lived (the portal uses 1800s) and must never be cached in the
  idempotency store.
- Do NOT touch offers, webhooks, OAuth, GDPR or Lexware.
- Do NOT change any B2C path or any existing portal behaviour.
- Do NOT add a status-write endpoint. See 12.12.4 decision 1.

Tests required:
- ability AND company-permission enforcement per endpoint (both halves, independently)
- cross-company isolation: another company's order/document is 404
- an internal note or unpublished report document is never returned
- upload + retry with the same Idempotency-Key stores exactly one document
- a signed URL is returned, is time-limited, and contains no raw storage path
- timeline stages match what the portal renders for the same order
- existing B2B and B2C document/timeline tests still pass

Verify: focused tests, full suite once, Pint, route:list, migrate:status, scribe:generate.
Before relying on any new unique constraint in a write path, probe the actual driver as
12.12.7 does — do not assume.
Update section 12: add 12.14 for phase 3, extend the tables in 12.12.2/12.12.3/12.12.5/
12.12.6, record results, and re-point the next-phase prompt.
Stop after Phase 3.
```
