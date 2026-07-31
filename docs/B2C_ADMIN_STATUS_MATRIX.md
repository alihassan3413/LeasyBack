# B2C / Admin Status Matrix

Companion to `docs/B2C_ADMIN_MIGRATION_AUDIT.md`. Every status-bearing entity found in the domain, its full value set, and — critically — the transition rules that **do not currently exist** anywhere in the reference system and must be built as real, explicit rules in Laravel rather than a generic "set any status" endpoint.

---

## 1. Order status (`leasyback_orders.order_status`)

**Full canonical value set** (from the Postgres CHECK constraint, already present in the Laravel migration): `order_requested`, `order_placed`, `confirmed`, `discarded`, `cancelled`, `inspected`, `workshop`, `reinspection`, `delivered`, `reworkshop`.

**Finding, stated plainly:** in the reference implementation, there is no state machine. Every handler that changes `order_status` writes an unconditional `UPDATE` (with a row lock, but no `WHERE order_status = <expected current value>` guard) except two: `approve_leasyback_order` (requires `order_requested`) and the never-mounted `reject_leasyback_order_request` (also requires `order_requested`). This means, as built, an order can currently be pushed from `delivered` back to `order_placed` by anyone holding an Admin JWT or the hardcoded API key. **Do not port this. Build the table below as real, enforced code** (recommend a `TransitionOrderStatus` action with an explicit `$allowedTransitions` map, executed in a DB transaction with `lockForUpdate()`).

### Recommended transition table

| From | To | Actor | Trigger | Side effects | Reversible? |
|---|---|---|---|---|---|
| *(none — initial)* | `order_requested` | Firmenkunde (B2B) only | Customer books an inspection/appointment for a company vehicle | Order row created; no external API call yet | N/A |
| *(none — initial)* | `order_placed` | Privatkunde, or Admin on behalf of anyone | Customer/Admin books an inspection/appointment for a non-B2B vehicle | Order row created; real external provider call made (TÜV SÜD) or provider call recorded (DEKRA/"others" — confirm real integration exists, audit §4.1) | N/A |
| `order_requested` | `order_placed` | Admin only | `approve` action | Real external TÜV SÜD API call made; **do not** persist the resulting auth token into the order's payload column (audit §4.1 — Rust does this, it's a bug, not a requirement) | No — moves forward only |
| `order_requested` | `discarded` | Admin only | `reject` action | None found in the reference beyond the status write | Confirm with product this action is actually wanted (§4 below) — terminal either way if built |
| `order_placed` / `confirmed` (webhook path) | `confirmed` | System (TÜV SÜD/DEKRA callback, API-key authenticated) | External provider confirms the booking | Two status-update audit rows written in the reference system (see §5 below on consolidating this) | Confirm — probably not, treat as forward-only |
| `confirmed` | `inspected` | Admin only | Manual progression once inspection has occurred | — | Confirm reversibility with product |
| `inspected` | `workshop` | Admin only | Vehicle sent to a workshop for repair | — | — |
| `workshop` | `reinspection` | Admin only | Repair complete, re-inspection scheduled | — | — |
| `reinspection` | `reworkshop` | Admin only | Re-inspection found more work needed | — | Loop back to `workshop` is presumably possible from here — confirm the real cycle with product; it's not evidenced anywhere in the reference code, only the status name suggests it |
| `reinspection` | `delivered` | Admin only | Re-inspection passed, vehicle/process complete | Terminal | No |
| any non-terminal status | `cancelled` | Admin (customer-initiated cancellation, if that's a real requirement — not evidenced in the reference handlers reviewed, confirm with product) | — | Terminal | No |

**Terminal statuses:** `delivered`, `cancelled`, `discarded`. (These three are exactly the set already used by the reference system's "does this vehicle have an unfinished order" guard — `order_status NOT IN ('delivered','cancelled','discarded')` — confirming they're the intended terminal set, even though no explicit state machine enforces it.)

**Open questions this table surfaces (see plan §13):**
1. Is `inspected → workshop → reinspection → reworkshop/delivered` really a strict linear sequence, or can it loop (`reworkshop → reinspection` again)? The reference system never enforces or evidences a real cycle — only Admin's free-text override, which accepts any-to-any.
2. Does TÜV SÜD's real webhook ever drive statuses beyond `confirmed` (i.e., can their system report "inspection complete" automatically), or are `inspected`/`workshop`/`reinspection`/`reworkshop`/`delivered` always manual Admin actions? The reference code suggests always-manual (the API-key webhook path is hardcoded to only ever set `confirmed`).
3. Is customer-initiated cancellation a real requirement, and from which statuses? Not evidenced anywhere in the audited code.
4. Does the never-shipped `reject` action matter, or was it abandoned mid-build?

---

## 2. Offer status (`leasyback_offers.offer_status`)

**Full value set:** `draft`, `published`, `selected`, `closed`, `cancelled`.

This one **is** already a real, enforced state machine in the reference system (both Rust and current Laravel) — it's the one part of the domain that got this right.

| From | To | Actor | Trigger | Side effects | Reversible? |
|---|---|---|---|---|---|
| *(none — initial)* | `draft` | Admin only | `create` | — | N/A |
| `draft` | `published` | Admin only | `publish`, blocked if another offer for the same order is already `selected` | Now visible to the customer | No |
| `draft` / `published` | `cancelled` | Admin only | `cancel`, blocked if a `selected` offer already exists for the order | `cancellation_reason` recorded | No |
| `published` | `selected` | The vehicle's actual owner (Privatkunde/Firmenkunde) — **must be ownership-checked; currently a live bug, see permission matrix** | Customer accepts an offer | All *other* `published` offers for the same order are auto-transitioned to `closed` | No |
| `published` (a sibling, once another is selected) | `closed` | System (automatic side effect of a sibling's `select`), not a direct actor action | — | — | No |

**Terminal statuses:** `selected`, `closed`, `cancelled`. **Invariant enforced at the DB level** (unique partial index): at most one `selected` offer per order.

**Open question (audit §4.5, plan §13):** selecting an offer currently has **zero effect** on `leasyback_orders.order_status` in either system. Decide whether offer selection should automatically drive an order transition (e.g., into `workshop`) or remain fully independent, Admin-driven separately.

---

## 3. User / B2B activation status (`users.is_active`, `b2b.is_active`)

Simple boolean, not a multi-value enum — and correctly implemented as such in both systems (this is the one status field in the whole domain that's already done right).

| From | To | Actor | Trigger | Side effects |
|---|---|---|---|---|
| `true` | `false` | Admin only | `PATCH admin/b2c/{userId}/status` or `admin/b2b/{b2bId}/status` | Existing session/token immediately invalidated on the next request (already correctly handled by the auth module's `EnsureUserIsActive` middleware — reuse it, don't rebuild) |
| `false` | `true` | Admin only | Same endpoints | Customer can log in again |

No terminal state — fully reversible in both directions. This is the correct model for a boolean toggle; **do not** generalize this into a free-text status field the way the order-status endpoint mistakenly does.

---

## 4. Vehicle — no independent status field

The `vehicles` table itself has **no status/enum column** in either the Rust or current Laravel schema. A vehicle's "state" as shown in the old frontend's dashboard is entirely **derived** from its most recent/active order's `order_status` (and, separately, whether it has any `published` vehicle report documents). There is nothing to build a vehicle-status transition table for — don't invent one. If a future requirement needs a vehicle-level state independent of its orders, that's a new product decision, not something the reference system supports.

---

## 5. Vehicle document — `published` boolean (vehicle report documents only)

Only `vehicle_report_documents.published` carries a status-like flag (customer-uploaded documents — `Leasingvertrag`/`vorschaden` — have no publish concept at all; they're visible to their owner immediately).

| From | To | Actor | Trigger |
|---|---|---|---|
| `false` | `true` | Admin only | `publish` action, after transferring/uploading a report/invoice document |
| `true` | `false` | Not evidenced anywhere — unpublish doesn't appear to exist as an action | — |

Deletion is blocked once the linked order reaches `delivered` (the reference system's check includes a typo'd fallback string `'delevered'` alongside the correct value — a data-quality artifact of the reference system, not a rule to replicate; use only the correct canonical status value in Laravel).

---

## 6. Audit trail consolidation (cross-cutting, affects every table above)

Two overlapping, inconsistently-used audit mechanisms exist for orders specifically:
- `leasyback_order_audit_log` — action values `REQUEST_ORDER`, `CREATE_ORDER`, `APPROVE_ORDER`, `REJECT_ORDER`, plus a `STATUS_UPDATE` value that's declared in the CHECK constraint but **never actually inserted** by any handler.
- `leasyback_order_status_updates` — old/new status, `updated_by`, `auth_source` (`api_key`/admin), `caller_ip`. Written only by the confirm/status-update handlers, never by create/approve/reject.

**Recommendation:** use `leasyback_order_status_updates` for every `order_status` transition going forward (it already has the right columns — `auth_source`/`caller_ip` matter for distinguishing webhook vs. Admin-driven changes), and `leasyback_order_audit_log` for broader lifecycle events that aren't pure status changes (creation, approval with its external-call context, offer-related order touchpoints). Every `TransitionOrderStatus` call should write exactly one row to the status-updates table; don't leave this split ambiguous per-handler the way the reference system does.

Similarly, `leasyback_offer_audit_log` (action values `created`/`published`/`selected_by_customer`/`closed_after_customer_selection`/`cancelled`) already maps cleanly 1:1 to the offer state machine in §2 — keep writing one row per transition there, it's already correct.
