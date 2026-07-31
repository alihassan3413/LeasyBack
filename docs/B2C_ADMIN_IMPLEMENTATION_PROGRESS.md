# B2C / Admin Implementation Progress

Read alongside `docs/B2C_ADMIN_IMPLEMENTATION_PLAN.md` (checkpoint definitions) and `docs/B2C_ADMIN_MIGRATION_AUDIT.md` (original findings).

## 1. Completed checkpoints

**Checkpoint 0 (prior session)** — Document-storage architecture: private `documents` Laravel disk (swappable via `DOCUMENTS_FILESYSTEM_DRIVER`), `public` disk kept separate for workshop logos, `VehicleDocumentPolicy`/`VehicleReportDocumentPolicy`/`WorkshopPolicy` + `AuthServiceProvider` introduced, `VehicleDocumentController`/`VehicleReportController`/`WorkshopController` rewritten to authorize a DB-loaded record before touching storage, old arbitrary-key `ImageController`/`Image` module deleted. This also happened to close the plan's "critical Image-controller vulnerability" item ahead of schedule.

**Checkpoint 1 — Foundation Testing and Authorization** — complete:
- Fixed the critical Admin SQL injection in `AdminController::orders()`.
- Added the `vehicles.vehicle_belongs` CHECK constraint (Postgres-only, additive).
- Added `OrderStatus` and `VehicleOwnerType` enums as single sources of truth for status/ownership validation.
- Added model factories for every model touched by this checkpoint's tests.
- Added 18 new Feature tests: authorization tests for the 3 existing Policies (previously untested) + regression tests for the SQL-injection fix.
- Full suite: 122 passed, 4 skipped, 0 failures.

**Checkpoint 2 — Profile/preferences/address backend** — complete:
- Fixed the `ProfileController::updateAddressContact` IDOR by wiring in the already-correct, previously-unused `ProfileService` + `AddressContactRequest`/`PreferencesRequest`.
- Added `ProfilePolicy`, registered for both `LeasybackUserProfile` and `UserPreference`.
- Added 5 Profile-domain model factories.
- Added 15 new Feature tests: owner/non-owner/unauthenticated/Admin behavior across all 5 `ProfileController` endpoints, plus direct Policy-ability tests.
- Full suite: 137 passed, 4 skipped, 0 failures. `vendor/bin/pint --dirty --test` clean. `npm run build` succeeds (frontend untouched this checkpoint).

**Checkpoint 3 — Profile frontend** — complete:
- Two new self-service Inertia pages under `settings/`: **Address & contact** (address, contact person, repeatable phone numbers) and **Preferences** (timezone, language, notification toggles), both using a read/edit-toggle card pattern.
- New session-authenticated web controllers (`Settings\AddressController`, `Settings\PreferencesController`) — thin, delegate to the same `ProfileService` Checkpoint 2 wired in, reusing the same `AddressContactRequest`/`PreferencesRequest` Form Requests unchanged.
- Built a reusable, non-native dropdown (`ui/select/*` on `reka-ui`, wrapped by `form/SelectField.vue`) since none existed in this repo before — used for salutation, country, language, and timezone across both pages, plus the phone-number international-prefix field.
- Built two more reusable components: `settings/SettingsCard.vue` (read/edit card chrome, shared by both pages) and `form/PhoneNumberFieldset.vue` (repeatable add/remove phone rows).
- Added one new `ProfileService` method (`findPreferencesForUser`) — see decisions below, this fixes a real latent bug the naive approach would have reintroduced.
- Added 11 new Feature tests (session `actingAs`, not Sanctum tokens) covering owner/non-owner/unauthenticated/Admin behavior for both new controllers, including the IDOR regression re-proven through the web route.
- Full suite: 148 passed, 4 skipped, 0 failures. `vendor/bin/pint --dirty --test`, `npm run lint`, and `npm run build` all clean.
- **Not verified in a live browser** — the Chrome browser-automation extension wasn't connected in this environment, so the pages were checked via `npm run build` (compiles), `npm run lint` (no issues), and Feature tests asserting exact Inertia prop values end-to-end over real HTTP, but not by actually clicking through the rendered UI. Flagged as a caveat, not silently skipped.

**Checkpoint 4 — Vehicle backend** — complete:
- Fixed the `VehicleController::findByOwner` IDOR — it ran a completely unscoped query trusting the client-supplied `ownerId` alone, so any authenticated user could read any vehicle's full details by pairing a guessed `vehicleId` with its real `owner_id`.
- Added `VehiclePolicy` (`view`/`update`), registered for `Vehicle`, wrapping the already-correct `VehicleScopeService::findVehicleWithAccess` — matching the exact convention `VehicleDocumentPolicy` already established.
- Refactored `VehicleController::update()`/`assignProfile()` to authorize through the new Policy instead of calling `VehicleScopeService` directly — same behavior (404 on no access, not 403), now the formal, testable mechanism.
- Found and fixed a real spec mismatch: `VehicleDocumentController::upload()` allowed files up to 20 MB; the audit's documented reference-system cap is 10 MB. Fixed to `max:10240`.
- Confirmed the Vehicle-document Policy regression-test item was **already satisfied in Checkpoint 1** (`VehicleDocumentPolicyTest` already proves owner/non-owner/admin access) — no new work needed there, just verified.
- Added 13 new Feature tests: `VehiclePolicyTest` (direct ability tests), `VehicleControllerTest` (the `findByOwner` IDOR regression + `update`/`assignProfile` non-owner blocking), `VehicleDocumentUploadTest` (size cap + mime allow-list actually enforced, not just present in source).
- Full suite: 161 passed, 4 skipped, 0 failures. `vendor/bin/pint --dirty --test` clean.

**Checkpoint 5 — Vehicle frontend (this session)** — complete:
- Replaced the placeholder `/dashboard` page with the real vehicle dashboard: active/completed vehicle table split (a vehicle is "completed" once its latest order's status is `delivered`), status badges, per-row actions (edit, upload document).
- New session-authenticated `VehicleController`/`VehicleDocumentController` (under `App\Http\Controllers`, not the Sanctum API ones) — same reason as Checkpoint 3's `Settings\*` controllers: Inertia pages can't call the Sanctum-bearer-token `/vehicle/*` API routes directly.
- Extracted `VehicleService::createVehicle()`/`updateVehicle()`/`uploadDocument()`/`deleteDocument()` from the Sanctum API `VehicleController`/`VehicleDocumentController` (which now delegate to them) so both entry points share one implementation — same pattern as `ProfileService` in Checkpoints 2–3.
- Added `StoreVehicleRequest`/`UpdateVehicleRequest` Form Requests, reused by both the API and web controllers.
- Found and fixed two real, previously-inert bugs in the already-existing-but-unused `VehicleService::listVehiclesWithOrders()` while making it the new web dashboard's data source: it referenced a `s3_key` column that Checkpoint 0 renamed to `path`, and hardcoded the `s3` disk instead of the swappable `documents` disk. Fixed both; did **not** rewire the still-separate, still-correct Sanctum API `dashboard()` method to use this service (would have changed that live endpoint's response shape — see decisions).
- Built the German 3-segment license plate input (`LicensePlateInput.vue`, validation ported from the legacy frontend's `licensePlate.ts`), the vehicle brand list (`vehicleBrands.ts`), a `ui/table/*` primitive set and a `ui/badge/Badge.vue` (neither existed in this repo before), and `AddVehicleModal.vue`/`UploadDocumentModal.vue`.
- Ported the "Leasingende unbekannt" / "Leasinggeber unbekannt" ("ich weiß es nicht") checkbox pattern from the legacy Add-Vehicle modal — but adapted, not copied: the legacy backend required those fields and the old UI faked a placeholder date/empty string to satisfy it; this Laravel backend already allows both fields to be `null`, so the new modal sends real `null` instead of fabricating data.
- Reimplemented the legacy "duplicate document type → replace?" confirm flow entirely client-side (compare against the vehicle's already-loaded document list), since the current backend allows multiple documents of the same type silently — unlike the legacy backend, which rejected the upload outright and drove this same UX off that error.
- Added 13 new Feature tests across two new files (dashboard scoping/creation/update, document upload/delete authorization) plus a `store()` regression test for the Checkpoint 4/5 service-extraction refactor.
- Full suite: 172 passed, 4 skipped, 0 failures. `vendor/bin/pint --dirty --test`, `npm run lint`, `npm run build` all clean.
- **Not verified in a live browser** — same environment limitation as Checkpoint 3 (Chrome extension not connected). Correctness checked via `npm run build`/`npm run lint` plus Feature tests asserting exact Inertia prop shapes over real HTTP; the modals' drag-and-drop, dropdown interactivity, and visual layout have not been clicked through.

## 2. Files changed

**New:**
- `app/Enums/OrderStatus.php` — backed enum for `leasyback_orders.order_status`, with `values()` and `activeValues()`.
- `app/Enums/VehicleOwnerType.php` — backed enum for `vehicles.vehicle_belongs` (`B2C`/`B2B`), with `values()`.
- `database/migrations/2026_08_01_000003_add_vehicle_belongs_check_constraint_to_vehicles_table.php` — Postgres-only CHECK constraint, mirrors the existing `users_user_type_check` pattern exactly.
- `database/factories/VehicleFactory.php`, `WorkshopFactory.php`, `VehicleDocumentFactory.php`, `VehicleReportDocumentFactory.php`, `LeasybackOrderFactory.php`.
- `tests/Feature/Policies/WorkshopPolicyTest.php`, `VehicleDocumentPolicyTest.php`, `VehicleReportDocumentPolicyTest.php`.
- `tests/Feature/Api/Admin/AdminOrdersTest.php`.

**Modified:**
- `app/Modules/UserProfile/Admin/Http/Controllers/AdminController.php` — `orders()` now delegates entirely to `AdminQueryService::orders()` instead of building raw SQL with a string-interpolated `order_status` filter. Nothing else in this controller changed (its other raw-SQL methods don't take unsanitized input into the query text — only `orders()` did).
- `app/Modules/UserProfile/Admin/Services/AdminQueryService.php` — `ORDER_STATUSES`/`ACTIVE_STATUSES` private consts replaced with calls to the new `OrderStatus` enum (removes a duplicated status list).
- `app/Modules/UserProfile/Vehicle/Models/Vehicle.php`, `VehicleDocument.php`, `VehicleReportDocument.php`, `app/Modules/UserProfile/Order/Models/LeasybackOrder.php`, `app/Models/Workshop.php` — added `HasFactory` (+ explicit `newFactory()` overrides on the four `App\Modules\...` models, required because their namespace doesn't match Laravel's default `Database\Factories\{Model}Factory` convention).

### Checkpoint 2

**New:**
- `app/Policies/ProfilePolicy.php` — see §3a for the exact rules.
- `database/factories/AddressFactory.php`, `ContactFactory.php`, `PhoneNumberFactory.php`, `LeasybackUserProfileFactory.php`, `UserPreferenceFactory.php`.
- `tests/Feature/Api/ProfileControllerTest.php` — 11 tests covering all 5 endpoints.
- `tests/Feature/Policies/ProfilePolicyTest.php` — 3 tests for the two view-abilities (no live route exercises them yet) and the create/update admin-deny rule.

**Modified:**
- `app/Modules/UserProfile/Profile/Http/Controllers/ProfileController.php` — fully rewritten. Every method now delegates to `ProfileService` (already existed, already did the correct ownership-scoped queries, was simply never called) instead of building ad hoc `Address`/`Contact`/`PhoneNumber` Eloquent queries inline. `show()` now calls `ProfileService::findForUser()` instead of duplicating that query. No controller method is longer than 3 lines of actual logic.
- `app/Modules/UserProfile/Profile/Http/Requests/AddressContactRequest.php`, `PreferencesRequest.php` — `authorize()` now calls the new `ProfilePolicy` abilities (`createProfile`/`updateProfile`, `createPreferences`/`updatePreferences`) instead of just checking `$this->user() !== null`.
- `app/Providers/AuthServiceProvider.php` — registered `ProfilePolicy` for both `LeasybackUserProfile::class` and `UserPreference::class`.
- `app/Modules/UserProfile/Profile/Models/Address.php`, `Contact.php`, `PhoneNumber.php`, `LeasybackUserProfile.php`, `UserPreference.php` — added `HasFactory` + explicit `newFactory()` overrides (same reason as Checkpoint 1's models).

### Checkpoint 3

**New (backend):**
- `app/Http/Controllers/Settings/AddressController.php`, `PreferencesController.php` — thin, session-authenticated (`auth`/`active` middleware, same as the existing `Settings\ProfileController`/`PasswordController`), delegate entirely to `ProfileService`.
- `app/Http/Controllers/Settings/Concerns/HandlesServiceValidationErrors.php` — small trait shared by both new controllers; see decisions below for why it exists.
- `routes/settings.php` — added `address.edit`/`address.store`/`address.update` and `preferences.edit`/`preferences.store`/`preferences.update`, following the existing flat-name convention (`profile.edit`, `password.edit`).

**New (frontend):**
- `resources/js/components/ui/select/*` (`Select.vue`, `SelectTrigger.vue`, `SelectContent.vue`, `SelectItem.vue`, `SelectItemText.vue`, `SelectValue.vue`, `SelectGroup.vue`, `SelectLabel.vue`, `SelectScrollUpButton.vue`, `SelectScrollDownButton.vue`, `index.ts`) — headless `reka-ui`-based dropdown primitives, styled to match the existing `ui/button`/`ui/checkbox`/`ui/dropdown-menu` conventions exactly. Not a native `<select>` anywhere.
- `resources/js/components/form/SelectField.vue` — the actual reusable component pages consume: `modelValue` + `options: {label,value}[]` + `placeholder`, emits `update:modelValue`, pairs with `FormField` the same way `Input`/`PasswordInput` do.
- `resources/js/components/form/PhoneNumberFieldset.vue` — reusable repeatable phone-number editor (add/remove rows, prefix dropdown + number input), not tied to any one page.
- `resources/js/components/settings/SettingsCard.vue` — reusable read/edit-toggle card built on the existing `ui/card/*` primitives; both new pages use it.
- `resources/js/pages/settings/Address.vue`, `resources/js/pages/settings/Preferences.vue`.
- `resources/js/types/profile.ts` — shared TypeScript types (`AddressData`, `ContactData`, `PhoneNumberData`, `PreferencesData`, `UserProfileData`) matching `ProfileService`'s response shapes, imported by both pages instead of each redeclaring its own.
- `tests/Feature/Settings/AddressControllerTest.php`, `PreferencesControllerTest.php`.

**Modified:**
- `app/Modules/UserProfile/Profile/Services/ProfileService.php` — added `findPreferencesForUser(User $user): ?array` (new method, nothing existing changed behavior). See decisions below.
- `resources/js/layouts/settings/Layout.vue` — added "Address & contact" and "Preferences" entries to `sidebarNavItems`.

### Checkpoint 4

**New:**
- `app/Policies/VehiclePolicy.php` — `view`/`update` abilities, both delegating to `VehicleScopeService::findVehicleWithAccess`.
- `tests/Feature/Policies/VehiclePolicyTest.php`, `tests/Feature/Api/VehicleControllerTest.php`, `tests/Feature/Api/VehicleDocumentUploadTest.php`.

**Modified:**
- `app/Modules/UserProfile/Vehicle/Http/Controllers/VehicleController.php` — `findByOwner()` rewritten to resolve the vehicle through `VehicleScopeService::findVehicleWithAccess` (scoped to the authenticated user) instead of an unscoped `Vehicle::where(...)` query, then cross-checks the resolved vehicle's real owner against the requested `ownerId` (preserves the existing "id + owner must both match" contract without trusting `ownerId` for the actual access decision). `update()`/`assignProfile()` now load the vehicle unscoped and authorize via `$user->can('update', $vehicle)` instead of calling `VehicleScopeService` directly — identical resulting behavior, now routed through the formal Policy.
- `app/Modules/UserProfile/Vehicle/Http/Controllers/VehicleDocumentController.php` — upload size cap `max:20480` (20 MB) → `max:10240` (10 MB), matching the reference system's documented cap.
- `app/Providers/AuthServiceProvider.php` — registered `VehiclePolicy` for `Vehicle::class`.

### Checkpoint 5

**New (backend):**
- `app/Http/Controllers/VehicleController.php`, `VehicleDocumentController.php` — session-authenticated web controllers, `index`/`store`/`update` and `store`/`destroy` respectively.
- `app/Http/Controllers/Concerns/HandlesServiceValidationErrors.php` — relocated from `Settings/Concerns/` (was Settings-specific, now shared by the Vehicle web controllers too) and broadened to also catch plain `abort($status, $message)` (`HttpExceptionInterface`), not just `HttpResponseException` — see decisions.
- `app/Modules/UserProfile/Vehicle/Http/Requests/StoreVehicleRequest.php`, `UpdateVehicleRequest.php`.
- `routes/vehicles.php` — `dashboard` (moved here from `web.php`'s inline closure), `vehicles.store`, `vehicles.update`, `vehicles.documents.store`, `vehicles.documents.destroy`.
- `tests/Feature/VehicleDashboardControllerTest.php`, `VehicleDocumentControllerTest.php`.

**New (frontend):**
- `resources/js/lib/licensePlate.ts` — ported near-verbatim from the legacy frontend (pure, framework-agnostic validation/normalization functions).
- `resources/js/lib/vehicleBrands.ts` — ported vehicle brand list, adapted to export `SelectFieldOption[]` instead of the legacy's vee-validate-shaped options.
- `resources/js/lib/vehicleStatus.ts` — a focused label/badge-variant map keyed to the `OrderStatus` PHP enum's exact values (not a port of the legacy's much larger, deliberately-unmerged multi-palette `status.ts`).
- `resources/js/components/form/LicensePlateInput.vue` — reusable 3-segment plate input; public API is a single `modelValue` string, like any other field.
- `resources/js/components/ui/table/*` (`Table`, `TableHeader`, `TableBody`, `TableRow`, `TableHead`, `TableCell`, `TableEmpty`, `TableFooter`, `index.ts`) — ported from the legacy repo's shadcn-vue-style primitives; this repo had no table component at all before.
- `resources/js/components/ui/badge/Badge.vue` (+ `index.ts`, `cva`-based variants) — this repo had no badge component either; used for the order-status pills in the vehicle table.
- `resources/js/components/vehicle/AddVehicleModal.vue`, `UploadDocumentModal.vue`.
- `resources/js/types/vehicle.ts` — matches `VehicleService::listVehiclesWithOrders()`'s response shape, same convention as `types/profile.ts`.

**Modified:**
- `app/Modules/UserProfile/Vehicle/Services/VehicleService.php` — added `createVehicle()`, `updateVehicle()`, `uploadDocument()`, `deleteDocument()`, and the private `resolveOwnership()`/`fail()` helpers (extracted from the API controllers, not new business logic). Fixed `listVehiclesWithOrders()`'s report-document block (`s3_key` → `path`, hardcoded `s3` disk → `documents` disk) and added a `documents` key to its per-vehicle output.
- `app/Modules/UserProfile/Vehicle/Http/Controllers/VehicleController.php` — `store()`/`update()` now delegate to `VehicleService` + the new Form Requests; down to a few lines each.
- `app/Modules/UserProfile/Vehicle/Http/Controllers/VehicleDocumentController.php` — `upload()`/`destroy()` now delegate to `VehicleService::uploadDocument()`/`deleteDocument()`.
- `app/Http/Controllers/Settings/AddressController.php`, `PreferencesController.php` — updated `use` import for the relocated `HandlesServiceValidationErrors` trait; no behavior change.
- `routes/web.php` — the inline `dashboard` closure route removed (moved into `routes/vehicles.php` as a real controller action); `require __DIR__.'/vehicles.php';` added.
- `resources/js/pages/Dashboard.vue` — fully rewritten (was placeholder `PlaceholderPattern` scaffold content).
- `tests/Feature/Api/VehicleControllerTest.php` — added a `store()` regression test for the Checkpoint 5 service-extraction refactor.

## 3. Important decisions

- **`AdminController::orders()` fix = wire in `AdminQueryService`, not a fresh rewrite.** The plan explicitly calls this out (§4, Checkpoint 1) — the service already existed, already used Eloquent's query builder throughout (fully parameterized), and already allow-lists `order_status` before it reaches any query. The controller method is now three lines. `vehicles()`, `ordersByUser()`, `vehiclesByUser()`, `ordersByUserType()`, `vehiclesByUserType()` were **not** touched — they either already use `?` bindings for user-controlled values or (for the `*ByUserType` pair) are pre-existing stubs that just delegate to the now-fixed methods. Rewiring those too would have meant changing their response shape without being asked; left for whichever checkpoint actually builds the Admin listing UI.
- **New `OrderStatus`/`VehicleOwnerType` enums, not raw arrays.** Mirrors the existing `UserType` enum + `users_user_type_check` migration pattern already established in this codebase (see `database/migrations/2026_07_31_000002_...`). Same shape: enum → `values()` → Postgres CHECK constraint built from those values, so the constraint can't silently drift from the enum.
- **The "introduce the first concrete Policies" scope item was already satisfied before this checkpoint started.** `WorkshopPolicy`, `VehicleDocumentPolicy`, and `VehicleReportDocumentPolicy` were added in the prior session as part of the storage-architecture directive. This checkpoint's authorization work was therefore about **testing** those Policies (zero tests existed for any of them), not adding new ones — which matches this checkpoint's actual title, "Foundation Testing and Authorization."
- **Canonical vs. shim model classes matter for Policy type hints.** Several models live at `App\Modules\UserProfile\...\Models\X` with a thin re-export shim at `App\Models\X extends ...\X`. The three Policies type-hint the **shim** class (e.g. `App\Models\VehicleReportDocument`), matching how every real controller queries these models (`VehicleReportDocument::find()` via the shim import). One test (`VehicleReportDocumentPolicyTest::test_view_ability_requires_published_or_admin`) calls the Policy directly with no HTTP round-trip in between (no live route exercises the `view` ability yet), so it re-fetches through the shim class before asserting — otherwise PHP's type check rejects the canonical-class instance the factory produces. Every other new test goes through real HTTP routes, which already return shim instances naturally.
- **Factories for the four `App\Modules\...` models needed explicit `newFactory()` overrides.** Laravel's default factory-name resolver strips the app namespace and preserves the rest of the path (e.g. `App\Modules\UserProfile\Vehicle\Models\Vehicle` → `Database\Factories\Modules\UserProfile\Vehicle\Models\VehicleFactory`), not just the class basename. Discovered via a failing first test run; fixed by adding `protected static function newFactory()` to each affected model rather than moving/renaming factories.
- **Unauthorized vehicle-document access returns 404, not 403**, by existing design in `VehicleDocumentController` (deliberately doesn't reveal whether a vehicle/document exists to a caller without access). Tests assert `assertNotFound()` for the non-owner case there, and `assertForbidden()` everywhere else (Workshop, Admin routes), matching each controller's actual behavior rather than a single assumed convention.
- **Only the factories this checkpoint's tests actually need were added** (`Vehicle`, `Workshop`, `VehicleDocument`, `VehicleReportDocument`, `LeasybackOrder`) — not every domain model, per this checkpoint's explicit instruction to narrow that item from the master plan's broader "every model" framing.

### Checkpoint 2 decisions

- **The real IDOR fix is `ProfileService`'s ownership-scoped query, not the Policy.** `updateAddressContact`/`updatePreferences` accept a client-supplied `address_id`/`contact_id`/`preference_id` — there's no way to authorize "is this the caller's own resource" via a Policy instance check without first loading the record, and loading it correctly (scoped to `user_id`) *is* the fix. `ProfileService::updateAddressContact` joins `user_profiles` → `contacts` and requires `up.user_id = $user->id` before touching anything, `lockForUpdate()`'d, returning a clean 404 (not a silent no-op, not a 403) when the ids don't resolve to the caller's own profile. `ProfilePolicy` handles the orthogonal question of *who may create/update a profile at all* (see below) — the two layers are complementary, not redundant.
- **`ProfilePolicy` denies Admin on `create`/`update`, allows Admin on `view`**, per `docs/B2C_ADMIN_PERMISSION_MATRIX.md`'s explicit UserProfile row: "Admin has no legitimate use case to edit a customer's personal contact info directly." Since `createProfile`/`updateProfile`/`createPreferences`/`updatePreferences` don't need a loaded model instance to decide (the rule is purely user-type-based — instance-level ownership is `ProfileService`'s job, see above), they're authorized directly in each Form Request's `authorize()`, keeping the controller itself free of any explicit `$this->authorize()` calls.
- **One Policy class, two registered models, distinct ability names.** `LeasybackUserProfile` (profile/address/contact/phone bundle) and `UserPreference` are different Eloquent models but conceptually one "self-service profile" domain per this checkpoint's instructions, so both are registered against the same `ProfilePolicy` in `AuthServiceProvider`. Since one PHP method can't type-hint two different classes, the profile abilities are named `viewProfile`/`createProfile`/`updateProfile` and the preference ones `viewPreferences`/`createPreferences`/`updatePreferences`, rather than reusing `view`/`create`/`update` for both.
- **`viewProfile`/`viewPreferences` have no live route yet.** `ProfileController::show()` always resolves `$request->user()`'s own profile — there's no client-suppliable identifier, so there's no IDOR surface to authorize against today. The abilities exist and are tested directly (`ProfilePolicyTest`) because the permission matrix documents Admin-view-any as a legitimate future capability; wiring a real admin-view-by-id endpoint is not in this checkpoint's scope.
- **Invalid-identifier / validation-failure responses verified clean.** A malformed `address_id`/`contact_id` (not a UUID) is rejected by `AddressContactRequest`'s validation rules before the controller ever runs (422, standard Laravel validation-error shape). A well-formed but nonexistent or not-yours id reaches `ProfileService`, which throws `HttpResponseException` wrapping a plain `{"error": "..."}` JSON body — Laravel's exception handler renders `HttpResponseException` as-is regardless of `APP_DEBUG`, so no `exception`/`file`/`line`/`trace` keys leak even in this repo's `APP_DEBUG=true` default. Both cases have an explicit regression test asserting the response body has none of those debug keys.

### Checkpoint 3 decisions

- **Two separate auth mechanisms exist side by side, on purpose.** The `/userprofile/*` API routes (Checkpoint 2) are Sanctum-bearer-token-only — `bootstrap/app.php` has an explicit comment that cookie/CSRF middleware must never be applied to them, since the legacy `leasyback_web` SPA authenticates that way. The new Inertia pages instead use **new, separate, session-authenticated web controllers** (`Settings\AddressController`/`PreferencesController`, under the existing `auth`+`active` web middleware group) that call `ProfileService` directly — not the Sanctum API controller, and the Inertia frontend never touches a bearer token. Both entry points share the same `ProfileService` and the same `AddressContactRequest`/`PreferencesRequest`, so the IDOR fix and validation rules can't drift between them.
- **`ProfileService` reports domain errors (409 already-exists, 404 not-found) via `HttpResponseException` with a bare `{"error": "..."}` body** — correct for the JSON API, but Inertia doesn't know how to render a bare JSON body mid-visit. `HandlesServiceValidationErrors` (a small trait, not a rewrite of `ProfileService`) catches it in the two new web controllers and redirects back with the same message flashed into a named error-bag field (`address`/`preferences`), which Inertia's `useForm().errors` picks up exactly like a normal validation error. `ProfileService` itself is untouched by this — the translation happens only at the web-controller boundary.
- **Added `ProfileService::findPreferencesForUser()` instead of reusing `findForUser()`.** `findForUser()` returns `null` for the *entire* bundle whenever no `user_profiles` row exists — but `user_preferences` is a fully independent table a user could populate without ever creating an address/contact profile. Reusing `findForUser()` for the Preferences page would have silently hidden real preferences data for that (real, schema-permitted) case. Caught during design, not during a failing test — `test_preferences_are_visible_even_without_an_address_profile` locks it in as a regression test now that the fix exists.
- **Address & contact merged into one page/one card, not two**, unlike the legacy `leasyback_web`'s separate `AccountDetail` (address + identity) and `ContactPerson` (a distinct secondary contact person) components. The current Laravel schema only has one `contacts` row per profile (`user_profiles.contact_id`, 1:1) — there's no second "contact person" concept to port. Presenting them as one form matches the actual backend resource (`POST/PUT /userprofile/address-contact` is already one combined endpoint) rather than inventing a two-contact model that doesn't exist.
- **The reusable dropdown is genuinely non-native, per the explicit instruction.** `ui/select/*` wraps `reka-ui`'s `SelectRoot`/`SelectTrigger`/`SelectContent`/`SelectItem` (the same headless-UI foundation already used for `Button`/`Checkbox`/`Label`/`DropdownMenu` in this repo), ported from the legacy `leasyback_web` repo's own `ui/select/` (which already existed there, unused by its forms) and re-styled to match this repo's Tailwind tokens. The one pre-existing dropdown-like thing in this repo, `Register.vue`'s plain `<select>` with a manual `ChevronDown` overlay, was left as-is (out of scope, not part of this checkpoint) rather than retrofitted.
- **`SelectField.vue` is the actual reusable unit, not the five `ui/select/*` primitives individually.** Composing `Select`+`SelectTrigger`+`SelectValue`+`SelectContent`+`SelectItem` by hand in every page would defeat the point of "reusable" — `SelectField` takes a flat `modelValue`/`options`/`placeholder` API (matching every other form field in this codebase) and is what `Address.vue`, `Preferences.vue`, and `PhoneNumberFieldset.vue` actually import. It's reused **four times** across salutation, country, language, and timezone (plus the phone-prefix field), not built once and used once.
- **No live browser verification was possible.** `mcp__claude-in-chrome` reported the extension not connected in this environment. Correctness was checked instead via `npm run build` (Vue/TS compiles), `npm run lint` (eslint, clean), and Feature tests that assert exact Inertia prop values (`assertInertia(...->where(...))`) over real HTTP requests through the full middleware/controller/service stack — this proves the data flow end-to-end but does **not** confirm the dropdown opens/closes correctly, keyboard nav works, or the visual layout is right. Whoever reviews this should click through `/settings/address` and `/settings/preferences` at least once before considering Checkpoint 3 fully done.
- **`vue-tsc --noEmit` doesn't run cleanly in this repo** (pre-existing `tsconfig.json` issue — its `"types"` array includes a relative path, `./resources/js/types`, which isn't a valid `types` entry for a direct CLI invocation, independent of anything in this checkpoint). Confirmed pre-existing by the fact that it fails identically on `vue/tsx`, one of the untouched default entries. Not fixed (out of scope, config surface not directory this checkpoint owns) — `npm run build`/`npm run lint` remain the real gates, as they're what the project's own scripts define.

### Checkpoint 4 decisions

- **`findByOwner`'s fix ignores the client-supplied `ownerId` for the actual access decision, but still checks it.** The correct authorization question is "does the authenticated user have access to *this* vehicle at all" — answered entirely by `VehicleScopeService::findVehicleWithAccess($vehicleId, $user)`, which never looks at `$ownerId`. Once that resolves a vehicle, the code cross-checks the vehicle's *real* owner against the requested `$ownerId` purely to preserve the endpoint's existing "id + owner must both match" response contract (a caller who gets the pairing wrong still gets 404, same as before) — not as a security boundary. This avoids two smaller mistakes: trusting `ownerId` as an access grant (the original bug) or dropping it from the response contract entirely (an unrelated, unrequested behavior change).
- **`update()`/`assignProfile()` were refactored to use the Policy even though they weren't vulnerable.** They already called `VehicleScopeService::findVehicleWithAccess` directly and were already correctly scoped — this wasn't a fix, it's what "formalize `VehiclePolicy` on top of the already-good `VehicleScopeService`" (the plan's literal Checkpoint 4 wording) means in practice: make the Policy the actual mechanism controllers use, not just a class that exists in isolation. The 404-not-403 response shape was preserved exactly (`! $vehicle || ! $user->can('update', $vehicle)` still returns the identical message either way) so this is a pure internal refactor, not a behavior change.
- **The Vehicle-document Policy regression-test item needed no new code.** `VehicleDocumentPolicyTest` (added in Checkpoint 1) already proves owner/non-owner/admin access on list/view/delete. Re-verified by re-reading it and confirming it still passes; no duplicate test was added. Consistent with how Checkpoint 1 handled the already-satisfied "introduce Policies" item — check what already exists before assuming a plan bullet means new code.
- **20 MB → 10 MB is a real spec-compliance fix, not a guess.** `docs/B2C_ADMIN_MIGRATION_AUDIT.md` explicitly documents the reference (Rust) system's cap as 10 MB and frames Checkpoint 4's job as verifying "Laravel enforces the same cap" — finding it set to 20 MB and leaving it as "verified, but wrong" would defeat the point of the checkpoint. The mime allow-list itself (`pdf,jpg,jpeg,png`) already matched and needed no change; Laravel's `mimes` rule also sniffs actual file content rather than trusting the extension/declared content-type alone, which is stricter than the documented reference behavior — noted in the controller comment, not changed (already an improvement, nothing to fix).
- **Vehicle creation (`store()`) and the two "*ByUserType"/`dashboard`/`listByOwner` methods were left untouched.** `store()`'s Admin/Firmenkunde/Privatkunde branching decides who the *new* vehicle's owner becomes — that's business logic, not an access-control gate, and isn't flagged anywhere as vulnerable. `listByOwner()`/`dashboard()` already have correct (if inline, duplicated) ownership checks and aren't broken; consolidating them through `VehicleScopeService::resolveOwnerId()` would be a reasonable future cleanup but isn't what this checkpoint's four explicit bullet points asked for, so it was left alone to keep the diff reviewable.

### Checkpoint 5 decisions

- **`store()`/`update()`/document upload/delete were extracted into `VehicleService`, not just duplicated into the new web controller.** Checkpoint 4 already extracted `findByOwner`'s scoping logic into a Policy; this checkpoint needed the *mutation* logic (owner resolution, audit logging, document storage) available from a second, non-Sanctum entry point. Rather than copy-pasting the Sanctum API controller's bodies into the new web controller (which would let the two drift out of sync over time, the exact anti-pattern Checkpoints 2–3 fixed for Profile), both the API and web controllers now call the same `VehicleService` methods. The API controllers shrank as a side effect — a deliberate, not incidental, part of "keep controllers thin."
- **Found `VehicleService::listVehiclesWithOrders()` was stale (referenced a column Checkpoint 0 renamed, hardcoded the wrong disk) because it was still unused code.** Since this checkpoint is the method's first real caller, fixing those two bugs before activating it was necessary, not optional — shipping a "wire in the existing service" change while knowingly leaving it broken would defeat the purpose. Deliberately did **not** touch the Sanctum API's own `dashboard()` method (which has its own, still-separate, still-working inline implementation) to wire it through the now-fixed service too: `listVehiclesWithOrders()`'s response shape genuinely differs (missing `b2b_id`/`b2c_user_id`/`assigned_profile_id`, differently-shaped `status_updates`/`order_confirmations`) from what `dashboard()` currently returns, and that's a live endpoint the legacy `leasyback_web` SPA calls — changing its response shape wasn't asked for and wasn't risked. `dashboard()` and `listVehiclesWithOrders()` remain two independent implementations of a similar query on purpose, for now; consolidating them is listed under deferred work.
- **The "unbekannt" checkboxes are ported as UX, not as a data-integrity workaround.** The legacy Add-Vehicle modal sent a fake "today" placeholder for `leasing_end_date` (and `first_registration_date`, which isn't even shown as a field in this rebuild) because *its* backend required those fields non-null. This Laravel backend's `StoreVehicleRequest`/`UpdateVehicleRequest` already validate both as `nullable`, so the new modal sends real `null` when "Ich weiß es nicht" is checked — preserving the useful UX pattern (an explicit, honest "I don't know" affordance) without importing the data-quality problem (a fabricated leasing-end date that looks real) that the workaround existed to paper over in the old system.
- **The duplicate-document-type "replace" flow moved entirely to the client.** The legacy backend rejected a same-`document_type` re-upload with an "already exists" error, and the old UI's replace-flow was built around catching that error. This backend's `VehicleDocumentController::upload()` has never done that check — it always creates a new row. Rather than add a new backend rejection (a real behavior change to an existing, tested, working endpoint, and out of this checkpoint's frontend scope) or drop the UX entirely, `UploadDocumentModal.vue` compares the selected type against the vehicle's already-loaded `documents` prop *before* submitting, and shows the identical inline warning/Replace/Cancel UI the legacy version did — same user-facing behavior, different mechanism.
- **No live browser verification, again.** Same `mcp__claude-in-chrome` "extension not connected" result as Checkpoint 3, re-checked at the start of this checkpoint's frontend work in case it had become available — it hadn't. The two new modals in particular (drag-and-drop, the reka-ui Select's keyboard/pointer interactions, the plate input's per-segment live validation) are exactly the kind of thing only a real browser check catches; flagged again under deferred work rather than silently assumed correct.
- **Row-level order-timeline expansion was not ported.** The legacy `VehicleTable`/`VehicleRow` had an expandable per-vehicle detail panel showing the full order timeline, inspection appointment, and driver contact info. That's genuinely order-domain UI (it's built from `order.request_payload`/`status_updates`, not vehicle fields), and the plan's own checkpoint sequence puts order-domain work in Checkpoints 6–7 (Order backend/frontend) — building a real version of it now, ahead of the Order backend checkpoint's own hardening work (the plan flags `TransitionOrderStatus` and an Offer BOLA fix as still-open there), would mean building UI against order data/endpoints that haven't been through the same audit-and-fix pass the Vehicle domain just got. The table still shows each vehicle's current status via the badge; the deep-dive panel is deferred, not silently dropped.

## 3a. Policy rules (`ProfilePolicy`)

| Ability | Model | Privatkunde / Firmenkunde / Werkstatt | Admin |
|---|---|---|---|
| `viewProfile` | `LeasybackUserProfile` | ✅ own only (`profile->user_id === user->id`) | ✅ any |
| `createProfile` | `LeasybackUserProfile` (class-level) | ✅ | ❌ |
| `updateProfile` | `LeasybackUserProfile` (class-level) | ✅ | ❌ |
| `viewPreferences` | `UserPreference` | ✅ own only (`preference->user_id === user->id`) | ✅ any |
| `createPreferences` | `UserPreference` (class-level) | ✅ | ❌ |
| `updatePreferences` | `UserPreference` (class-level) | ✅ | ❌ |

Directly mirrors `docs/B2C_ADMIN_PERMISSION_MATRIX.md`'s "UserProfile (own address / contact / preferences)" table. Cross-user access to a *specific* address/contact/preference id (not just "can this user type act on the resource class at all") is blocked one layer down, inside `ProfileService`'s ownership-scoped queries — see the Checkpoint 2 decisions above.

## 3b. Policy rules (`VehiclePolicy`)

| Ability | Model | Owner (B2C user / B2B company member) | Other authenticated user | Admin |
|---|---|---|---|---|
| `view` | `Vehicle` | ✅ | ❌ | ✅ any |
| `update` | `Vehicle` | ✅ | ❌ | ✅ any |

Both abilities delegate to `VehicleScopeService::findVehicleWithAccess`, the same scoping logic `VehicleDocumentPolicy` and `VehicleReportDocumentPolicy` already build on — one source of truth for "does this user own this vehicle" across the whole Vehicle domain.

## 4. Tests run and results

**Checkpoint 1:**
- New tests only: `php artisan test --compact tests/Feature/Policies tests/Feature/Api/Admin` → **18 passed (37 assertions)**.
- Migration `2026_08_01_000003_add_vehicle_belongs_check_constraint_to_vehicles_table` run against the dev Postgres DB — applied cleanly (232ms). Not exercised by the test suite itself (tests run on sqlite `:memory:`, where the migration is a deliberate no-op, same as the existing `users_user_type_check` constraint).

**Checkpoint 2:**
- New tests only: `php artisan test --compact tests/Feature/Api/ProfileControllerTest.php tests/Feature/Policies/ProfilePolicyTest.php` → **15 passed (55 assertions)**.

**Both checkpoints, run at the end of Checkpoint 2:**
- `php artisan test` (full suite) → **137 passed, 4 skipped, 0 failures** (349 assertions).
- `vendor/bin/pint --dirty --test` → **passed** (no diffs pending on any file this session touched).
- `npm run build` → **succeeded** (`vite build`, 38.01s) — expected to be a no-op check since no frontend files were touched in either checkpoint; confirms the existing frontend still builds cleanly.

**Checkpoint 3:**
- New tests only: `php artisan test --compact tests/Feature/Settings` → **20 passed (131 assertions)**.

**All three checkpoints, run at the end of Checkpoint 3:**
- `php artisan test` (full suite) → **148 passed, 4 skipped, 0 failures** (446 assertions).
- `vendor/bin/pint --dirty --test` → **passed**.
- `npm run lint` (eslint --fix) → **clean**, no errors/warnings.
- `npm run build` → **succeeded** (`vite build`, ~8s) — both new pages (`Address-*.js`, `Preferences-*.js`) and the new `SettingsCard`/`Checkbox` chunks are present in the manifest.
- **Not run**: any browser-based visual/interaction check (see the Checkpoint 3 decisions note above — the Chrome extension wasn't connected in this environment).

**Checkpoint 4:**
- New tests only: `php artisan test --compact tests/Feature/Policies/VehiclePolicyTest.php tests/Feature/Api/VehicleControllerTest.php tests/Feature/Api/VehicleDocumentUploadTest.php` → **13 passed (27 assertions)**.

**All four checkpoints, run at the end of Checkpoint 4:**
- `php artisan test` (full suite) → **161 passed, 4 skipped, 0 failures** (473 assertions).
- `vendor/bin/pint --dirty --test` → **passed**.

**Checkpoint 5:**
- New tests only: `php artisan test --compact tests/Feature/VehicleDashboardControllerTest.php tests/Feature/VehicleDocumentControllerTest.php` → **9 passed (33 assertions)**.

**All five checkpoints, run at the end of Checkpoint 5:**
- `php artisan test` (full suite) → **172 passed, 4 skipped, 0 failures** (522 assertions).
- `vendor/bin/pint --dirty --test` → **passed**.
- `npm run lint` (eslint --fix) → **clean**, no errors/warnings.
- `npm run build` → **succeeded** (`vite build`, ~10s) — `Dashboard-*.js` grew from a ~2 kB placeholder chunk to ~24 kB of real page content; new `SelectField`, `DialogTitle`, `index` (table) chunks present.
- **Not run**: any browser-based visual/interaction check — see decisions above.

## 5. Deferred work

Everything the plan defers past Checkpoint 5, still untouched:
- Offer `customerSelect` BOLA — zero ownership check today, confirmed again in an earlier survey this session, not fixed (Checkpoint 6).
- B2B `showByUser` IDOR and `update` role-acceptance bug (Checkpoint 9).
- Centralizing the ~20 scattered `user_type === 'Admin'` checks (including `AdminController::ensureAdmin()`) behind one Policy/Gate mechanism (Checkpoint 8).
- Dead schema (`user_workshops`, `vehicle_report_document_logs`) — still unresolved.
- Admin-view-any-profile endpoint — `ProfilePolicy::viewProfile`/`viewPreferences` already support it (Admin passes), but no route exists yet; build only if/when a real Admin "look up a customer's profile" feature is prioritized.
- **Live browser verification of the Checkpoint 3 and 5 pages** — functionally proven via Feature tests + build/lint, but never actually clicked through. Do this before considering either checkpoint fully signed off; prioritize the two Vehicle modals (drag-and-drop, plate input, Select interactivity) since they're the most interaction-heavy new UI so far.
- A map picker for the address (the legacy `AccountDetail.vue` had one, draggable pin + reverse geocoding) — not ported; `longitude`/`latitude` are silently hardcoded to `0` by `ProfileService::addressValues()` regardless. Add a map picker only if product actually wants coordinates captured.
- Avatar/profile-image upload (`user_profiles.image_url` exists in the schema, `LeasybackUserProfile` model has the field) — no UI for it anywhere yet, B2C or Admin.
- `VehicleController::store()`'s Admin/Firmenkunde/Privatkunde ownership-resolution branching, and the duplicated inline versions of it in `listByOwner()`/`dashboard()` (API), could be consolidated through `VehicleScopeService::resolveOwnerId()` — a worthwhile cleanup, not done (not broken, not in either checkpoint's explicit scope).
- `Vehicle::create()` in `VehicleService::createVehicle()` doesn't set `vehicle_belongs` through the `VehicleOwnerType` enum added in Checkpoint 1 (still a raw `'B2B'`/`'B2C'` string) — harmless today since the DB CHECK constraint backstops it, but worth aligning whenever this method is next touched.
- The Sanctum API's `VehicleController::dashboard()` and `VehicleService::listVehiclesWithOrders()` remain two separate implementations of a similar query — see Checkpoint 5 decisions for why they weren't consolidated. Worth revisiting once there's a reason to change the API's response shape anyway (e.g. if `leasyback_web` is ever retired or its contract renegotiated).
- Row-level order-timeline expansion on the vehicle table (legacy's `VehicleRow`/`DdfExpanded`) — deferred to Checkpoint 6/7 (Order backend/frontend), see decisions above.
- Vehicle deletion — no delete-vehicle capability exists anywhere in this codebase or the legacy references (flagged as an open product question in the master plan, §13 item 10); the dashboard has no delete action either, matching that.

## 6. Current blockers

None functionally. One environment limitation carried over from Checkpoint 3: browser automation isn't available in this session, so none of the Inertia pages built in Checkpoints 3 or 5 have been visually/interactively verified.

## 7. Exact next checkpoint

**Checkpoint 6 — Order backend and status workflow**: build a `TransitionOrderStatus` action with the explicit transition table from `docs/B2C_ADMIN_STATUS_MATRIX.md` §1 (replacing any free-text status-override anti-pattern); fix the Offer `customerSelect` BOLA (zero ownership check today, reconfirmed during this session's earlier survey); move the TÜV SÜD webhook API key to config if it isn't already; add `OrderPolicy`/`OfferPolicy` with tests for every transition in the status matrix (valid and invalid attempts); add a role check (Admin-only) to `createStation`, which currently has none. Do not start Checkpoint 7 (Order/Offer frontend) until Checkpoint 6 is reviewed.

## 8. Handoff note for another Claude session

Checkpoints 1 through 5 are done and merged into the working tree (not committed to git unless you're told to). If you're picking this up cold:
- Read `docs/B2C_ADMIN_IMPLEMENTATION_PLAN.md` first for the full checkpoint sequence and domain boundaries — this progress file only tracks what's actually been done, not the plan itself.
- The `AdminQueryService`/`ProfileService` pattern (existing-but-unused safe implementation, wire it in rather than rewrite) has now repeated twice — check for a matching `*Service` class before writing new query logic from scratch in any future checkpoint.
- When adding a factory for any model under `App\Modules\...\Models\`, you must add an explicit `newFactory()` override on the model (see any model touched in Checkpoints 1–4 for the pattern) — the default factory-name convention does not find it otherwise.
- When writing a Policy test for a model that has an `App\Models` shim, always create/fetch through whichever class the Policy itself type-hints (check the Policy's `use` imports) — mixing canonical and shim instances across a raw (non-HTTP) `$user->can(...)` call either throws a PHP `TypeError` or, if the canonical class isn't registered in `AuthServiceProvider` at all, silently returns `false` with no error.
- If a Policy ability doesn't need a loaded model instance to decide (e.g. a class-level rule like "Admins can never do X"), authorize it directly in the Form Request's `authorize()` rather than adding an `$this->authorize()` call in the controller — keeps the controller thin, matches the pattern in `AddressContactRequest`/`PreferencesRequest`.
- **Sanctum API routes and Inertia web routes are two separate front doors onto the same domain.** Don't assume a Sanctum-protected `/userprofile/*`-style JSON endpoint is reachable from an Inertia page via a plain `fetch`/`axios` call — it isn't (no stateful-SPA cookie middleware is applied to those routes, by design, per the comment in `bootstrap/app.php`). Build a parallel thin web controller calling the same service instead, as `Settings\AddressController`/`PreferencesController` do.
- **A service method that returns `null` for a "not found" case may be hiding a real, independently-existing record** if it's actually joining across multiple tables and treating the *first* join's absence as "nothing exists" — check `findForUser()`'s doc comment on `findPreferencesForUser()` for the exact case this bit. Worth a quick look before reusing any existing `find*For*` method for a new page.
- For any new dropdown anywhere in the frontend, use `@/components/form/SelectField.vue` — don't reach for a native `<select>` or hand-roll another custom dropdown. It's genuinely generic (`modelValue`/`options`/`placeholder`).
- `vue-tsc --noEmit` doesn't run cleanly in this repo due to a pre-existing `tsconfig.json` quirk (unrelated to any checkpoint's changes) — rely on `npm run build` and `npm run lint` instead, they're what this project's own scripts define as the real gates.
- No JS test runner (Vitest/Jest/etc.) is configured in this repo — frontend correctness is verified via `npm run build`, `npm run lint`, and backend Feature tests that assert exact Inertia prop values, not component-level unit tests.
- English-only naming was maintained for everything new (enums, factories, tests, Policy ability names, PHP classes) per this session's instruction; German only appears in realistic user-facing text (Vue template strings, factory data like `sprache => 'de'`, `salutation => 'Herr'`), which is fine.
- **Before assuming a plan bullet needs new code, check whether an earlier checkpoint already did it.** Checkpoint 4's "Vehicle-document Policy regression test" item turned out to already exist from Checkpoint 1 — re-read the relevant test file before writing a duplicate. This has now happened twice (Checkpoint 1's "introduce Policies" item too); the plan document was written before several checkpoints' work landed, so it can lag reality.
- When an authorization fix involves a client-supplied id that's *supposed* to correspond to something (like `findByOwner`'s `ownerId`), don't just drop the parameter to fix the IDOR — resolve access from the authenticated user first (ignore the untrusted value for the actual decision), then use the client-supplied value only to preserve the endpoint's existing response contract if that's cheap to do. See `VehicleController::findByOwner` for the exact pattern.
- If a plan item says "verify X is enforced," actually check the value against the documented spec (`docs/B2C_ADMIN_MIGRATION_AUDIT.md` in this case) rather than only checking "does a validation rule exist" — the vehicle-document upload cap was present but wrong (20 MB vs. the documented 10 MB), which a shallower check would have missed.
- **Every "*Controller* frontend" checkpoint so far has needed the same shape of backend work first**: a Sanctum API entry point already exists and must stay untouched in its response contract, so a new session-authenticated web controller gets built alongside it, and any real business logic gets extracted into (or wired into an already-existing, unused) `*Service` class both controllers call. Expect this pattern again for Checkpoint 7 (Order/Offer frontend) — check for an `OrderService`/`OfferService` before assuming logic needs to move for the first time.
- **Before wiring in an existing-but-unused service method, check whether it's actually still correct** — don't assume "already correct, just needs activating" the way Checkpoints 1–3 could. `VehicleService::listVehiclesWithOrders()` had silently rotted (a renamed column, a hardcoded disk) exactly because nothing called it since the Checkpoint 0 storage refactor landed. If a candidate service method predates a later architectural change, diff its assumptions against what actually changed before trusting it.
- **A live, already-shipped consumer of an endpoint (here: the legacy `leasyback_web` SPA calling the Sanctum API) is a real constraint**, even when nothing in this repo tests it. Before reusing one method's logic to satisfy two different response-shape needs, check whether the existing shape is actually depended on elsewhere — when in doubt, keep two implementations rather than silently narrowing a live endpoint's output.
- `resources/js/components/ui/table/*` and `ui/badge/*` now exist — check there before building a new table or status-pill component in a future checkpoint (Admin's vehicle/order tables in Checkpoints 10–11 will need exactly this).
- `resources/js/lib/vehicleStatus.ts` only covers the `OrderStatus` enum values that exist today — if Checkpoint 6 changes what values `order_status` can hold (the plan's status-matrix work), update this map in the same pass, or the vehicle table will silently fall back to showing the raw status string for anything new.
