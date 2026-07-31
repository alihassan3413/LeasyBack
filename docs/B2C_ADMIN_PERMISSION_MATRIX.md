# B2C / Admin Permission Matrix

Companion to `docs/B2C_ADMIN_MIGRATION_AUDIT.md`. Covers the four actor types found in the domain: **Privatkunde** (B2C individual customer), **Firmenkunde** (B2B company user — out of scope to *implement* this phase per the architecture decision, but included here because Admin/Vehicle/Order resources are shared with B2B and the boundary needs to be explicit), **Werkstatt** (workshop account), **Admin**.

Architecture decision (see audit §9): implement as native Laravel **Policies**, one per domain model, with rules keyed off `$user->user_type` and explicit ownership checks (`$user->id === $resource->b2c_user_id`, etc.) — not scattered `if ($user->user_type === 'Admin')` checks in controllers, and not (yet) `spatie/laravel-permission`, which is installed but has no current use case. Every policy method listed below should exist as a real, named, testable method — `viewAny`, `view`, `create`, `update`, `delete`, plus named custom methods for special transitions.

**Frontend visibility is never authorization.** Every rule below must be enforced server-side in the Policy/Action, regardless of what the Vue/Inertia UI shows or hides.

---

## UserProfile (own address / contact / preferences)

| Action | Privatkunde | Firmenkunde | Werkstatt | Admin |
|---|---|---|---|---|
| `view` (own profile) | ✅ own only | ✅ own only | ✅ own only | ✅ any |
| `create` (own address/contact/preferences) | ✅ own only | ✅ own only | ✅ own only | ❌ (Admin doesn't create on behalf of a customer through this resource) |
| `update` (own address/contact/preferences) | ✅ own only | ✅ own only | ✅ own only | ❌ — Admin has no legitimate use case to edit a customer's personal contact info directly; if a support need arises later, add a named `updateOnBehalfOf` action, don't reuse the customer's own update path |
| `delete` | ❌ — no self-service delete exists in the reference product (contact-support flow only, see audit §7 item 9) | ❌ | ❌ | 🔒 privileged, out of scope this phase (no delete-account admin action found in the reference system either — confirm with product before building one) |

**Fix required before this matrix is enforceable in code:** `ProfileController::updateAddressContact` currently has no ownership check at all (audit §3.1) — wiring in the existing, correct `ProfileService` is what makes "✅ own only" actually true.

---

## Vehicle

| Action | Privatkunde | Firmenkunde | Werkstatt | Admin |
|---|---|---|---|---|
| `viewAny` | ✅ own vehicles only | ✅ own company's vehicles only | ❌ (no vehicle relationship modeled for workshops in the current schema — see audit §3.4) | ✅ all |
| `view` | ✅ if `b2c_user_id === $user->id` | ✅ if `b2b_id === $user->company->id` | ❌ | ✅ any |
| `create` | ✅ creates with self as owner (server-derived, never client-supplied) | ✅ creates with own company as owner (server-derived) | ❌ | ✅ can create on behalf of any B2C/B2B owner (explicit `owner_id`/`owner_type` param, still validated to be a real user/company) |
| `update` | ✅ own only | ✅ own company's only | ❌ | ✅ any |
| `assignProfile` (link a `user_profiles.profile_id` to the vehicle) | ✅ own only, and the assigned profile must belong to the same owner (fix needed — audit §3.2) | ✅ own company only, same constraint | ❌ | ✅ any |
| `delete` | ❌ — no delete endpoint found in the reference system for vehicles; confirm this is intentional (product decision, §13) | ❌ | ❌ | 🔒 not confirmed to exist in the reference system — do not invent a delete without product sign-off |

**Fix required:** `VehicleController::findByOwner` currently accepts any `$ownerId` in the URL with no check against the caller (audit §3.2) — must enforce `view` above.

---

## Vehicle Document

| Action | Privatkunde | Firmenkunde | Werkstatt | Admin |
|---|---|---|---|---|
| `viewAny` (list docs for a vehicle) | ✅ only for vehicles they own | ✅ only for vehicles their company owns | ❌ | ✅ any vehicle |
| `view` (single doc) | ✅ same scoping | ✅ same scoping | ❌ | ✅ any |
| `upload` | ✅ own vehicle only, `document_type` restricted to customer-allowed types (`Leasingvertrag`, `vorschaden` — **not** `gutachten`/report or invoice types, which are Admin-only per the old frontend's `UploadDocumentModal` restricting the dropdown) | ✅ own company's vehicle only, same type restriction | ❌ | ✅ any vehicle, any type including `gutachten`/`Rechnung` (invoice) — via the separate Admin "upload report"/"upload invoice" actions, not the customer upload endpoint |
| `delete` | ✅ own vehicle only, and **not** for admin-uploaded report/invoice types (the old frontend blocks this client-side; enforce it server-side too) | ✅ same | ❌ | ✅ any, via the dedicated `admin/vehicle/report/delete` action |
| `publish` (mark a report doc as customer-visible) | ❌ | ❌ | ❌ | ✅ only — this is an Admin-only concept, no customer-facing equivalent |

---

## Order

| Action | Privatkunde | Firmenkunde | Werkstatt | Admin |
|---|---|---|---|---|
| `viewAny` | ✅ own vehicles' orders only | ✅ own company's vehicles' orders only | ❌ (no order↔workshop assignment modeled currently — audit §3.4/§13) | ✅ all |
| `view` | ✅ same scoping | ✅ same scoping | ❌ | ✅ any |
| `create` (book an inspection/appointment) | ✅ own vehicle only, direct-to-provider (goes to `order_placed` immediately) | ✅ own company's vehicle only, but goes to `order_requested` pending Admin approval, not direct — this is a real, confirmed business-rule difference, not an oversight | ❌ | ✅ any vehicle, on behalf of any owner |
| `approve` (move `order_requested` → provider call → `order_placed`) | ❌ | ❌ | ❌ | ✅ only |
| `reject` (move `order_requested` → `discarded`) | ❌ | ❌ | ❌ | ✅ only — **confirm with product this is actually wanted**; the reference implementation built it but never shipped the route (audit §4.1) |
| `confirm` (external webhook path, e.g. TÜV SÜD/DEKRA system confirming a booking) | n/a — not a user action | n/a | n/a | n/a — this is a **system-to-system** credential (API key), not tied to any user_type at all; keep it that way but move the key to config and route it through the same validated transition action (audit §4.2) |
| `transitionStatus` (named transitions only — `inspected`, `workshop`, `reinspection`, `reworkshop`, `delivered`, `cancelled`) | ❌ | ❌ | ❌ | ✅ only, via `TransitionOrderStatus`, never a raw status string (this replaces the free-text override anti-pattern flagged in the audit) |

---

## Offer

| Action | Privatkunde | Firmenkunde | Werkstatt | Admin |
|---|---|---|---|---|
| `viewAny` (list offers for an order) | ✅ own order only, `published`/`selected` statuses only | ✅ own company's order only, same status restriction | ❌ | ✅ any order, any status including `draft`/`cancelled`/`closed` |
| `create` (draft an offer) | ❌ | ❌ | ❌ | ✅ only |
| `publish` | ❌ | ❌ | ❌ | ✅ only, requires current status `draft` |
| `cancel` | ❌ | ❌ | ❌ | ✅ only, requires current status `draft`/`published`, blocked if a `selected` offer already exists for the order |
| `select` (customer accepts an offer) | ✅ **only the vehicle's actual owner**, requires status `published` (fix required — audit §4.5, currently a live BOLA with zero ownership check) | ✅ same, for own company's vehicle | ❌ | ❌ — **Admin must never be able to select "as" the customer**; the Rust reference had a disabled block for this that's worth keeping disabled-and-explicit in Laravel (i.e., write a test proving Admin gets 403 here, don't just rely on absence of a bypass) |

---

## Workshop

| Action | Privatkunde | Firmenkunde | Werkstatt | Admin |
|---|---|---|---|---|
| `view` | ❌ | ❌ | ✅ own only | ✅ any |
| `create` | ❌ | ❌ | ✅ creates their own (1:1 with their `user_id` in the current Laravel schema) | ❌ (no evidence Admin creates workshops on behalf of someone in the reference system) |
| `update` | ❌ | ❌ | ✅ own only | 🔒 not confirmed in the reference system — likely a legitimate future need (support edits) but not built anywhere today; don't invent it without product sign-off |

---

## B2B Company (out of scope to implement this phase — included for boundary clarity only)

| Action | Privatkunde | Firmenkunde | Werkstatt | Admin |
|---|---|---|---|---|
| `view` | ❌ | ✅ **own company only** — currently a live IDOR in `B2BController::showByUser` (audit §6), must be fixed even though B2B implementation itself is deferred, since the route already exists and is reachable | ❌ | ✅ any |
| `update` | ❌ | ✅ own company only, **and only if the user's `user_b2b.role === 'owner'`** (currently accepts any role — a fix needed even while B2B feature-work is deferred) | ❌ | ✅ any |
| `updateStatus` (activate/deactivate) | ❌ | ❌ | ❌ | ✅ only |

---

## Admin operational resources

| Action | Privatkunde | Firmenkunde | Werkstatt | Admin |
|---|---|---|---|---|
| `viewDashboardSummary` | ❌ | ❌ | ❌ | ✅ only |
| `viewAny` (customer/vehicle/order listings, any filter) | ❌ | ❌ | ❌ | ✅ only |
| `deactivateCustomer` / `reactivateCustomer` (B2C or B2B) | ❌ | ❌ | ❌ | ✅ only — boolean toggle, already correctly scoped in the reference system, keep it narrow (not a free-text status field) |
| `createInspectionStation` (master data) | ❌ | ❌ | ❌ | ✅ only — **currently has no check at all in either system** (audit §5); this is a real gap to close, not a design question |
| `syncAppraisal` / TIM operations | ❌ | ❌ | ❌ | ✅ only |
| `manageReportDocuments` (transfer/upload/publish/delete) | ❌ | ❌ | ❌ | ✅ only |

---

## Notes on enforcement mechanics

- Every "own only" cell above should resolve through a single, reusable scoping mechanism per resource (the audit found `VehicleScopeService` already does this correctly for Vehicle — extend the same pattern to Order/Offer/Document rather than re-deriving ownership logic per controller).
- Every Admin-only cell should go through one central `Admin`-context gate (fixing the ~20 scattered `user_type === 'Admin'` string checks found across the current Laravel controllers into actual Policy `before()` hooks or a shared trait/gate), not per-controller re-implementation.
- "Sensitive admin actions must be auditable" (per the task brief): `deactivateCustomer`, `transitionStatus`, offer `publish`/`cancel`, and report-document `publish`/`delete` should all write to an audit trail (see audit §9's recommendation to consolidate the two currently-overlapping order audit tables) — not just succeed silently.
