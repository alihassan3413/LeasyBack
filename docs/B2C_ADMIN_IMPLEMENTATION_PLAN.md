# B2C / Admin Implementation Plan

Status: **Checkpoint 0 (audit + plan) — awaiting approval. No production code has been written.**

Read alongside `docs/B2C_ADMIN_MIGRATION_AUDIT.md` (full feature inventory + classifications), `docs/B2C_ADMIN_PERMISSION_MATRIX.md`, and `docs/B2C_ADMIN_STATUS_MATRIX.md`.

---

## 1. What changes about the plan given what already exists

The original checkpoint template (in the brief) assumes building this domain from nothing. The audit found that's not the case: `app/Modules/UserProfile/{Profile,Vehicle,Order,Offer,Workshop,B2B,Image,Admin,Tim}` and `app/Modules/DekraProcess` already exist, with a database schema that's already migrated and, in several places, already an improvement over the Rust reference (bigint `users.id` + UUID domain keys as the brief requires, encrypted workshop banking fields, Postgres CHECK constraints, a correctly-centralized `VehicleScopeService`). It also has five confirmed live security bugs (one critical SQL injection, one critical arbitrary-file-access vulnerability, two IDOR bugs, one BOLA on a financial action), zero test coverage, and zero model factories anywhere in the domain.

**This changes Checkpoint 1's job**: not "design and build a foundation," but "assess, hardened, and test the foundation that's already there," fixing the highest-severity bugs before any new B2C/Admin feature work is layered on top of them. The checkpoints below are renumbered/adjusted from the brief's suggested template to reflect this.

---

## 2. Domain boundaries

| Module | Owns | Does not own |
|---|---|---|
| **UserProfile** (own name matches the existing `app/Modules/UserProfile` root — kept) | Personal/contact information: `user_profiles`, `contacts`, `addresses`, `phone_numbers`, `user_preferences`. One profile per user. | Vehicle, order, or document data — those reference a user/company id, they don't live inside Profile. |
| **Vehicle** | Vehicle identity and ownership: `vehicles`, `vehicle_audit_log`. Owns the `VehicleScopeService` (B2B/B2C/Admin scoping) as shared logic other modules call into, not duplicate. | Documents (separate sub-concern below), orders, offers. |
| **Document** (currently split across `Vehicle`'s `vehicle_documents` and `Admin`'s `vehicle_report_documents` — audit found no reason to merge these, they have genuinely different ownership/visibility rules) | File metadata + S3 storage rules for both customer-uploaded documents (`vehicle_documents`) and Admin-managed report/invoice documents (`vehicle_report_documents`, `vehicle_report_document_logs`). | The vehicle/order records they attach to. |
| **Order** | Lifecycle and status transitions: `leasyback_orders`, `leasyback_order_status_updates`, `leasyback_order_audit_log`, `leasyback_order_confirmations`, `inspection_stations`. Owns `TransitionOrderStatus` as the *only* way `order_status` changes. | Offers (separate module, references `order_id`), logistics (separate module, references `auftragsnummer`). |
| **Offer** | Buyback offer lifecycle: `leasyback_offers`, `leasyback_offer_audit_log`. Already the best-implemented state machine in the domain — extend, don't replace. | Order status itself (offers reference an order but don't drive its status without an explicit product decision, see §13). |
| **Workshop** | Workshop company records: `workshops` (already 1:1 with `user_id` in the current schema — simpler than the Rust many-to-many, confirmed a deliberate improvement). | Vehicle/order assignment to a workshop — **not modeled anywhere in either system today**; if this becomes a real requirement it's new work, not a port (see §13). |
| **Logistics** (new module — schema exists, zero implementation in either system) | `logistics_address_profiles`, `leasyback_order_logistics`. | Everything else — this is a self-contained pickup/delivery scheduling concern layered on top of an existing order. |
| **B2B** (deferred this phase per the architecture decision, but its existing routes are live and must be secured regardless) | `b2b`, `user_b2b`. | Vehicles/orders themselves (referenced via nullable FK, not owned). |
| **Admin** | Privileged operational actions only: dashboards, cross-customer search/listing, activation toggles, TIM sync triggers, report-document publish/transfer, offer create/publish/cancel, order approve/reject/transition. **No new models.** Every Admin controller calls the same Vehicle/Order/Offer/Profile actions and policies as the customer-facing side, just with an Admin-scoped Policy branch. | Anything with its own table — if an "Admin*" model is ever proposed, that's the anti-pattern the brief explicitly forbids. |
| **DekraProcess** (existing, adjacent module — TÜV/DEKRA-style external inspection order integration, separate from the `order_tuvsud`/`order_others` domain already inside `UserProfile\Order`) | Its own existing tables (`clients`, `dienstleistungsobjekt`, `besichtigung_orte`, `kunden_auftrag`, `anlage_liste`, `auftrag_partner`, `quittungen`+children). | Needs a security pass (no role checks anywhere, unauthenticated webhook) but is not being restructured this phase — flagged for hardening in Checkpoint 12. |

**Shared concepts that must not be duplicated:**
- Ownership scoping (B2C-owns-via-`b2c_user_id` / B2B-owns-via-`b2b_id` / Admin-sees-all) — one `VehicleScopeService`-style resolver per resource, reused by both the customer controller and the Admin controller for that resource. Admin must call the *same* scoping service with an "all" mode, not reimplement the join logic.
- Admin authorization — one central mechanism (Policy `before()` hook or shared trait), not per-controller string checks. See permission matrix.
- S3 document handling (upload, presigned URL generation, delete) — one small set of helpers reused by Vehicle documents, Vehicle report documents, and (if brought into scope) workshop logos, each still going through their own ownership-scoped Policy before touching storage.

---

## 3. Database design — what's kept, what's fixed, what's new

The full reconstructed schema (25 tables) is in the audit §6/agent findings; not repeated here in full. Summary of decisions:

**Kept as-is** (already correct, already migrated): `users` (bigint id, existing `user_type`/`is_active`), `vehicles`, `vehicle_audit_log`, `vehicle_documents`, `workshops` (1:1, encrypted banking fields), `addresses`/`contacts`/`phone_numbers`, `user_profiles`, `user_preferences`, `b2b`/`user_b2b`, `leasyback_orders`, `leasyback_order_confirmations`, `leasyback_order_status_updates`, `leasyback_order_audit_log`, `leasyback_offers`, `leasyback_offer_audit_log`, `inspection_stations`, `vehicle_report_documents`, `tim_token`/`tim_bewertung`/`vehicle_assessments`/`assessment_documents`, DekraProcess's own tables, `logistics_address_profiles`/`leasyback_order_logistics` (schema kept, business logic is new work).

**Small schema fixes to make in Checkpoint 1** (not full rebuilds, targeted additive migrations):
- `vehicles.vehicle_belongs` has no CHECK constraint in the current Laravel migration enforcing it's `'B2B'`/`'B2C'` (the Rust reference has this constraint; Laravel doesn't) — add it, Postgres-only per existing project convention (see `docs/AUTH_MODULE.md` for the established pattern of Postgres-only additive migrations).
- Confirm (don't just assume) whether `user_b2b`'s `unique(user_id)` (a user belongs to at most one company) is an intentional simplification over Rust's true many-to-many — flagged in §13.

**Dead schema to resolve** (wire up or drop, don't leave silently unused): `user_workshops`, `vehicle_report_document_logs`.

**New this phase:** nothing at the table level — Logistics' tables already exist, they just have no Actions/Controllers/Policies yet (Checkpoint 12 territory, pending the scope decision in §13).

**UUID vs. bigint discipline:** every table above already follows the intended rule — `users.id` bigint, every domain-entity primary key (vehicle, order, offer, document, workshop, address, contact, b2b) is UUID, and foreign keys between domain entities use the UUID, while any FK back to `users` uses the bigint `id`. No public-UUID-as-every-FK anti-pattern found; keep this discipline for any new tables (Logistics).

---

## 4. Ordered checkpoints

Each checkpoint below produces, where applicable: migration, model, enum, Form Request, Policy, Action, thin controller, API Resource or typed Inertia props, routes, focused tests, doc update. Every checkpoint stops for review before the next begins, matching the pattern used for the auth migration.

### Checkpoint 1 — Foundation hardening (not "build from scratch")
- Fix the **critical Image-controller vulnerability** first (arbitrary S3 key read/delete, no ownership scoping) — highest severity finding in the entire audit, blocks everything else that touches S3 with confidence.
- Fix the **critical Admin SQL injection** (`AdminController::orders()`) by wiring in the already-correct, currently-unused `AdminQueryService`.
- Add the two missing schema fixes from §3.
- Add model factories for every domain model (currently zero exist beyond `UserFactory`) — this alone unblocks every subsequent checkpoint's tests.
- Decide and document the Policy architecture (native Policies, confirmed in the audit) and write the base `Policy` scaffolding pattern once, reused everywhere after.
- Regression tests proving the two critical fixes actually block the exploit (attempt the SQL-injection payload, attempt the arbitrary S3 key access) — not just "the code looks fixed."

### Checkpoint 2 — Profile/preferences/address backend
- Fix the address/contact **IDOR** by wiring the already-correct, unused `ProfileService` + `AddressContactRequest`/`PreferencesRequest` into `ProfileController` (not a rebuild).
- `ProfilePolicy`, tests proving cross-user update is blocked.

### Checkpoint 3 — Profile frontend
- Inertia pages for B2C self-service profile (address/contact/preferences), matching the old app's `B2cAccountView`/`AccountDetail`/`ContactPerson` UX shape, built with the component conventions already established in the auth work (`FormField`, brand styling where it makes sense for authenticated-app pages — confirm with product whether authenticated pages use the brand-teal auth look or the existing generic dashboard theme, §13).

### Checkpoint 4 — Vehicle backend
- Fix `findByOwner`'s missing ownership check.
- Formalize `VehiclePolicy` on top of the already-good `VehicleScopeService`.
- Vehicle document Policy + regression test (the Rust critical bug here was already avoided in Laravel — prove it with a test, don't just trust it).
- Verify the 10MB/type allow-list is actually enforced (not confirmed field-by-field in the audit).

### Checkpoint 5 — Vehicle frontend
- Dashboard vehicle table (active vs. completed split), add-vehicle modal (German 3-segment plate input, "ich weiß es nicht" pattern), document upload modal with the duplicate-type "replace" flow — all real, confirmed-worth-preserving UX patterns from the old frontend audit.

### Checkpoint 6 — Order backend and status workflow
- Build `TransitionOrderStatus` action with the explicit transition table from `docs/B2C_ADMIN_STATUS_MATRIX.md` §1 — this replaces the free-text status-override anti-pattern the brief specifically warned about.
- Fix the Offer `customerSelect` **BOLA** (zero ownership check today) — add the missing ownership verification.
- Move the TÜV SÜD webhook API key from wherever it currently lives to config (confirm it's not a literal already — audit didn't find a Laravel-side copy of the Rust hardcoded key, but verify).
- `OrderPolicy`, `OfferPolicy`, tests for every transition in the status matrix (valid and invalid attempts).
- `createStation` currently has no role check in either system — add one (Admin-only).

### Checkpoint 7 — Order/Offer frontend
- Order-creation modal (station/date/time picker, fee-acknowledgement gate), order-status timeline card, offers card with real accept/select flow (fixing the old frontend's permanently-disabled "Angebot annehmen" button now that a real, ownership-checked select action exists).

### Checkpoint 8 — Admin permissions and shared admin layout
- Central Admin authorization mechanism (Policy `before()` hook or shared gate), replacing the ~20 scattered `user_type === 'Admin'` checks.
- Admin Inertia layout (Kunden/Fahrzeuge/Aufträge nav, matching the old admin panel's information architecture without copying its dead/decorative bits — no fake "Systemstatus" health card, no orphaned `AdminPanel2`-style duplication).

### Checkpoint 9 — Admin customer management
- Fix the B2B `showByUser` **IDOR** and the `update` role-acceptance bug (should require `role === 'owner'`, currently accepts any) by wiring the already-correct, unused `B2BService`.
- B2C/B2B customer list+detail+activate/deactivate Admin UI, with real server-side search (the reference system's search surface is thinner than the old frontend's client-side filtering implies it should be — a legitimate improvement opportunity, not scope creep).

### Checkpoint 10 — Admin vehicle management
- Admin vehicle list/detail/create-on-behalf-of-customer.
- Extend the already-reasonably-built vehicle report document transfer/upload/publish/delete flow; no rebuild needed there.

### Checkpoint 11 — Admin order management
- Admin order list/detail, approve/reject (confirm reject is wanted first, §13), status transitions via the same `TransitionOrderStatus` action from Checkpoint 6 (never a second, parallel "admin override" path).
- Admin offer create/publish/cancel UI.

### Checkpoint 12 — Documents, notifications, operational hardening
- Real order/offer notification emails to the actual vehicle owner (the reference system's biggest email flaw — a hardcoded personal address as the effective only recipient — must not carry over; this is a real requirement to get right, not a bug to reproduce).
- DekraProcess hardening: role checks on every endpoint, shared-secret/signature verification on the `receiveTerminbestaetigung` webhook (matching the pattern the TÜV SÜD webhooks already use correctly).
- TIM integration hardening: resolve the `PasswortSHA1`-but-raw-password question with whoever owns the vendor relationship (§13) before wiring it in; fix the singleton-session-token race risk; handle malformed external XML as a recoverable error, not a panic.
- Logistics: build only if product confirms scope/priority (§13) — schema is ready, nothing else is.
- Resolve the two dead-schema tables (`user_workshops`, `vehicle_report_document_logs`) — wire up or drop.
- Consolidate the two overlapping order audit-log tables per the status matrix §6 recommendation.

### Checkpoint 13 — Final B2C/Admin integration and end-to-end testing
- Full test suite pass across every module built in this phase.
- Manual QA pass across the full B2C journey (register → profile → add vehicle → book inspection → receive offer → accept offer → track status) and the full Admin journey (view dashboard → find customer → find vehicle → manage documents → manage order status → manage offers).
- Verify the permission matrix with real authorization tests, not just manual spot-checks — every "❌" cell in `docs/B2C_ADMIN_PERMISSION_MATRIX.md` should have a test proving it's actually blocked.

---

## 5. Estimated checkpoint count

**13**, matching the brief's template count — the content shifted (more hardening-of-existing-code in early checkpoints, less pure from-scratch scaffolding) but the number of natural stopping points didn't change much given the domain's real size.

---

## 6. Risks

- **Zero existing test coverage + zero factories** across ~20 models means every checkpoint's "add tests" step is real, non-trivial work, not a formality — budget for it accordingly rather than treating it as a checkbox.
- **The critical Image and SQL-injection bugs are live in the current codebase today**, not hypothetical — if this app has any real traffic already, those should probably be patched immediately regardless of when Checkpoint 1 formally starts. Recommend confirming with the project owner whether an out-of-band emergency fix (outside the checkpoint sequence) is warranted before Checkpoint 1 even begins.
- **External integrations (TÜV SÜD, TIM, DEKRA) are only partially understood from code alone** — the audit surfaced real open questions (TIM's password-hashing field name mismatch, whether DEKRA has a real API at all vs. a permanently-fabricated success response, whether TÜV SÜD's webhook can ever drive post-`confirmed` statuses) that need a person with vendor knowledge, not more code reading, to resolve.
- **Order status transition rules beyond the two enforced-in-reference ones (`order_requested`→`order_placed`/`discarded`) are reconstructed from status *names* and the terminal-status set, not from any enforced rule in either system** — the transition table in the status matrix is a reasonable reconstruction, not a confirmed spec. Flag as a decision point, don't treat it as settled.
- **B2B is technically live and reachable today** (routes exist, IDOR/role bugs are real) even though full B2B implementation is deferred to a later phase — the fixes to `B2BController` in Checkpoint 9 are security hardening of an existing, exposed surface, not "starting B2B early."

---

## 7. Product decisions requiring confirmation before or during implementation

1. **Emergency patch timing** — should the critical Image and Admin-SQL-injection vulnerabilities be fixed immediately, out of band, rather than waiting for Checkpoint 1?
2. **Order status transition rules** — is the reconstructed table in `docs/B2C_ADMIN_STATUS_MATRIX.md` §1 correct? Specifically: can `reinspection`/`reworkshop` loop, does customer-initiated cancellation exist and from which states, does TÜV SÜD's real webhook ever drive statuses beyond `confirmed`, and is `reject` (built but never shipped in the reference system) a wanted feature?
3. **Offer selection ↔ order status** — should accepting an offer automatically transition the order (e.g., into `workshop`), or stay fully independent as it is today?
4. **DEKRA/"others" order-creation** — does a real DEKRA API exist to integrate, or does this remain a manual/Admin-driven flow (the reference system fabricates a success response with no real call)?
5. **TIM credential format** — is `PasswortSHA1` a real pre-hashed value maintained out-of-band, or is the reference implementation simply broken/never-tested? Needs the vendor relationship owner, not more code reading.
6. **Workshop↔order/vehicle assignment** — the brief's example Admin action "AssignOrderToWorkshop" implies a relationship that **does not exist in either the Rust or current Laravel schema**. Is this a real, prioritized requirement (new work) or aspirational example text?
7. **Logistics (pickup/delivery scheduling)** — schema exists, zero implementation anywhere. In scope for this phase (Checkpoint 12) or deferred entirely?
8. **`user_b2b` one-company-per-user constraint** — confirm this is an intentional simplification over Rust's true many-to-many, not an oversight.
9. **Dead schema** (`user_workshops`, `vehicle_report_document_logs`) — wire up for their evident intended purpose, or drop?
10. **Vehicle deletion** — no delete-vehicle capability was found anywhere in either system. Confirm this is intentional before Checkpoint 4/10 assumes it stays that way.
11. **Account deletion** — the old frontend's B2C "delete account" is a mailto-support link with zero backend involvement. Confirm whether Admin needs a real deactivate/delete-customer-data action, or whether the existing `is_active` toggle plus manual data handling is sufficient.
12. **Profile frontend visual language** (Checkpoint 3) — should authenticated B2C pages (profile, dashboard) adopt the brand-teal/orange auth-page look, or stay with the app's existing generic dashboard theme? These are different audiences/contexts (public marketing-adjacent auth pages vs. logged-in app), worth a deliberate call rather than a default.

---

## 8. Exact first implementation checkpoint

**Checkpoint 1 — Foundation hardening**: fix the critical Image-controller and Admin-SQL-injection vulnerabilities, add the missing `vehicle_belongs` CHECK constraint, add model factories for every domain model, establish the Policy architecture pattern, and ship regression tests proving both critical fixes actually hold. No new B2C/Admin-facing feature work happens in this checkpoint — it's entirely about making the existing foundation safe and testable before building on it.
