# B2C / Admin Migration Audit

Checkpoint 0 deliverable. Read alongside `docs/B2C_ADMIN_IMPLEMENTATION_PLAN.md` (ordered checkpoints), `docs/B2C_ADMIN_PERMISSION_MATRIX.md`, and `docs/B2C_ADMIN_STATUS_MATRIX.md`.

**Method:** five parallel research passes — (1) Rust `profiles`/`vehicle`/`vehicle_documents`/`workshop`/`image_upload`, (2) Rust `order_others`/`order_tuvsud`/`order_logistics`/`offers`/`tim_appraisal`/`email_send`, (3) Rust `admin_panel`/`middleware`/full route table/`b2b` boundary, (4) old Vue frontend (`leasyback_web`) B2C + Admin pages mapped to endpoints, (5) current Laravel app's existing `app/Modules/UserProfile/*` + `app/Modules/DekraProcess/*` — an honest production-readiness assessment, not a presence check.

**Critical context discovered in this audit that changes the shape of the work:** a substantial B2C/Admin implementation **already exists** in this Laravel app (`app/Modules/UserProfile/{Profile,Vehicle,Order,Offer,Workshop,B2B,Image,Admin,Tim}`, `app/Modules/DekraProcess`), with a database schema that already improves on the Rust design in several ways (bigint `users.id`, UUID domain keys, encrypted workshop banking fields, Postgres CHECK constraints). It also has **five live, confirmed security vulnerabilities** (one critical SQL injection, one critical arbitrary-file-access vulnerability, two IDOR bugs, one BOLA bug on a financial-decision endpoint), **zero test coverage**, and **zero model factories** for any of these ~20 models. Two of the five bugs already have a correct, unused fix sitting in the repo (`ProfileService`, `B2BService`, `AdminQueryService`, `EnsuresAdmin` trait — built properly, never wired to the actual controllers). This changes Checkpoint 1's job from "build a foundation" to "hardened, tested foundation on top of what's already there."

---

## 1. Classification standard (carried over from the auth migration)

Every Rust behavior below is tagged:

1. **Required product behavior** — a real capability users need; port the requirement, not the implementation.
2. **Security/architecture improvement** — Laravel should do this even though Rust didn't.
3. **Rust bug — do not port** — a defect in the reference implementation.
4. **Obsolete implementation detail** — Rust-specific plumbing with no Laravel equivalent needed.
5. **Product decision required** — ambiguous or missing spec; needs a call from the project owner.

---

## 2. Global architecture notes (apply across every subsystem)

| Observation | Classification |
|---|---|
| Single JWT (HS256, shared `SECRET` env) trusted for `user_type` on every request, no per-request re-check against the `users` table | 3 — do not port. Laravel already uses session/Sanctum + `EnsureUserIsActive` re-checking `is_active` live; keep that. |
| No centralized authorization — every "admin-only" handler repeats `if user_type != "Admin"` inline (~20 call sites); `spatie/laravel-permission` is installed in the current Laravel app but **completely unused** | 3 (scattered checks) — do not port. See §9 Permission architecture decision. |
| CORS: `allow_any_origin()` on every route | 3 — do not port; Laravel's CORS is already properly scoped (see `docs/AUTH_MODULE.md`). |
| Raw DB error strings returned to clients (`ApiError::DbError(e.to_string())`) | 3 — do not port; already fixed pattern exists in the auth module's exception renderer, extend the same scoped rendering to these routes. |
| Raw JWT + decoded claims `println!`-logged on every request (`middleware.rs:37,47`); SendGrid API key `println!`-logged (`status_notify.rs:66`) | 3 — do not port. Verify no equivalent secret-logging exists in current Laravel controllers (not found by the audit, but not exhaustively checked either — flag for review). |
| Hardcoded TÜV SÜD API key `"AKIAZI2PK2IT5KC3EZBV"` (AWS-key-shaped literal) in source, used both as a webhook credential and as an Admin-equivalent bypass | 3 — do not port. Laravel's `OrderController::confirm`/`status` already reads the key via an `extractApiKey` pattern per the audit — **confirm it's sourced from config/env, not a literal, before Checkpoint 6 ships.** |
| Insecure fallback defaults for TÜV SÜD/TIM credentials and URLs if env vars unset (`"secret"`, `"supersecret"`, `"DEMO:TEST"`, a hardcoded partner URL, a hardcoded bucket name `"my-bucket"`) | 3 — do not port. Laravel config should fail closed (throw/refuse to boot) on missing required secrets in production, not silently fall back to a weak default. |
| Order-creation emails hardcoded to a personal Gmail address (`jupiterbarua@gmail.com`) as the **only** real recipient in most paths — the real per-customer recipient lookup exists in Rust source but its call is commented out, so **real customers never received order-created emails** in the reference system | 3 — do not port as-is; 1 — the underlying requirement ("notify the vehicle owner when an order/offer/status changes") is real and must actually work in Laravel, unlike in Rust. |
| TIM integration's SOAP field is literally named `PasswortSHA1` but the raw configured password is sent unhashed | 5 — product decision required. Need to confirm the real credential format with whoever owns the TIM vendor relationship before wiring TIM in Laravel; do not guess. |
| `order_logistics` (pickup/delivery scheduling) has a fully-designed SQL schema (`logistics_address_profiles`, `leasyback_order_logistics`) already migrated into the current Laravel app, but **zero business logic anywhere, in Rust or Laravel** | 4/5 — this is a new feature to design from the schema + real requirements, not something to port. Confirm scope/priority with the product owner (§13). |
| `user_workshops` / `vehicle_report_document_logs` tables exist in the Laravel schema but **no model/controller references either** | 4 — dead schema; either wire them up for their apparent intended purpose (workshop team membership; report-document audit trail) or drop them, don't leave them silently unused. |

---

## 3. B2C feature inventory

### 3.1 Profile (personal info, address, contact, preferences)

"Profile" is not one table — `user_profiles` (1:1 `users`) + `contacts` + `addresses` + `phone_numbers` (1:many per contact) + `user_preferences`, matching the current Laravel schema exactly (already migrated this way).

| Capability | Old route (Rust) | Method | Auth | Ownership check (Rust) | Current Laravel route | Ownership check (Laravel) | Fields | Classification |
|---|---|---|---|---|---|---|---|---|
| Create address+contact+phones | `/userprofile/address-contact` | POST | JWT | Implicit (inserts tied to caller) — OK | `userprofile/address-contact` (`ProfileController@storeAddressContact`) | Tied to caller — OK | `address{street,number,additional_address?,zip_code,city,country}`, `contact{salutation,first_name,last_name}`, `phones[{international_prefix,phone_number}]` | 1 |
| Update address+contact+phones | `/userprofile/address-contact` | PUT | JWT | **NONE — IDOR**: `address_id`/`contact_id` taken from body, never checked against caller | Same route, `updateAddressContact` | **Same IDOR still present** (`ProfileController.php:78-119`) — any authenticated user can overwrite any other user's address/contact/phones by ID | 3 (Rust bug) carried forward as a **live Laravel bug** — must fix in Checkpoint 2. A correct, unused fix already exists: `ProfileService::updateAddressContact` checks `user_profiles.user_id = caller AND contact_id = data.contact_id` with row locking. **Wire it in, don't rebuild.** |
| Create preferences | `/userprofile/user-preferences` | POST | JWT | Tied to caller — OK | not confirmed 1:1 in audit; same module | — | `timezone` (IANA-style), `sprache` (`de/en/fr/es/it`), push/email booleans | 1 |
| Update preferences | `/userprofile/user-preferences` | PUT | JWT | Correct — filtered by `preference_id AND user_id`, though doesn't check rows-affected (silent no-op success) | `ProfileController::updatePreferences` — correctly scoped by `user_id` | Correct | same | 1, with a minor 2 (check rows-affected, return 404 on no-op) |
| Get aggregate profile | `/userprofile/user-profile` | GET | JWT | Always keyed off caller's own `user_id` — OK | `ProfileController@show` | OK | joined view of all of the above | 1 |

**Recommended Laravel approach:** keep the existing `Profile` module's routes/shape; in Checkpoint 2, replace `ProfileController::updateAddressContact`'s body with a call into the already-correct `ProfileService`, add a `ProfilePolicy` (or fold the ownership check into the Action itself), and write the Feature test that would have caught this IDOR (attempt to update another user's `address_id`, assert 403/404).

### 3.2 Vehicle

**Fields** (already matches current Laravel `vehicles` table): `vehicle_id` (UUID), `license_plate` (unique), `first_registration_date`, `leasing_end_date`, `vin` (17 chars), `make`, `model`, `leasinggeber` (leasing company), `b2b_id`/`b2c_user_id` (mutually exclusive owner), `assigned_profile_id`, `vehicle_belongs` (`B2B`/`B2C`).

| Capability | Old route | Method | Auth | Ownership (Rust) | Current Laravel route | Ownership (Laravel) | Classification |
|---|---|---|---|---|---|---|---|
| Create vehicle | `/vehicle/create` | POST | JWT | Owner derived server-side from `user_type` — correct | `vehicle/create` (`VehicleController@store`) | Uses `VehicleScopeService` — correct, already wired | 1 |
| Update vehicle | `/vehicle/{id}` | PATCH | JWT | Correct (scope-checked before update, though check-then-act rather than atomic) | `vehicle/{vehicleId}` | Uses `VehicleScopeService` | 1 |
| Assign profile to vehicle | `/vehicle/{id}` | PUT | JWT | Scope-checked, but doesn't verify `assigned_profile_id` belongs to the same owner | `vehicle/{vehicleId}` (`assignProfile`) | Not flagged as fixed — verify in Checkpoint 4 | 1, with a 2 (validate the assigned profile belongs to the vehicle's own owner) |
| Get vehicle by id+owner | `/vehicle/find/{vehicle_id}/{owner_id}` | GET | JWT | **NONE — IDOR**: `owner_id` from URL, never checked against caller | `vehicle/find/{vehicleId}/{ownerId}` (`VehicleController@findByOwner`) | **Same IDOR still present** (`VehicleController.php:164-185`) | 3, live Laravel bug — fix in Checkpoint 4 |
| List vehicles by owner | `/vehicle/list/{owner_id}` | GET | JWT | Correct — explicitly checks `owner_id` against caller | `vehicle/list/{ownerId}` | Not separately flagged as broken; verify it retained the correct check when porting | 1 |
| Dashboard (vehicles + nested orders/status/reports) | `/vehicle/list/report/status` | GET | JWT | Correct scoping by `user_type` (Admin=all, B2B=own company, B2C=own) | `vehicle/list/report/status` (`VehicleController@dashboard`) | Uses `VehicleScopeService` | 1 |

**Recommended Laravel approach:** `VehicleController`/`VehicleScopeService` is the best-built piece of this domain already — extend it, formalize as a `VehiclePolicy` in Checkpoint 4, and fix `findByOwner`'s missing ownership check (require `$ownerId` to match the authenticated user's own id/company id, exactly like the sibling `listByOwner` already does correctly).

### 3.3 Vehicle documents

**Fields**: `document_id`, `vehicle_id`, `document_category` (always `"Fahrzeug"`), `document_type` (allow-list: `Leasingvertrag`, `vorschaden`, `gutachten`, `Sonstiges`), `original_file_name`, `s3_key`, `content_type`, `file_size`, `uploaded_by_user_id`. Storage: **S3 only**, key pattern `vehicle-documents/{vehicle_id}/{document_type}/{uuid}_{filename}`, DB stores the key not the file; every read generates a fresh presigned URL (Rust: 3h). Only one document per `document_type` per vehicle is allowed (re-upload of an existing type is rejected, not replaced) — a real product rule, not a bug.

| Capability | Old route | Method | Auth | Ownership (Rust) | Current Laravel | Ownership (Laravel) | Classification |
|---|---|---|---|---|---|---|---|
| Upload document | `/vehicle/{id}/documents` | PUT | JWT | **CRITICAL BUG**: the real ownership check (`ensure_vehicle_access`) is fully implemented but **commented out** — every non-admin authenticated user can upload/list/view/delete **any vehicle's** documents | `vehicle/{vehicleId}/documents` (`VehicleDocumentController@upload`) | **Fixed in Laravel** — audit found this controller checks correctly | 3 (Rust bug, already avoided in Laravel — confirm with a regression test anyway) |
| List/view/delete document | same base | GET/GET/DELETE | JWT | Same critical bug | same routes | Confirmed OK by the audit (`show`/`destroy` verify `vehicle_id` match) | 3 (Rust bug, avoided) |
| 10 MB size cap, `.pdf/.jpg/.jpeg/.png` + content-type allow-list, magic-byte-independent (trusts extension/content-type only, no sniffing) | n/a | n/a | n/a | n/a | n/a | n/a | 1 — real requirement, verify Laravel enforces the same cap/allow-list explicitly (not confirmed field-by-field in this audit; check in Checkpoint 4). |

**Recommended Laravel approach:** the existing controller already avoids the Rust critical bug — good. Add an explicit `VehicleDocumentPolicy` (even though it currently works, it should be a named, testable policy rather than inline logic) and a regression test proving cross-owner access is blocked.

### 3.4 Workshop

**Fields**: `workshop_id`/now `id` (UUID, 1:1 `user_id` in the Laravel schema — simpler than Rust's many-to-many `user_workshops`), `workshop_name`, `logo_url`, `contact_email`, address/contact fields (denormalized directly onto the `workshops` row in Laravel, vs. Rust's separate `addresses`/`contacts` FK — **a real, already-made schema simplification**), `has_vat_id`/`vat_id`, `iban`/`bic`/`account_holder` (**encrypted at the Laravel model level** — a real improvement over Rust, which stored these in plaintext), `packages_selected` (`Premium`/`Pro`), `terms_accepted`/`privacy_accepted`.

| Capability | Old route | Method | Auth | Rust flaw | Current Laravel | Laravel status | Classification |
|---|---|---|---|---|---|---|---|
| Create workshop | `/workshop/create` | POST | JWT, `user_type=="Werksatatt"` | Address/contact insert failures silently swallowed (`.ok()`), workshop created anyway with NULL refs | `workshop/create` (`WorkshopController@store`) | Best-built controller in the whole domain per the audit — `authorizeOwner()` helper, structured rules, encrypted fields | 1, with the swallow-failure Rust behavior explicitly **not** ported (3) |
| Update workshop | `/workshop/{id}` | PATCH | JWT, ownership-checked correctly via `user_workshops` membership | Response-shape inconsistency (returns bare string, not the row) | `workshop/{workshopId}` | Correctly ownership-checked | 1 |
| Get workshop by user | `/workshop/user_id/{id}` | GET | JWT | Correct — 404s if `user.user_id != {id}`. Only returns one workshop per user via `LIMIT 1` even though the schema (Rust) supported many | `workshop/user_id/{userId}` | Laravel simplified to 1:1 already — the Rust `LIMIT 1` gotcha doesn't apply to the new schema | 1 (schema simplification is a legitimate call, already made — confirm with product it's intentional, since Rust's `user_workshops` table hints multi-staff workshops were once considered) |

**Recommended Laravel approach:** use `WorkshopController` as the reference pattern (`authorizeOwner()`, encrypted sensitive fields) for fixing Profile/B2B/Vehicle controllers elsewhere in this domain.

### 3.5 Image upload (logos)

| Capability | Old route | Method | Auth | Rust flaw | Current Laravel | Laravel status | Classification |
|---|---|---|---|---|---|---|---|
| Upload logo | `/image/logos/upload` | POST | JWT (role unused) | No owner concept at all; no file-size cap (unbounded upload, DoS/cost vector) | `image/logos/upload` (`ImageController@upload`) | Not flagged as fixed | 3 — needs an owner/size cap in Laravel |
| Refresh signed URL | `/image/logos/{key}/signed-url` | GET | JWT (role unused) | **No ownership check** — any authenticated user can request a signed URL for **any** S3 key, not just their own logo | `image/logos/{key}/signed-url` | **CRITICAL — same vulnerability, but WORSE**: the Laravel route pattern (`where('key','.*')`) accepts **any key in the entire bucket**, not just the `logos/` prefix — an authenticated user can pass `vehicle-documents/...`, `tim/bewertung/...`, etc. and read files from **every other module's S3 storage** | 3 — highest-severity finding in this entire audit. **Fix before any other Checkpoint 4/8+ work touches S3.** |
| Delete logo | `/image/logos/{key}` | DELETE | JWT (role unused) | Same — any authenticated user can delete **any** object by key | same route | **Same critical bug, and destructive** — any authenticated user can permanently delete any file in the bucket, from any module | 3 — same top-priority fix |

**Recommended Laravel approach:** this needs to stop being a generic "any S3 key" endpoint entirely. Scope it to the caller's own workshop/profile logo record (validate the key belongs to a `logo_url` the caller actually owns, or better, make upload/delete take an owning entity id and derive the key server-side rather than accepting one from the client at all). This is the single highest-priority fix in the whole audit — recommend addressing it in Checkpoint 1 (foundation) rather than waiting for a later document-focused checkpoint, given the severity (any authenticated user, any file, in production, today).

---

## 4. Order lifecycle (the most complex subsystem)

### 4.1 Order creation

| Capability | Old route | Provider | Auth | What actually happens | Classification |
|---|---|---|---|---|---|
| Create TÜV SÜD-track order | `/order/tuvsud/create/{vehicle_id}` | tuvsud | JWT (role check commented out in Rust) | B2B (Firmenkunde): inserts `order_status='order_requested'`, **no external call**, awaits Admin approval. Admin/B2C: calls the real TÜV SÜD API directly, inserts `order_status='order_placed'`. Blocks if the vehicle already has an "unfinished" order (`order_status NOT IN (delivered, cancelled, discarded)`). | 1 (the B2B-needs-approval / B2C-goes-direct split is a real business rule) |
| Create DEKRA-track order | `/order/others/create/{vehicle_id}` | others (dekra) | JWT (role check commented out) | **No real external call at all** — provider hardcoded, response fabricated as if it succeeded. Same "one open order per vehicle" guard. | 3 — the fabricated-success behavior is a Rust stub, not a real integration; confirm with product whether DEKRA has a real API to integrate or whether this remains a manual/admin-driven flow for now (§13). |
| Approve a requested order | `/order/tuvsud/order/approve/{order_id}` | tuvsud | Admin only | Requires current status `order_requested`; the only handler besides the (unreachable) reject that checks a precondition. **Stores the TÜV SÜD auth token into `request_payload` JSONB** — secrets end up in a plain data column. | 1 (the approval gate is real) + 3 (storing secrets in a data column — do not port) |
| Reject a requested order | `/order/tuvsud/order/reject/{order_id}` | tuvsud | Admin only | Fully implemented in Rust **but never mounted** — dead/unreachable route. | 5 — was rejection a real, intended feature that just never shipped, or abandoned? Confirm with product; if real, it's a straightforward addition (mirror `approve`'s logic, target status `discarded`). |

### 4.2 Order status updates (the generic-status-override anti-pattern named explicitly in the task brief)

**This is the endpoint the brief specifically warns against, and it is real:** `GET /order/tuvsud/status` (Rust `tuvsud_status.rs`; Laravel port at `order/tuvsud/status`, `OrderController@status`). Reachable two ways: (a) a hardcoded shared-secret API key — forces status to `confirmed` unconditionally, or (b) an Admin JWT — accepts **any** of 9 allow-listed status strings and writes it with **zero precondition on the current status**. A `delivered` order can be pushed back to `order_placed` with no guard. This is a GET request used to perform a mutation (not idiomatic REST — Laravel's port should be a POST/PATCH). There is a second, narrower, actually-safe endpoint (`admin_panel/update_status.rs` / Laravel `admin/b2c/{id}/status`, `admin/b2b/{id}/status`) that's a plain `is_active` boolean toggle — that one is fine and not the problem.

**Recommended Laravel approach (Checkpoint 6):** replace the free-text `order_status` parameter with a `TransitionOrderStatus` action that accepts only a named transition (not a raw target status string), validated against an explicit transition table (see `docs/B2C_ADMIN_STATUS_MATRIX.md`), executed inside a DB transaction with row locking, and logged to a single consistent audit table (Rust/current schema has two — `leasyback_order_audit_log` and `leasyback_order_status_updates` — used inconsistently; pick one for new writes, per §9). Keep the external API-key-authenticated webhook path (TÜV SÜD really does call back), but route it through the same transition-validated action rather than a raw column write, and move the key out of source into config.

### 4.3 Order status enum

Full canonical list (DB CHECK constraint, already in the Laravel migration): `order_requested`, `order_placed`, `confirmed`, `discarded`, `cancelled`, `inspected`, `workshop`, `reinspection`, `delivered`, `reworkshop`. See `docs/B2C_ADMIN_STATUS_MATRIX.md` for the full from→to/actor/trigger table. Key findings:
- No status-transition state machine exists anywhere in Rust or (per the audit) current Laravel — every "set status" handler unconditionally overwrites. This is classification 3 across the board; **1** for the underlying requirement that these ten statuses exist and mean what they mean.
- Rust's in-code allow-list for the Admin-settable path is missing `order_requested` (inconsistent with the DB constraint) — a data-quality landmine, not a requirement; ignore the omission, use the full DB-backed list.
- `inspected`/`workshop`/`reinspection`/`reworkshop`/`delivered` are, in the reference implementation, **only ever reachable via the Admin manual-override path** — TÜV SÜD's real webhook only ever sets `confirmed`. Confirm with product whether TÜV SÜD's real integration is expected to drive these later statuses automatically in the future, or whether manual Admin progression through the workshop/inspection stages is the actual intended design (§13).

### 4.4 Logistics (pickup/delivery scheduling) — new feature, not a port

Schema (`logistics_address_profiles`, `leasyback_order_logistics`) is fully designed and already migrated in the current Laravel app. **Zero Rust business logic and zero Laravel business logic exists** — no handlers, no controllers, nothing references these tables beyond their own migration/model files. Treat as classification **5** — a genuinely new capability to scope with product before Checkpoint 12 builds it, not something with a reference implementation to consult.

### 4.5 Offers (buyback offers)

**Fields** (already migrated in Laravel): `offer_id`, `order_id`, `auftragsnummer`, `offer_sequence` (unique per order), four cost pairs (repair, depreciation, workshop-quote, missing-parts, each net/gross), `final_total_net/gross` (computed, not client-settable), `offer_status` (`draft`/`published`/`selected`/`closed`/`cancelled`), audit user-id columns, `cancellation_reason`. A unique partial index guarantees only one `selected` offer per order.

| Capability | Old route | Auth | Rust flaw | Current Laravel | Laravel status | Classification |
|---|---|---|---|---|---|---|
| Create offer draft | `/admin/offers/create/{auftragsnummer}` | Admin | Blocks if order already has a selected offer — correct | `admin/offers/create/{auftragsnummer}` | not flagged broken | 1 |
| Publish offer | `/admin/offers/publish/{offer_id}` | Admin | Requires `draft`, blocks if another already selected — correct | `admin/offers/publish/{offerId}` | not flagged broken | 1 |
| Cancel offer | `/admin/offers/cancel/{offer_id}` | Admin | Requires `draft`/`published`, blocks if a selected offer exists — correct | `admin/offers/cancel/{offerId}` | not flagged broken | 1 |
| List offers (admin) | `/admin/offers/list/{auftragsnummer}` | Admin | Returns all regardless of status | `admin/offers/list/{auftragsnummer}` | not flagged broken | 1 |
| List offers (customer) | `/vehicle/offers/customer/list/{auftragsnummer}` | JWT, explicitly rejects Admin | Correctly scoped by vehicle ownership join; only shows `published`/`selected` | `vehicle/offers/customer/list/{auftragsnummer}` (`OfferController@customerList`) | Correctly scoped per the audit | 1 |
| **Select offer (customer)** | `/vehicle/offers/customer/select/{offer_id}` | JWT | Ownership check exists via join **but is bypassed for anyone flagged `is_admin`** (dead/duplicate code disabled the intended Admin-block) — an Admin can select an offer "as" a customer | `vehicle/offers/customer/select/{offerId}` (`OfferController@customerSelect`) | **WORSE than Rust — no ownership check at all.** `$isAdmin` is computed and never used; any authenticated user who knows/guesses an `offerId` can select it, closing out the real owner's offer. **This is a BOLA on a financial-decision endpoint.** | 3, live Laravel bug, high priority — fix in Checkpoint 6/11. |

**Note:** selecting an offer does **not** touch `leasyback_orders.order_status` in either system — a real gap to decide on (does the order automatically transition on offer selection, e.g. to a "workshop"/"repair" stage, or is that still a separate manual Admin action?). Flagged in §13.

### 4.6 TIM appraisal integration (SOAP/XML, Admin-only)

Fetches vehicle valuation/appraisal documents from an external TIM system via hand-built SOAP XML envelopes over HTTP. Login field literally named `PasswortSHA1` but the raw password is sent unhashed (classification 5 — confirm real credential format with the vendor). A shared single-row session token (`tim_token`, singleton `id=1`) is used for the whole app — a real concurrency/race-condition risk if kept as-is (classification 2 — consider per-Admin or properly-locked session refresh). The Rust XML parser `.unwrap()`s on a missing field from untrusted external XML, i.e., a malformed TIM response can crash the process (classification 3 — do not port; handle parse failures as recoverable errors). The linkage between a synced TIM "Bewertung" and a `leasyback_orders` row (`response_body = to_jsonb(bewertung_id)`) is brittle/likely broken in practice (classification 3). `hersteller` (manufacturer) is permanently `NULL` in every synced record — an unfinished parser, not a deliberate omission (classification 3, needs a real fix if manufacturer data matters).

### 4.7 Email notifications

Two triggers found: order-created, and order-status-changed. In the reference system, **both send to a hardcoded personal Gmail address as the primary/always-cc'd recipient**, with the real per-customer lookup either commented out (order-created) or used only as a fallback string `"Kunde"` (status-changed). This is classification 3 across the board — the underlying requirement ("notify the relevant party when an order is created or its status changes") is classification 1 and must actually work correctly in Laravel, unlike in the reference system. Recommend Laravel Notifications (queued Mailables) keyed off the real vehicle owner (B2C user or B2B company contact email), built and tested in Checkpoint 12.

---

## 5. Admin feature inventory

| Capability | Old route | Method | Current Laravel route | Laravel status | Classification |
|---|---|---|---|---|---|
| Dashboard summary (counts) | `/admin/dashboard/summary` | GET | `admin/dashboard/summary` | not flagged broken | 1 |
| List B2C customers | `/admin/users/b2c` | GET | `admin/users/b2c` | Minimal filter surface (only `is_active`) in Rust — real requirement is probably richer search (name/email/city) per the old frontend's `UsersView.vue`, which does client-side search across many more fields than the API supports server-side | 1 (richer server-side search is a legitimate improvement, 2) |
| List B2B customers | `/admin/users/b2b` | GET | `admin/users/b2b` | same | 1/2 |
| List all vehicles / by user-type / by user | `/admin/list/vehicles(...)` | GET | `admin/list/vehicles(...)` | Rust version has N+1-style correlated subqueries per row (perf risk); Laravel's `AdminQueryService` (unused dead code) already solves this properly | 1, with a 2 (use `AdminQueryService`, not raw queries) |
| List all orders / by user-type / by user | `/admin/list/orders(...)` | GET | `admin/list/orders(...)` | **CRITICAL — SQL injection**: `AdminController::orders()` interpolates the `order_status` query param directly into a raw SQL string (`AdminController.php:199`, used at lines 209 & 218) | 3, live Laravel bug, **critical severity** — fix immediately (Checkpoint 1 or 8, not later). Fix is trivial: swap in the already-written, correctly-parameterized `AdminQueryService`, which is currently unused dead code. |
| B2C activate/deactivate | `/admin/b2c/{user_id}/status` | PATCH | `admin/b2c/{userId}/status` | Narrow boolean toggle, scoped to `Privatkunde` — correct pattern, not the anti-pattern the brief warns about | 1 |
| B2B activate/deactivate | `/admin/b2b/{b2b_id}/status` | PATCH | `admin/b2b/{b2bId}/status` | Same, correct | 1 |
| Vehicle report document transfer (from TIM sync) | `/admin/vehicle/report/transfer` | POST | `admin/vehicle/report/transfer` | Reasonably built — dedupe/idempotency checks present | 1 |
| Vehicle report document upload | `/admin/vehicle/report/upload` | POST | `admin/vehicle/report/upload` | Reasonably built | 1 |
| Vehicle report document publish | `/admin/vehicle/report/publish/{id}` | PATCH | `admin/vehicle/report/publish/{documentId}` | Reasonably built; blocks delete (not publish) on `delivered` orders, though the Rust version's block-check included a typo'd status string as a safety net (`'delevered'`) — a data-quality smell worth checking current prod data against if any exists | 1 |
| Vehicle report document delete | `/admin/vehicle/report/delete/{id}` | DELETE | `admin/vehicle/report/delete/{documentId}` | Reasonably built | 1 |
| Order approve/reject (TÜV SÜD track) | see §4.1 | — | not confirmed separately in Laravel audit — verify in Checkpoint 6/11 | — | 1 (approve) / 5 (reject — dead route upstream, confirm intent) |
| Order confirm (DEKRA/"others" track) | `/order/others/confirm` | POST | `order/others/confirm` | No precondition on current status (any status → `confirmed`); no audit-log entry (inconsistent with approve/reject) | 3 — fix in Checkpoint 6 via the same `TransitionOrderStatus` action |
| Create inspection station (master data) | `/order/stations/create` | POST | `order/stations/create` | **No admin/role check at all**, in either Rust or Laravel — any authenticated customer can create master-data inspection stations | 3, live bug (both systems) — needs an admin-only gate |

---

## 6. Existing Laravel module — production-readiness verdicts

| Module | Verdict | Key problems (file:line where known) |
|---|---|---|
| **Vehicle** | Partially built — needs fixes | `findByOwner` missing ownership check (`VehicleController.php:164-185`). Otherwise the best-scoped module besides Workshop — `VehicleScopeService` is correctly centralized and wired. |
| **Vehicle documents** | Production-ready-ish | Ownership check confirmed present (unlike the Rust reference, where it was commented out) — still needs a formal `VehicleDocumentPolicy` + regression test rather than relying on inline controller logic. |
| **Workshop** | Best-built module in this domain | `authorizeOwner()` helper, structured request rules, encrypted IBAN/BIC/account holder. Use as the template for fixing the others. |
| **Order** | Partially built — needs fixes | `createStation` has no role check; no status-transition validation anywhere (see §4.2); API-key webhook gating pattern itself is sound and worth keeping. |
| **Offer** | Scaffolding only / not usable as-is | `customerSelect` has **zero ownership verification** — BOLA on a financial-decision endpoint (`OfferController.php:172-220`). `customerList` is correctly scoped. |
| **Profile** | Scaffolding only / not usable as-is | Live `ProfileController::updateAddressContact` has **no ownership check** (`ProfileController.php:78-119`) — IDOR. A correct, unused `ProfileService` + `AddressContactRequest`/`PreferencesRequest` FormRequests already exist and just need wiring in. |
| **B2B** | Scaffolding only / not usable as-is | `showByUser` is an **IDOR** — any Firmenkunde user can view any other company's data by URL id (`B2BController.php:120-146`). `update` checks membership but accepts any role, not just `'owner'`. A correct, unused `B2BService` already exists. |
| **Admin** | Scaffolding only / not usable as-is | `orders()` has a **SQL injection** via the `order_status` query param (`AdminController.php:199`). A correct, unused `AdminQueryService` + `EnsuresAdmin` trait already exist. |
| **Image** | Scaffolding only / not usable as-is — **critical vulnerability** | `signedUrl`/`delete` accept an arbitrary S3 key with no ownership scoping and no prefix restriction — read/delete access to the entire bucket, every module's files, from any authenticated user (`ImageController.php:51-86`). |
| **Tim** | Partially built | Consistent Admin-only checks; SOAP XML built via unescaped string interpolation (low real-world risk since values are config-sourced, but fragile); singleton session token. |
| **DekraProcess** | Partially built — needs authorization + webhook auth | Solid validation and transactional client-ID generation; **no role checks on any endpoint** (should likely be Admin/internal-only); `receiveTerminbestaetigung` webhook has **zero authentication** (only rate-limiting) unlike the TÜV SÜD webhooks, which require an API key. |

**Test coverage:** zero. No Feature/Unit test in the repo references any model or controller in this entire domain (confirmed via full-tree search). **No model factories** exist for any of ~20 domain models — `UserFactory` is the only factory in the app. Every checkpoint below must ship its own factories and tests; there is nothing to extend.

**Storage pattern:** S3 (`Storage::disk('s3')`, temporary signed URLs, 15min–3h depending on context) is the dominant, consistent pattern for every document type in this domain (vehicle documents, vehicle report documents, TIM XML, DEKRA XML). Avatar uploads (already-shipped auth module) are the one deliberate exception, using local/public disk — confirm this split is intentional before assuming domain documents should ever move off S3.

---

## 7. Old frontend → endpoint cross-reference

See the full endpoint map produced by the frontend-audit agent (B2C dashboard, vehicle table, document upload, order creation modal, offers card, B2C onboarding wizard, Admin panel/users/vehicles/orders views, admin modals) — reproduced in condensed form in `docs/B2C_ADMIN_IMPLEMENTATION_PLAN.md` §2 (Route-to-page mapping) rather than duplicated here. Key UX patterns confirmed worth preserving: split "active vs. completed" vehicle dashboard sections; expandable vehicle row with Timeline/Documents/Offers/Inspection-location/Vehicle-spec cards; German 3-segment license-plate input with "ich weiß es nicht" (I don't know) checkboxes that null out optional fields instead of blocking submission; drag-and-drop document upload with a duplicate-type "replace" flow; Admin's deep-link query params (`?vehicleId=`/`?auftragsnummer=`) used by notification emails to jump straight to a row.

**Dead/incomplete old-frontend code found (do not treat as reference UX):**
1. `ForgotPasswordView.vue` — already known, simulated, never called a real endpoint (already fixed in the auth module).
2. `Step4PaymentMethod.vue` (B2C onboarding wizard) — hidden from the flow, its "payment" fields are actually a duplicate of the appointment step, and its submit makes no API call at all. Payment is not a real, working feature anywhere in the reference system.
3. `Step3Appointment.vue`'s slot-conflict dialog — `hasConflict` is hardcoded `false`; the conflict-detection UI can never trigger. Time slots are a hardcoded 4-option list, not fetched.
4. `DdfExpanded.vue`'s `mockOffers` — dead constant, superseded by the real offers API; the "Angebot annehmen" button is permanently disabled/decorative (selection actually happens by clicking an offer's radio circle).
5. Settings & payment history pages — routes explicitly commented out in the old app with a "hidden for now (unfinished)" note. Not a reference for anything.
6. `AdminPanel2.vue` — an orphaned duplicate of the admin dashboard, not wired into any route. Dead file.
7. Admin "Systemstatus" service-health card — hardcoded fake statuses, no real health-check backend.
8. `CUSTOMER_PAYMENT_FEATURE_ENABLED` flag — gates a "Bezahlen" (pay) icon in the order timeline, permanently off. Payment-in-timeline is scaffolded UI with no backend, anywhere.
9. `DeleteAccount.vue` — not broken, but intentionally a "contact us to delete" mailto link, no self-service delete endpoint exists anywhere in the reference system.

---

## 8. Hardcoded secrets/values found (Rust reference — must not carry into Laravel; Laravel findings noted separately)

| Value | Where | System |
|---|---|---|
| `EXPECTED_API_KEY = "AKIAZI2PK2IT5KC3EZBV"` (AWS-key-shaped literal) | `order_tuvsud/tuvsud_order_handler.rs:57` | Rust |
| Insecure env-var fallbacks: `"secret"` (TÜV SÜD token), `"supersecret"` (partner number), `"DEMO:TEST"` (product key), a literal partner URL, `"my-bucket"` (S3 bucket) | `main.rs:60-68` | Rust |
| Personal Gmail `jupiterbarua@gmail.com` as hardcoded email recipient (3 separate call sites) | `email_send/*.rs` | Rust |
| Hardcoded platform email `platform@leasyback.com`, sender `service@leasyback.com`, admin panel URL embedded in emails | `email_send/order_create_admin_notify.rs` | Rust |
| Hardcoded TÜV SÜD contact person "Jannis Gremler" + phone/email in every order payload | `order_tuvsud/tuvsud_order_handler.rs` | Rust |
| Raw JWT + claims logged via `println!` on every request; SendGrid API key logged via `println!` | `middleware.rs`, `email_send/status_notify.rs` | Rust |
| **SQL injection** via unescaped `order_status` query param | `AdminController.php:199,209,218` | **Laravel — live, fix required** |
| **Arbitrary S3 key** read/delete, no prefix or ownership scoping | `ImageController.php:51-86` | **Laravel — live, fix required, critical** |

None of the Rust-side hardcoded secrets were found reproduced in the current Laravel codebase by this audit (the two Laravel-side findings above are different bugs, not copies of the Rust ones) — but this was not exhaustively re-verified for every config value; a config/secrets review is recommended as part of Checkpoint 1.

---

## 9. Cross-cutting decisions this audit surfaces (see §13 of the plan for the full confirmation list)

- **Permission architecture**: adopt native Laravel Policies keyed off `user_type` (matches the 4-role reality — Privatkunde/Firmenkunde/Werkstatt/Admin — and the pattern already partially working in `VehicleScopeService`/`WorkshopController`), or adopt the already-installed-but-unused `spatie/laravel-permission` for finer-grained future Admin permission tiers? Recommendation: Policies now, keep spatie available for later if Admin ever needs staff-level permission tiers — don't adopt a package with zero current use case just because it's installed.
- **Audit log consolidation**: `leasyback_order_audit_log` and `leasyback_order_status_updates` currently overlap in purpose and are written inconsistently by different handlers. Pick one going forward (recommend keeping `leasyback_order_status_updates` for status transitions specifically, since it already has `auth_source`/`caller_ip` columns suited to that, and use `leasyback_order_audit_log` for broader order-lifecycle events like creation/approval).
- **Dead schema**: `user_workshops` (many-to-many workshop membership) and `vehicle_report_document_logs` (audit trail) exist in migrations with no code using them. Decide: wire them up for their evident intended purpose, or drop them.
