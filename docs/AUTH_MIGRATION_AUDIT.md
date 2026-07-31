# AUTH_LEASY → LARAVEL MIGRATION AUDIT

**Scope:** `auth_leasy` (Rust/Actix, source of truth) vs. `leasyback-backend` (Laravel, generated replacement)
**Method:** Full read of every Rust source file (10 files, ~600 LOC) and every Laravel file relevant to the auth surface (controllers, form requests, models, migrations, mail, config, routes, tests). Read-only — no code modified.
**Verdict up front:** **NOT production
<!-- @import "[TOC]" {cmd="toc" depthFrom=1 depthTo=6 orderedList=false} -->

<!-- @import "[TOC]" {cmd="toc" depthFrom=1 depthTo=6 orderedList=false} -->
-ready as a drop-in replacement.** The Laravel app is a superset rewrite that changes the authentication mechanism entirely (JWT → Sanctum opaque tokens), changes the primary key type (UUID → auto-increment integer), and silently changes the password hashing algorithm's effective behavior for already-hashed data. Several of these are "improvements," but the brief at the time was behavioral parity — every one of them was flagged as a difference regardless of whether it was desirable.

> **Superseded framing:** This document was written under a strict 100%-parity objective. That objective has since been replaced — see `docs/AUTH_PRODUCTION_IMPLEMENTATION_PLAN.md §0` for the authoritative classification of every finding below (legitimate requirement / security improvement / Rust bug to discard / implementation detail / product decision required). `auth_leasy` is a demo/reference implementation, not a production target; do not read the findings below as a "must replicate" checklist. They remain valid as a **factual diff** — what changed and where — just not as a verdict on what Laravel *should* do.

---

## STEP 1 — RUST INVENTORY (ground truth)

### Files
`main.rs`, `app_state.rs`, `routes.rs`, `leasy_error.rs`, `auth/mod.rs`, `auth/handlers.rs`, `auth/middleware.rs`, `auth/password_change.rs`, `email/mod.rs`, `email/reg_email.rs`, `templates/registration_welcome.html`, `db_script/create_user_1.sql`, `db_script/fix_user_type_admin_2.sql`.

### Routes (actix, mounted at `/auth`, no `/api` prefix, port 3051)
| Method | Path | Handler | Auth |
|---|---|---|---|
| POST | `/auth/register` | `handlers::register` | none |
| POST | `/auth/login` | `handlers::login` | none |
| POST | `/auth/changepassword` | `password_change::change_password` | manual Bearer + `extract_jwt` |

There is **no route requiring `AuthenticatedUser`** (the `FromRequest` extractor at `middleware.rs:93-127`) — it, and its backing `verify_jwt()` function, are **dead code**, never wired to any handler. The only real auth-gate in the whole service is the hand-rolled header parsing + `extract_jwt()` call inside `change_password`.

### DTOs
- `RegisterRequest { user_email: String, user_type: String, password: String }` — **no `name` field exists**.
- `LoginRequest { user_email: String, password: String }`
- `ChangePasswordRequest { current_password: String, new_password: String }`
- `ApiResponse<T> { ok: bool, data: Option<T>, message: Option<String> }` — used **only on success**. Error responses do **not** use this envelope (see Step 9).
- `Claims { user_id: String, email: String, user_type: String, exp: usize }`

### JWT logic (`auth/middleware.rs`)
- Library: `jsonwebtoken` 9.3.1, algorithm **HS256 (default)**.
- Secret: `SECRET` env var, read fresh on every call (`std::env::var("SECRET")`), raw bytes as HMAC key.
- Expiry: `Utc::now() + 24 hours`, stored as `exp` (unix seconds).
- `generate_jwt(user_id: Uuid, email, user_type)` → signs `Claims`.
- `extract_jwt(token)` — the function actually used by `/changepassword`: requires claims `["exp","user_id","email","user_type"]` present, decodes, **also manually re-checks `exp < now`** (belt-and-braces), parses `user_id` as UUID. On any failure returns `ApiError::BadRequest("Token expired")` or `ApiError::BadRequest("Token error")` — **not 401**, **400** in both cases.
- `verify_jwt(token)` — dead code, only reachable via the unused `AuthenticatedUser` extractor. HS256 validation only, generic `ApiError::Unauthorized` on failure.
- Bearer parsing: manual `Authorization` header, `starts_with("Bearer ")`, no case-insensitive scheme check, no `Bearer` with different casing handled.

### Password logic (`auth/handlers.rs`)
- `hash_password`: `argon2` crate, `Argon2::default()` (argon2id, RFC9106 default params m=19456,t=2,p=1), random salt via `OsRng`, output is a full PHC string (`$argon2id$v=19$...`).
- `verify_password(password, hash) -> Result<bool, ApiError>`:
  ```rust
  match config.verify_password(...) {
      Ok(_) => Ok(true),
      Err(_) => Err(ApiError::Argon2Error("Current password does not match"))
  }
  ```
  **This function never returns `Ok(false)`.** On a wrong password it returns `Err`, not `Ok(false)`.

### ⚠️ Critical hidden bug in Rust that Laravel MUST decide whether to replicate
Because `verify_password` never returns `Ok(false)`, the "if !password_ok" branches in both `login` and `change_password` are **unreachable dead code**:

- **Login**, `handlers.rs:117-119`:
  ```rust
  if !verify_password(&payload.password, &row.password_hash)? {
      return Err(ApiError::Unauthorized);
  }
  ```
  On a wrong password, `verify_password(...)?` propagates `Err(ApiError::Argon2Error(...))` immediately via `?` — the function returns **before** reaching the `if !...`. Real production behavior: **wrong password on login → HTTP 406 Not Acceptable, body `"Current password does not match"`** — not 401 Unauthorized. Only a **non-existent email** produces 401 Unauthorized (`"Unauthorized"` plain-text body). This means the two failure cases are trivially distinguishable by status code (401 vs 406) — an email-enumeration side channel that exists in the current production Rust service today.
- **Change password**, `password_change.rs:80-86`: identical pattern. Wrong current password → **406 `"Current password does not match"`**, not the 400 `"Incorrect current password"` the code appears to intend.

Any Laravel implementation that returns 401/422 with a clean "wrong password" message (which is exactly what it does — see Step 5) is **behaviorally different** from what Rust actually ships today, even though the Rust behavior looks like an unintentional bug. Flagging this for a product decision, not silently "fixing" it, is required by the audit brief.

### Validation (Rust)
- Register: `user_type` must be one of `["Privatkunde","Firmenkunde","Werksatatt","Admin"]` (hardcoded array, checked in the handler, **not** via a validation framework). **No email format check. No password length/complexity check at all** (empty-string passwords are hashed and stored). No `name` field exists to validate.
- Login: no validation beyond `Deserialize` (missing fields → 400 from Actix's JSON extractor, generic serde error body).
- Change password: manual checks — current/new non-empty, new length ≥ 8, new ≠ current. Order: empty-current → empty-new → new-too-short → new-equals-current → (JWT already parsed before these checks, at the very top).

### Database (`db_script/*.sql`)
```sql
CREATE TABLE users (
    user_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_email TEXT NOT NULL UNIQUE,
    user_type TEXT NOT NULL CHECK (user_type IN ('Privatkunde','Firmenkunde','Werksatatt','Admin')),
    password_hash TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email_unique ON users (LOWER(user_email));
```
- PK is a **UUID**, generated by Postgres (`gen_random_uuid()`), not the app.
- Email uniqueness is enforced **case-insensitively at the DB level** via a functional unique index on `LOWER(user_email)`, in addition to the plain `UNIQUE` on the raw column.
- `user_type` has a **DB-level CHECK constraint** — invalid values are rejected by Postgres even if application code is bypassed.
- Only 5 columns exist. No `name`, `phone`, `address`, `is_active`, `email_verified_at`, etc.
- `fix_user_type_admin_2.sql` shows the CHECK constraint was later widened to add `'Admin'` — confirming `Admin` is (and always was) a **valid, self-service-registerable** value through `/auth/register` in the current production system.

### Environment variables
| Var | Required? | Rust default if missing |
|---|---|---|
| `SEND_GRID` | **Required** — `.expect(...)`, process panics/fails to start without it | none |
| `FRONTEND_URL` | optional | `https://app.leasyback.com` |
| `DATABASE_URL` | **Required** — `.expect(...)` | none |
| `SECRET` | Required **only lazily**, read inside `generate_jwt`/`extract_jwt` at request time, not at startup | none — missing var → `ApiError::Internal` (500) per request, app still boots |

### Email sending (`email/reg_email.rs`)
- Direct HTTP call to `https://api.sendgrid.com/v3/mail/send` via `reqwest`, Bearer auth with `sendgrid_api_key`.
- Renders `templates/registration_welcome.html` via Askama (compile-time template), single placeholder `{{login_url}}` — **no user name in the template at all**.
- **Sends the welcome email TWICE on every registration**: once to the actual registrant, and a **second, hardcoded copy to `jupiterbarua@gmail.com`** (`reg_email.rs:93-119`) — apparently a leftover developer debug/monitoring BCC. This is undocumented, hardcoded, and not gated by any env var or flag.
- **Failure reporting bug**: the function only checks the HTTP status of the **second** (hardcoded) SendGrid call for success/failure. If the first email (to the real user) fails but the second (to the hardcoded address) succeeds, `send_registration_email` returns `Ok(())` and the failure is silently swallowed — the real user never gets their email and nothing is logged.
- Registration email failure of any kind is non-fatal to the register endpoint either way (`if let Err(error) = ... { eprintln!(...) }`) — user is still created and 200 is returned.

### Business rules extracted
1. Registration allows self-service `Admin` account creation with no restriction.
2. Email lookup at login is case-insensitive; uniqueness at register is enforced case-insensitively at the DB layer.
3. Wrong password produces a different HTTP status (406) than "user not found" (401) on both login and change-password — an enumeration oracle.
4. Password policy is asymmetric: **no rules on register**, but change-password requires ≥8 chars and difference from current.
5. Registration email is sent twice per registration (one hidden copy), and email failure never blocks registration.
6. JWT is stateless, 24h fixed expiry, no revocation/blacklist mechanism, no refresh tokens, no "logout" endpoint exists at all.
7. There is no `/me`, no `/logout`, no email verification, no password-reset/forgot-password flow anywhere in `auth_leasy`.
8. CORS is fully open (`allow_any_origin`), all common methods, `Authorization`/`Accept`/`Content-Type` headers allowed, no credentials support.

### External integrations / dependencies
`actix-web`, `actix-cors`, `sqlx` (Postgres), `argon2`, `jsonwebtoken`, `askama` (templates), `reqwest` (SendGrid HTTP), `dotenvy`, `env_logger`, `uuid`, `chrono`, `thiserror`, `anyhow`. No queue, no cache, no Redis, no session store — fully stateless per-request service.

---

## STEP 2 — LARAVEL INVENTORY (relevant to auth_leasy scope)

The Laravel app is dramatically larger in scope (DEKRA integration, vehicle/order/B2B/workshop/TIM modules, an Inertia+Vue dashboard with its own Breeze/Fortify-style session auth). Only the pieces that are the actual candidate replacement for `auth_leasy` are in-scope here:

- **Controller**: `app/Http/Controllers/Api/AuthController.php` — `register`, `login`, `changePassword`, plus **new** `logout`, `me`.
- **Requests**: `App\Http\Requests\Api\{RegisterRequest,LoginRequest,ChangePasswordRequest}`.
- **Model**: `App\Models\User` (Sanctum `HasApiTokens`, huge `$fillable` far beyond the 3 register fields, casts `password` as `'hashed'`, `user_type` as `UserType` enum, `is_active` boolean).
- **Enum**: `App\Enums\UserType` (`Privatkunde`, `Firmenkunde`, `Werkstatt`≡`'Werksatatt'`, `Admin`), with `registrableValues()` excluding `Admin`.
- **Mail**: `App\Mail\RegistrationWelcome` (queued Mailable) + `resources/views/emails/registration-welcome.blade.php`.
- **Routes**: `routes/api.php`, registered twice — once under `/api/*` (framework default) and once unprefixed under `/*` via a `then:` callback in `bootstrap/app.php` explicitly for backward compatibility with the existing frontend that calls `/auth/...` directly (mirrors Rust's unprefixed paths — this part was clearly done deliberately and correctly).
- **Migrations**: base `users` table + 4 follow-on migrations adding `user_type`, profile fields, `is_active`.
- **Auth mechanism**: **Laravel Sanctum** (`laravel/sanctum: ^4.3`) personal-access tokens — **not JWT**. No `jsonwebtoken`-equivalent package (`tymon/jwt-auth`, `php-open-source-saver/jwt-auth`) is installed at all.
- **There is a second, entirely unrelated auth stack** (`Http/Controllers/Auth/*` — `RegisteredUserController`, `AuthenticatedSessionController`, `PasswordResetLinkController`, `NewPasswordController`, `ConfirmablePasswordController`, `VerifyEmailController`) which is standard Laravel Breeze/Fortify scaffolding for the Inertia/Vue **web dashboard's own session-cookie login**. This is *not* related to `auth_leasy` and should not be confused with the API replacement — but its existence means there are now **two independent, parallel user-authentication systems** in one app sharing the same `users` table.
- **No tests** reference the `Api\AuthController` endpoints at all (`grep` for `Api|api.auth|/auth/register|/auth/login` across `tests/` returns zero matches). All existing auth tests (`AuthenticationTest`, `RegistrationTest`, `PasswordUpdateTest`, etc.) exercise the unrelated Breeze web-session controllers.

---

## STEP 3 & 4 — ROUTE / BUSINESS-LOGIC MAPPING

| # | Rust feature | Laravel equivalent | Status |
|---|---|---|---|
| 1 | `POST /auth/register` | `POST /auth/register` (and `/api/auth/register`) | **PARTIAL** — path matches, throttled (new), behavior differs (see below) |
| 2 | `POST /auth/login` | `POST /auth/login` | **PARTIAL** — path matches, behavior differs significantly |
| 3 | `POST /auth/changepassword` | `POST /auth/changepassword` | **PARTIAL** — path matches, auth mechanism entirely different |
| 4 | (none) | `POST /auth/logout` | **EXTRA** — not in Rust, harmless addition |
| 5 | (none) | `GET /auth/me` | **EXTRA** — not in Rust, harmless addition |
| 6 | Stateless JWT (HS256, `SECRET`, 24h) | Sanctum opaque DB-backed tokens | **INCORRECT** — different auth mechanism entirely, not interoperable |
| 7 | `AuthenticatedUser` extractor / `verify_jwt` | — | N/A — Rust dead code, nothing to port |
| 8 | Argon2id password hashing | `Hash::make()` default driver = **bcrypt** (no `config/hashing.php`, no `HASH_DRIVER` env) | **INCORRECT** — see Step 5 |
| 9 | UUID primary key | Auto-increment `bigint` `id` | **INCORRECT** — breaking type change |
| 10 | Case-insensitive unique email (DB-level, functional index) | Plain `unique()` on `email` (case-sensitivity DB-dependent) | **PARTIAL/INCORRECT** — see Step 6 |
| 11 | `user_type` CHECK constraint at DB | No DB constraint, enum validated only in `RegisterRequest` | **PARTIAL** — app-level only |
| 12 | Self-registerable `Admin` type | Blocked — `registrableValues()` excludes `Admin` | **CHANGED BEHAVIOR** (see Step 8) |
| 13 | No password rules on register | `min:8`, `max:128` enforced on register | **CHANGED BEHAVIOR** (stricter) |
| 14 | `email` format: none | `email:rfc,dns`, `max:255`, `unique:users,email` | **CHANGED BEHAVIOR** (stricter + new failure mode) |
| 15 | Register response envelope `{ok,data,message}` | Same on success | **COMPLETE** for the success path |
| 16 | Register error path: raw DB error text, 400, plain text | Structured 422 JSON w/ validation errors | **INCORRECT** (different status/shape, but see note below — Laravel's is objectively better) |
| 17 | SendGrid direct HTTP call, hardcoded API | Laravel `Mail::queue()`, generic mailer (`log` by default, no SendGrid transport configured/installed) | **MISSING/INCORRECT** — see Step 10 |
| 18 | Double-send (real user + hardcoded BCC) | Single send | **MISSING** (intentionally, arguably correct, but a behavior delta) |
| 19 | Login: 401 "Unauthorized" (plain text) for bad email; 406 "Current password does not match" for bad password | Login: 401 JSON `{ok:false,...,"Invalid credentials."}` for **both** cases | **INCORRECT** — status codes and body format both differ |
| 20 | Change-password: manual Bearer/JWT parse, ordered manual validation | Sanctum `auth:sanctum` middleware + FormRequest validation | **INCORRECT** — mechanism, ordering, and status codes all differ |
| 21 | Rate limiting: none | `throttle:5,1` (register/changepassword), `throttle:10,1` (login) | **EXTRA** — new safety feature not in Rust, but changes behavior (429s that didn't exist before) |
| 22 | CORS: `allow_any_origin`, all methods, max_age 3600 | Origin allow-list (`FRONTEND_URL` + localhost regex), `allowed_methods:['*']`, max_age 600 | **CHANGED BEHAVIOR** — stricter, could break existing integrations from other origins |
| 23 | `FRONTEND_URL` default `https://app.leasyback.com` | Same default in `config/app.php` | **COMPLETE** |
| 24 | `SEND_GRID` required at boot (panics if missing) | No equivalent required-at-boot check; Laravel just uses whatever `MAIL_MAILER` is configured (defaults to `log`) | **MISSING** startup guarantee |
| 25 | `DATABASE_URL` required at boot | Laravel uses discrete `DB_*` vars, no single `DATABASE_URL`, default connection `sqlite` | **CHANGED** — different env contract entirely |

---

## STEP 5 — AUTHENTICATION DEEP DIVE

### Login
Rust (`handlers.rs:104-128`):
```
SELECT ... WHERE LOWER(user_email) = LOWER($1)
no user found            -> 401, body "Unauthorized" (plain text)
user found, password check -> verify_password() bug means:
    wrong password -> 406 Not Acceptable, body "Current password does not match"
    correct password -> issue JWT, 200 JSON {ok, data:{token, user_type, user_id}, message}
```
Laravel (`AuthController::login`):
```
User::whereRaw('LOWER(email) = ?', [strtolower(...)])->first()
no user OR Hash::check fails -> 401 JSON {ok:false, data:null, message:"Invalid credentials."}
success -> Sanctum::createToken(...) -> 200 JSON {ok, data:{token, user_id, user_type}, message}
```
**Findings:**
- Status codes diverge on wrong password (406 vs 401 in current Rust prod; Laravel is internally consistent but doesn't match either Rust code path exactly).
- Body format diverges on every error path: Rust errors are **plain text**, Laravel errors are **structured JSON**. Any client parsing Rust error bodies as plain strings will break; any client expecting Laravel's JSON on error against the real Rust service is already broken today — worth clarifying with the frontend team which contract it actually consumes.
- Token materially different: Rust issues a **JWT** the client can decode client-side (contains `user_id`, `email`, `user_type`, `exp`); Laravel issues an **opaque Sanctum token** (format `{id}|{40-char-random}`) that carries no claims and cannot be decoded/inspected client-side. Any frontend logic that decodes the JWT payload (e.g. to read `user_type` without waiting for a server round trip, or to check expiry client-side) will break.
- Token lifetime: Rust JWT always expires in exactly 24h, enforced by both the library and a manual re-check. Sanctum's default token **never expires** unless `SANCTUM_TOKEN_EXPIRATION`/`config('sanctum.expiration')` is set — it defaults to `24 * 60` minutes (24h) per `config/sanctum.php:52`, so the *duration* happens to match, but the *mechanism* (DB-checked, revocable) is different from stateless JWT (unrevocable until natural expiry).
- Field order/naming: Rust login response `data` = `{token, user_type, user_id}`; Laravel = `{token, user_id, user_type}`. JSON key order is not normally significant, but confirm no client depends on positional array parsing.

### Register
- Rust: no email format validation, no password strength validation, duplicate email surfaces as a raw Postgres error string wrapped in `ApiError::DbError` → HTTP 400, e.g. `"Database Error: duplicate key value violates unique constraint \"idx_users_email_unique\""`. Leaks internal schema/constraint names.
- Laravel: `email:rfc,dns` (performs live DNS MX lookup on the domain — **this is a real production risk**: registration will now fail, or hang/timeout, for any domain without resolvable DNS at validation time, and adds external network dependency + latency to every registration attempt that didn't exist in Rust), `max:255`, `unique:users,email` pre-check → clean 422 `{ok:false, message:"Validation failed.", errors:{...}}`. Objectively better UX, but a different contract, and the `dns` rule specifically is a new hard external dependency with no equivalent safeguard in Rust.
- Rust allows registering as `Admin`; Laravel's `Rule::in(UserType::registrableValues())` explicitly excludes it — self-registration as Admin now returns 422 instead of succeeding. This is almost certainly a deliberate, good fix, but it is a **hard behavior break** if anything (ops scripts, other internal tooling) relies on the old self-registration-as-Admin path.
- Laravel requires (or defaults) a `name` field that doesn't exist in Rust's schema/DTO at all: `'name' => $validated['name'] ?? explode('@', $validated['user_email'])[0]`. Harmless synthesized default, but it's new derived data with no Rust equivalent to diff against.

### Change password
- Rust: manual Bearer parse → `extract_jwt` → **then** validates current/new non-empty, new length, new≠current → fetch user by UUID → verify current password (406 bug as above) → hash & update → 200 `{"message":"Password updated successfully"}` (note: **not** wrapped in the `{ok,data,message}` envelope used everywhere else — an inconsistency in Rust itself).
- Laravel: `auth:sanctum` middleware → `ChangePasswordRequest` validates (`required`, `different:current_password`, `min:8`) → wrong current password → 422 `{ok:false,...,"Current password is incorrect."}` → success → 200 `{ok:true, data:null, "Password updated successfully."}`.
- Validation **order** differs: Rust checks "is new password different from current" via a bare string comparison *before* ever touching the DB or verifying the current password is even correct; Laravel's `different:current_password` is a request-level rule so it's also checked before the controller runs, but Laravel's overall validation error response format (422 + field-level errors) is unconditionally different from Rust's flat 400 string messages.
- Missing/invalid Authorization header: Rust returns 400 `"Missing or invalid Authorization header"`; Laravel's `auth:sanctum` middleware returns its standard 401 `{"message":"Unauthenticated."}` — different status code (400 vs 401) and body shape.
- Rust's success body is a bare `{"message": ...}`, not `{ok,data,message}` — if any client special-cased this endpoint's response shape, note that Laravel's version is actually *more* consistent (uses the standard envelope), which is a deviation from Rust's literal (if accidental) inconsistency.

### JWT generation / verification / claims / algorithm / SECRET — N/A in Laravel
No JWT is generated or verified anywhere in the Laravel app for this flow. This is not a partial gap, it's a **complete architectural substitution**. If any other consumer of the ecosystem (mobile app, another microservice, a gateway) verifies the JWT independently using `SECRET` and HS256 (rather than calling back into `auth_leasy`), that consumer **will not work** against Laravel's tokens at all — there is nothing to verify with the shared secret because no JWT is issued.

### Password hashing / verification
- Rust: Argon2id, PHC string format (`$argon2id$v=19$m=...,t=...,p=...$<salt>$<hash>`).
- Laravel: `Hash::make()` with **no configured driver** → framework default **bcrypt** (`$2y$12$...`, `BCRYPT_ROUNDS=12` from `.env.example`). No `config/hashing.php` is published in this project and no `HASH_DRIVER` env var is set.
- **Consequence**: `password_verify()`/Laravel's bcrypt checker cannot validate an Argon2id PHC hash (different prefix, different verification routine) — if the users table (or its data) is ever migrated over from the live Postgres database, **100% of existing users' passwords will fail to verify** under Laravel until they reset their password. This is the single highest-risk finding in this audit for any real cutover that carries forward existing user data. (If Laravel is instead configured with `HASH_DRIVER=argon2id`, PHP's `password_verify` *does* support the PHC argon2id format compatibly with the Rust `argon2` crate's output — so this is fixable by config, but it is **not configured today**.)
- Rust's `verify_password` quirk (never returns `Ok(false)`, only `Ok(true)`/`Err`) has no Laravel equivalent — Laravel's `Hash::check` correctly returns `bool`. Functionally more correct, but see the 406-status finding above: this also means the distinguishable-by-status-code side channel present in Rust does not exist in Laravel.

### Authorization / role checking
- Rust: no role/authorization checks anywhere. `user_type` is stored and returned but never gates any endpoint.
- Laravel: same — `user_type` stored/returned, `User::isAdmin()`/`isType()` helper methods exist on the model but are not called from any of the 3 in-scope endpoints. Parity holds here (both are role-agnostic for these 3 endpoints), but note Laravel *has* the scaffolding (`EnsuresAdmin` trait under `Modules/UserProfile/Admin/Support/`) for other modules — out of scope for this audit but worth knowing it exists for once other modules are audited.

### Email verification / forgot password
- Neither exists in Rust at all.
- Laravel has an **entire parallel Breeze/Fortify stack** for these (`VerifyEmailController`, `PasswordResetLinkController`, `NewPasswordController`, `EmailVerificationNotificationController`) — but these belong to the **separate web-session auth system**, not the `Api\AuthController` JSON API. They are irrelevant to `auth_leasy` parity but their presence means `email_verified_at` exists on the `users` table and could silently interact with other code paths (e.g., Breeze's `verified` middleware) if ever applied to API routes. Not currently applied to the API routes reviewed.

---

## STEP 6 — DATABASE COMPARISON

| Aspect | Rust (`create_user_1.sql`) | Laravel (migrations) | Match? |
|---|---|---|---|
| Table name | `users` | `users` | ✅ |
| Primary key | `user_id UUID DEFAULT gen_random_uuid()` | `id` bigint auto-increment | ❌ **type change** |
| Email column name | `user_email` | `email` | ❌ name differs (app-layer maps it, so not itself a break, but any raw SQL/reporting tooling pointed at the DB needs updating) |
| Email uniqueness | `UNIQUE` + functional `UNIQUE INDEX ON (LOWER(user_email))` | `unique()` on `email` only | ❌ case-insensitive constraint **not enforced** at DB level |
| `user_type` | `TEXT NOT NULL CHECK (... IN (4 values))` | `string` with default `'Privatkunde'`, no CHECK, no NOT NULL enforcement beyond default | ❌ constraint not enforced at DB level |
| `password_hash`/`password` | `TEXT NOT NULL` | `string NOT NULL` (via `$table->string('password')`) | ✅ equivalent |
| `created_at` | `TIMESTAMPTZ NOT NULL DEFAULT now()` | `timestamps()` → `created_at`/`updated_at`, nullable by Laravel convention | ⚠️ Laravel's are nullable columns (framework default), Rust's is `NOT NULL DEFAULT now()`; also Laravel adds `updated_at` which doesn't exist in Rust |
| Extra columns | none | `name` (required, no default), `email_verified_at`, `remember_token`, `phone`, `address`, `city`, `zip_code`, `country`, `avatar_path`, `is_active` (default true) | New surface area; none of these are populated/read by `auth_leasy`, so no data-loss risk in that direction, but a Rust→Laravel data migration must synthesize `name` for every existing row (schema requires it) |
| Foreign keys / relationships | none | `User` has `belongsToMany(B2B)`, `hasMany(Vehicle)`, `hasOne(LeasybackUserProfile)`, `hasOne(UserPreference)` | New, unrelated to auth parity, expected given Laravel's larger scope |
| Indexes | PK + functional unique index on lower(email) | PK + plain unique index on email | ❌ (see above) |

**Data migration blocker:** because the PK type changes from UUID to auto-increment integer, there is no direct 1:1 row migration path — every existing UUID `user_id` referenced anywhere else (JWTs already issued, other services, audit logs, client-stored IDs) becomes meaningless under the new schema unless a UUID column is added and preserved alongside/instead of the integer PK.

---

## STEP 7 — VALIDATION RULE-BY-RULE

| Field / Rule | Rust | Laravel | Match? |
|---|---|---|---|
| `user_email` required | implicit (serde deserialize fails → generic 400 if absent) | `required` | ⚠️ same effect, different error body |
| `user_email` format | none | `email:rfc,dns` (register), `email` (login) | ❌ new, stricter, and network-dependent (DNS check) on register |
| `user_email` max length | none | `max:255` | ❌ new |
| `user_email` uniqueness (register) | DB constraint only (surfaces as raw error) | `unique:users,email` app-level pre-check | ⚠️ different failure path/status/body, and (per Step 6) not case-insensitive the way Rust's DB constraint is |
| `user_type` required | implicit | `required` | ✅ |
| `user_type` allowed values | `Privatkunde, Firmenkunde, Werksatatt, Admin` (hardcoded array in handler) | `Privatkunde, Firmenkunde, Werksatatt` (`Rule::in(registrableValues())`, i.e. **excludes Admin**) | ❌ **Admin removed from allowed values** |
| `password` (register) required | implicit | `required` | ✅ |
| `password` (register) length | **none** | `min:8`, `max:128` | ❌ new, stricter |
| `name` | field doesn't exist | `nullable`, `max:255`, defaulted from email local-part if absent | N/A — new field |
| `current_password` required (change) | manual `is_empty()` check → 400 | `required` | ✅ same rule, different status/body |
| `new_password` required (change) | manual `is_empty()` check → 400 | `required` | ✅ same rule, different status/body |
| `new_password` min length (change) | manual `len() < 8` → 400 | `min:8` | ✅ same rule, different status/body |
| `new_password` != `current_password` | manual `==` check → 400 | `different:current_password` | ✅ same rule, different status/body |
| `new_password` max length | none | `max:128` | ❌ new |
| Ownership / authorization validation | N/A (no ownership concept in these 3 endpoints) | N/A | ✅ (both absent, consistent) |

---

## STEP 8 — BUSINESS RULES: EXTRACTED VS VERIFIED

| Business rule (Rust) | Present in Laravel? | Notes |
|---|---|---|
| Self-service `Admin` registration allowed | **NO** — blocked | Deliberate change; confirm with stakeholders this is wanted |
| Case-insensitive email uniqueness enforced at DB | **NO** — app-level `unique()` only, case-sensitivity DB-dependent | Real risk of duplicate-by-case accounts |
| Case-insensitive email match at login | **YES** (`whereRaw('LOWER(email)...')`) | ✅ matches |
| Wrong-password vs. no-such-user distinguishable by status code (406 vs 401) | **NO** — both return 401 uniformly | Behaviorally different (arguably a security improvement, since it removes an enumeration oracle, but it is still a deviation) |
| No password strength rule on register | **NO** — now enforced (`min:8`) | Stricter; existing weak-password users unaffected retroactively, but new registrations behave differently |
| Registration email sent twice (1 hidden BCC) | **NO** — single send | Missing (probably fine to drop, but flagging per the brief) |
| Registration failure is always non-blocking to register | **YES** — try/catch around `Mail::queue()` | ✅ matches (though Laravel's mail is *queued*, so actual delivery failures happen asynchronously and are logged separately — even less blocking than Rust's synchronous-but-swallowed failure) |
| JWT 24h fixed expiry, no revocation | Sanctum tokens default to 24h expiry (config) but **are DB-revocable** (`logout` deletes the token row) | Different mechanism, similar default duration |
| No rate limiting | Laravel adds `throttle:5,1` / `throttle:10,1` | New; will produce 429s where Rust never would |
| CORS fully open | Laravel restricts to `FRONTEND_URL` + localhost regex | Stricter; could break other legitimate origins currently working against Rust |
| No ownership checks (N/A for these 3 endpoints) | Same | ✅ |

---

## STEP 9 — RESPONSE COMPARISON

### Success responses
| Endpoint | Rust body | Laravel body | Match? |
|---|---|---|---|
| register | `{ok:true, data:{user_id(uuid), user_email, user_type, created_at}, message:"User registered"}` | `{ok:true, data:{user_id(int), user_email, user_type, created_at}, message:"User registered"}` | ⚠️ shape matches, `user_id` **type** differs (UUID string vs integer) |
| login | `{ok:true, data:{token(JWT), user_type, user_id(uuid)}, message:"Login successful"}` | `{ok:true, data:{token(opaque), user_id(int), user_type}, message:"Login successful."}` (note trailing period added) | ⚠️ shape matches, token format + user_id type differ; message text has an extra period |
| changepassword | `{"message":"Password updated successfully"}` (no `ok`/`data` wrapper, own HTTP 200) | `{ok:true, data:null, message:"Password updated successfully."}` | ⚠️ Laravel adds fields Rust doesn't have; trailing period added |

### Error responses
| Scenario | Rust status | Rust body | Laravel status | Laravel body |
|---|---|---|---|---|
| Register: invalid user_type | 400 | plain text `"Invalid user type"` | 422 | JSON `{ok:false,...,errors:{user_type:[...]}}` |
| Register: duplicate email | 400 | plain text `"Database Error: duplicate key value violates unique constraint ..."` | 422 | JSON `{ok:false,...,"This email is already registered."}` |
| Register: hashing failure | 406 | plain text | (not really reachable in PHP the same way) | n/a |
| Login: user not found | 401 | plain text `"Unauthorized"` | 401 | JSON `{ok:false,...,"Invalid credentials."}` |
| Login: wrong password | **406** | plain text `"Current password does not match"` | 401 | JSON `{ok:false,...,"Invalid credentials."}` |
| Change password: no/bad Authorization header | 400 | plain text `"Missing or invalid Authorization header"` | 401 | JSON `{"message":"Unauthenticated."}` (Sanctum default) |
| Change password: expired token | 400 | plain text `"Token expired"` | 401 | JSON `{"message":"Unauthenticated."}` |
| Change password: wrong current password | **406** | plain text `"Current password does not match"` | 422 | JSON `{ok:false,...,"Current password is incorrect."}` |
| Change password: new password too short/same as old | 400 | plain text | 422 | JSON w/ validation errors |
| Any uncaught exception | 500 | plain text `"Internal Server Error"` | 500 | Laravel's default JSON error handler — **includes stack trace, file, line if `APP_DEBUG=true`** (which is the `.env.example` default!) |

**Every single error path differs** in status code, content type (plain text vs JSON), and body shape between the two implementations. Any client written against the real Rust error contract will need a full rewrite of its error-handling branch to work with Laravel, and vice versa.

---

## STEP 10 — ENVIRONMENT VARIABLES

| Rust var | Laravel equivalent | Notes |
|---|---|---|
| `DATABASE_URL` (single connection string, required, panics if absent) | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (discrete vars), default connection `sqlite` in `.env.example` | Entirely different contract; ops/deploy scripts must change |
| `SECRET` (HMAC key for JWT, required lazily) | **No equivalent** — Sanctum tokens are hashed and stored in the DB, secured by Laravel's `APP_KEY` for other purposes, not used the same way | JWT-verification-by-shared-secret is gone |
| `SEND_GRID` (required at **startup**, `.expect()` panics if missing) | No equivalent required var; relies on generic `MAIL_MAILER` (defaults to `log` — i.e., **emails are not actually sent** unless explicitly configured for SMTP in production `.env`) | No SendGrid API integration exists in Laravel at all — no SendGrid transport package installed, no `SENDGRID_API_KEY`-style var anywhere in `.env.example` or `config/services.php`. If production intends to keep using SendGrid's HTTP API specifically (not generic SMTP), **this integration does not exist yet** |
| `FRONTEND_URL` (optional, default `https://app.leasyback.com`) | `FRONTEND_URL`, same default, read via `config('app.frontend_url')` | ✅ matches |
| — | `SANCTUM_STATEFUL_DOMAINS`, `SANCTUM_TOKEN_EXPIRATION`, `SANCTUM_TOKEN_PREFIX` | New, required for the new auth mechanism |
| — | `APP_DEBUG` (defaults `true` in `.env.example`!) | **Must be `false` in production** — leaves stack traces in 500 responses otherwise; verify actual deployed `.env`, not just the example |

---

## STEP 11 — SECURITY AUDIT

- **Ownership checks**: N/A for these 3 endpoints in both systems — consistent.
- **Authentication**: Present in both, mechanically incompatible (JWT vs Sanctum) — not a removal, but not interoperable either.
- **Authorization**: Absent in both for these endpoints — consistent, not a regression.
- **JWT validation**: Not applicable to Laravel (no JWT issued) — this isn't "removed," it's "never built"; if any *other* service in the ecosystem validates the Rust JWT independently, that integration is now broken.
- **Password verification**: Present and correct in both (Laravel's is arguably more correct, since Rust's never actually reaches its "wrong password" branch as designed).
- **Validation**: Laravel's validation is measurably *more* thorough (email format, DNS check, length caps) — not weaker.
- **Rate limiting**: Rust has **none** on any endpoint (unlimited login attempts, unlimited registration spam). Laravel adds `throttle:5,1` and `throttle:10,1` — a genuine security improvement, not present in the original, and not something to remove for parity's sake, but flag: if load-testing or legitimate bulk operations depended on unlimited request rates against the Rust service, they will now get 429s.
- **CSRF**: Not applicable — this is a stateless bearer-token API in both systems; Laravel's `bootstrap/app.php` explicitly documents that CSRF/cookie middleware is intentionally *not* applied to these routes ("The standalone leasyback_web SPA authenticates with Sanctum bearer tokens. Do not apply cookie/CSRF middleware to its API requests.") — correct call, consistent with Rust.
- **Security headers**: Neither system sets any special security headers (`X-Frame-Options`, `Content-Security-Policy`, HSTS, etc.) beyond framework/proxy defaults — parity, though neither is great.
- **Timing protections**: Rust does zero work to normalize timing between "user not found" and "wrong password" (short-circuits before hashing on user-not-found — actually a timing side-channel already present in Rust: `verify_password` is only called if a row was found, so a nonexistent email returns faster than a wrong password on an existing email). Laravel's `login()` has the exact same short-circuit shape (`! $user || ! Hash::check(...)` — `Hash::check` only runs if `$user` exists, due to short-circuit `||` evaluation) — **same timing profile, consistent, both vulnerable to the same enumeration-via-timing risk** (not introduced or fixed by the migration).
- **Debug mode leak**: `.env.example` ships `APP_DEBUG=true` — if this reaches production unedited, 500 errors will leak stack traces, file paths, and possibly query bindings. Rust's equivalent failure mode is a static "Internal Server Error" string, always. **Must verify actual production `.env`.**
- **Email enumeration via status code**: Rust exposes it today (401 vs 406 due to the bug). Laravel closes it (uniform 401). Net security improvement, but a deviation from literal parity — worth an explicit product decision either way.

---

## STEP 12 — HIDDEN LOGIC / THINGS AI MIGRATIONS COMMONLY MISS

| Hidden behavior in Rust | Found in Laravel? |
|---|---|
| Email lowercased for comparison only, **not** for storage (`user_email` stored as-submitted, compared via `LOWER()`) | Same pattern in Laravel (`whereRaw('LOWER(email)=?', ...)`, stored value unchanged) — ✅ matches |
| No `.trim()` anywhere on email/password input in Rust (leading/trailing whitespace is significant and stored/compared as-is) | Laravel also has no explicit `trim` rule on these fields — ✅ matches (both leave whitespace-sensitive) |
| UUID generation happens in **Postgres**, not the app (`DEFAULT gen_random_uuid()`) | Laravel: PK generation is auto-increment, entirely different mechanism — ❌ (already covered above) |
| No DB transactions anywhere in Rust (`register` is a single INSERT, no wrapping transaction; email send happens after, deliberately outside any transaction) | Laravel: `User::create()` is a single statement too, no explicit transaction — ✅ matches (neither wraps in a transaction, consistent) |
| No audit logging in Rust for auth events | Laravel: none for the Api\AuthController flows either (there IS a `VehicleAuditLog` model elsewhere, unrelated) — ✅ matches |
| Registration email failure never rolls back user creation | Laravel: same — `Mail::queue()` failure is caught and logged, user record stands — ✅ matches |
| Double-send-with-hardcoded-BCC | Not replicated — see Step 8 | ❌ missing (likely fine to drop, must confirm intent) |
| `created_at` returned as whatever `chrono`/`sqlx` serializes (RFC3339 w/ offset) | Laravel: `$user->created_at->toISOString()` — also ISO8601, but Carbon's `toISOString()` always renders `Z`/UTC-normalized suffix; verify exact string format matches any strict client-side date parser | ⚠️ likely fine, but worth a byte-for-byte check if any client does strict format matching |
| Default helper methods: none of note beyond `hash_password`/`verify_password`/`generate_jwt`/`extract_jwt` | Laravel adds `User::isAdmin()`, `User::isType()` — unused by the 3 endpoints, harmless additions | N/A |
| Rollback behavior on partial failure: N/A (no multi-step transactions to roll back in either system for these endpoints) | ✅ consistent | |

---

## STEP 13 — CODE COVERAGE MATRIX

| Rust Feature | Laravel Feature | Status | Confidence |
|---|---|---|---|
| POST /auth/register (route existence) | AuthController::register | COMPLETE | 95% |
| Register — user_type validation | RegisterRequest + UserType enum | PARTIAL (Admin excluded) | 95% |
| Register — email validation | RegisterRequest | INCORRECT (new stricter behavior + DNS check) | 90% |
| Register — password validation | RegisterRequest | INCORRECT (new min/max, none existed) | 95% |
| Register — duplicate email handling | Eloquent `unique` rule | INCORRECT (status/format/case-sensitivity all differ) | 90% |
| Register — DB insert & PK generation | `User::create()` | INCORRECT (UUID → int) | 100% |
| Register — welcome email send | `Mail::to()->queue()` | PARTIAL (no SendGrid transport wired, single-send not double-send, name added to template) | 85% |
| Register — response shape (success) | JsonResponse | PARTIAL (user_id type differs) | 95% |
| POST /auth/login (route existence) | AuthController::login | COMPLETE | 95% |
| Login — case-insensitive email match | `whereRaw(LOWER...)` | COMPLETE | 95% |
| Login — password verification | `Hash::check` | INCORRECT (bcrypt vs argon2id — verification will fail on migrated data unless config changed) | 90% |
| Login — token issuance | Sanctum `createToken` | INCORRECT (JWT → opaque token, full mechanism swap) | 100% |
| Login — error responses | JsonResponse | INCORRECT (status codes + body format differ) | 95% |
| POST /auth/changepassword (route existence) | AuthController::changePassword | COMPLETE | 95% |
| Change password — auth gate | `auth:sanctum` middleware | INCORRECT (mechanism swap, same as login) | 100% |
| Change password — validation rules | ChangePasswordRequest | COMPLETE (same rules, different transport/status) | 85% |
| Change password — response shape | JsonResponse | PARTIAL (envelope added, not present in Rust) | 85% |
| JWT generation/verification | — | MISSING | 100% |
| `AuthenticatedUser`/`verify_jwt` (dead code in Rust) | — | N/A (nothing to port) | 100% |
| Rate limiting | `throttle` middleware | EXTRA (not in Rust) | 100% |
| CORS policy | `config/cors.php` | CHANGED (stricter) | 95% |
| `SEND_GRID` required-at-boot guarantee | — | MISSING | 90% |
| `DATABASE_URL` contract | Discrete `DB_*` vars | CHANGED | 100% |
| Users table schema | Migrations | INCORRECT (PK type, missing CHECK constraint, missing case-insensitive unique index) | 100% |
| Email enumeration oracle (406 vs 401) | Uniform 401 | CHANGED (closed) | 90% |
| Self-registration as Admin | Blocked | CHANGED (closed) | 95% |

---

## STEP 14 — MISSING FEATURES REPORT

### CRITICAL
1. **Password hashing algorithm mismatch (Argon2id vs bcrypt).**
   - Rust location: `auth/handlers.rs:35-54`.
   - Expected: verify/produce Argon2id PHC-format hashes so existing user passwords keep working.
   - Laravel location: no `config/hashing.php`, default `bcrypt` used implicitly by `Hash::make()`/the `'hashed'` cast.
   - Why missing: no hashing config was published/customized during the AI migration.
   - Risk: **total login failure for every migrated existing user** until they reset their password.
   - Suggested fix: publish `config/hashing.php`, set driver to `argon2id` (PHP's `password_hash`/`password_verify` support PHC-format argon2id compatible with the Rust `argon2` crate's default output), verify with a real hash exported from the Postgres DB.

2. **Auth mechanism swap: JWT → Sanctum opaque tokens.**
   - Rust location: `auth/middleware.rs` (whole file).
   - Expected: HS256 JWT signed with `SECRET`, containing `user_id`/`email`/`user_type`/`exp`, verifiable by any consumer holding the secret.
   - Laravel location: `AuthController::login` (`createToken`), `routes/api.php` (`auth:sanctum`).
   - Why missing: architectural choice by the AI migration, not flagged as a decision point.
   - Risk: any external consumer that verifies the JWT independently (not just `auth_leasy` itself) breaks entirely; the "login token" contract for the whole ecosystem changes shape.
   - Suggested fix: this needs a product decision, not a silent code fix — either (a) issue real JWTs from Laravel using `SECRET` and matching claims (e.g. via `tymon/jwt-auth`), or (b) formally sunset JWT and update every consumer to accept opaque tokens instead. Do not ship without an explicit choice here.

3. **Primary key type change: UUID → auto-increment integer.**
   - Rust location: `create_user_1.sql:2`.
   - Expected: `user_id` is a UUID, generated by Postgres, returned in JWT claims and JSON responses as a string.
   - Laravel location: `0001_01_01_000000_create_users_table.php:12` (`$table->id()`).
   - Why missing: default Laravel scaffold was used as-is instead of matching the source schema.
   - Risk: no clean path to migrate existing rows; any external reference to a Rust `user_id` (stored tokens, other services' foreign keys, already-issued JWTs) becomes unresolvable.
   - Suggested fix: add a UUID column (possibly keep it as the field surfaced in API responses) or, if starting fresh, treat this as an explicit, signed-off breaking schema change.

4. **SendGrid integration does not exist.**
   - Rust location: `email/reg_email.rs` (direct SendGrid HTTP API call).
   - Expected: welcome email delivered via SendGrid using `SEND_GRID` API key.
   - Laravel location: `config/mail.php` (`log` driver default), no SendGrid transport package in `composer.json`, no `SENDGRID`-named var anywhere.
   - Why missing: generic Laravel `Mail` scaffolding was used without wiring the actual production transport.
   - Risk: **in the shipped default config, registration emails are never actually delivered** — they're written to the log channel. Even with `MAIL_MAILER=smtp` configured, that's SMTP, not SendGrid's HTTP API — different deliverability/reputation/analytics characteristics.
   - Suggested fix: install a SendGrid transport (e.g. Symfony's SendGrid Mailer bridge) and wire `SEND_GRID` (or a renamed equivalent) into `config/mail.php` / `config/services.php`, then update `MAIL_MAILER`.

### HIGH
5. **DB-level `user_type` CHECK constraint missing.**
   - Rust: `create_user_1.sql:4`. Laravel: plain `string` column, app-validation only.
   - Risk: any direct DB write (migration script, admin tool, future bug) can insert an invalid `user_type` with nothing at the DB layer to stop it.
   - Fix: add a raw `CHECK` constraint via a migration (`DB::statement(...)`) or a Postgres enum type.

6. **Case-insensitive email uniqueness not enforced at DB level.**
   - Rust: functional unique index on `LOWER(user_email)`. Laravel: plain `unique()`.
   - Risk: `User@x.com` and `user@x.com` can both be registered as distinct rows depending on DB collation (real risk on Postgres, which is case-sensitive by default); login's `LOWER()` match would then non-deterministically pick one of two accounts.
   - Fix: add a functional unique index (`CREATE UNIQUE INDEX ... ON users (LOWER(email))`) via raw SQL in a migration.

7. **Login/changepassword error contract completely different (status codes + body format).**
   - See Step 9 table in full.
   - Risk: any existing client coded against the real Rust responses (plain-text bodies, 406 on wrong password) will mishandle every Laravel error response.
   - Fix: decide definitively which contract the frontend actually needs and align Laravel's error responses to it (likely: keep Laravel's cleaner JSON contract, but this is a product decision, not a "should" — flagging per audit brief).

8. **No automated tests exist for the actual replacement endpoints.**
   - Laravel `tests/Feature/Auth/*` all test the unrelated Breeze web-session controllers; zero tests touch `Api\AuthController`.
   - Risk: none of the divergences in this report are guarded by CI; regressions can land silently.
   - Fix: write feature tests for `POST /auth/register`, `/auth/login`, `/auth/changepassword` against the exact Rust behaviors that are meant to be preserved (or the explicitly-approved deviations).

### MEDIUM
9. **CORS policy tightened (open → allow-list).** `main.rs:42-50` vs `config/cors.php`. Risk: any currently-working origin outside `FRONTEND_URL`/localhost gets blocked. Fix: confirm with stakeholders which origins must be supported in production, update `allowed_origins`/`allowed_origins_patterns` accordingly.

10. **Rate limiting introduced where none existed.** `routes/api.php:22,26,36`. Risk: legitimate high-volume callers (internal scripts, load tests, a misbehaving-but-legitimate client retry loop) now get 429s. Fix: confirm throttle limits with stakeholders; not necessarily wrong, just a new failure mode to document.

11. **Self-registration as `Admin` blocked.** `UserType::registrableValues()` vs Rust's hardcoded 4-value array including `Admin`. Risk/benefit: closes what is very likely an unintentional privilege-escalation hole in the current production Rust service — but is a literal behavior change. Fix: confirm this is desired (it almost certainly is) and document it as an intentional, approved deviation rather than an audit gap.

12. **Double-send-with-hardcoded-BCC email not replicated.** `email/reg_email.rs:93-119`. Risk: low (this looks like debug/monitoring cruft), but flagging since the brief demands 100% parity be an explicit choice, not an oversight. Fix: confirm with the team whether `jupiterbarua@gmail.com` needs to keep receiving a copy of every registration email; if not, formally deprecate rather than silently drop.

13. **`APP_DEBUG` defaults `true` in `.env.example`.** Risk: stack-trace leakage on 500s in production if the real `.env` isn't hardened. Fix: verify production `.env` explicitly, add a deploy-time check.

14. **`DATABASE_URL`/`SEND_GRID`-required-at-boot guarantees lost.** Rust panics immediately at startup if these are missing, giving an unmistakable fail-fast signal. Laravel has no equivalent boot-time assertion for mail configuration (DB connection failure will surface on first query instead of at boot). Fix: add a boot-time config check/health check if fail-fast behavior is operationally important.

### LOW
15. **Response field ordering / trailing-period differences in messages** (`"Login successful"` vs `"Login successful."`). Purely cosmetic unless a client does exact string matching on messages (bad practice, but confirm).
16. **`created_at` serialization format** — confirm Carbon's `toISOString()` output matches whatever `chrono`/`sqlx-postgres` produces byte-for-byte if any consumer parses dates strictly.
17. **Two parallel, unrelated auth systems now coexist** (Breeze web-session controllers + the API's Sanctum controller) sharing one `users` table — not a parity bug, but worth documenting so future maintainers don't confuse the two.

---

## STEP 15 — BEHAVIORAL COMPATIBILITY SCORES

| Dimension | Score | Rationale |
|---|---|---|
| Routes | 80% | All 3 required routes exist at matching paths (including the deliberate unprefixed-compat routing); 2 extra routes added (harmless) |
| Validation | 55% | Same rules present for change-password; register validation is materially different (stricter, new DNS dependency, Admin removed) |
| Authentication | 15% | Complete mechanism swap (JWT → Sanctum); nothing here is drop-in compatible for any external consumer expecting JWTs |
| Authorization | 90% | Neither system does real authorization on these 3 endpoints — consistent by omission |
| Business Logic | 45% | Core flows exist but numerous rule changes (Admin registration, password policy, error semantics, double-email-send) |
| Database | 35% | PK type change and two dropped DB-level constraints are significant; column additions are benign but the core schema is not compatible |
| Responses | 30% | Success-path shapes are close; every error path differs in status code and/or body format |
| Security | 70% | Net security posture is arguably *better* (closed enumeration oracle, added rate limiting, stronger validation) but that itself is a parity violation the audit brief requires flagging, and the bcrypt/argon2 mismatch is a serious regression risk for migrated data |
| **Overall Migration** | **~45%** | Functionally present but not behaviorally interchangeable; requires explicit product sign-off on multiple intentional deviations, plus fixes for the unintentional gaps, before this can be called equivalent |

---

## STEP 16 — MIGRATION READINESS

## **NO — auth_leasy cannot be replaced by leasyback-backend today.**

### Blockers (must resolve before cutover)
1. Password hashing algorithm mismatch — will lock out every migrated existing user (**CRITICAL**, Step 14 #1).
2. Auth mechanism is not JWT — breaks any external consumer that verifies tokens independently of calling back into the auth service, and changes the client-side contract entirely (**CRITICAL**, Step 14 #2).
3. Primary key type changed from UUID to auto-increment integer — no lossless data migration path exists as-is (**CRITICAL**, Step 14 #3).
4. SendGrid delivery is not wired up — registration emails will not be delivered via SendGrid in the current configuration (**CRITICAL**, Step 14 #4).
5. Error response contract (status codes + body shape) is completely different across every failure path on every endpoint — any existing client needs a coordinated rewrite (**HIGH**, Step 14 #7).
6. Zero automated test coverage on the actual replacement endpoints — no regression safety net for any fix made in response to this report (**HIGH**, Step 14 #8).
7. DB-level integrity constraints (user_type CHECK, case-insensitive email uniqueness) are not enforced — silent data-integrity drift over time (**HIGH**, Step 14 #5, #6).

### Deviations that are probably *desired* but require explicit sign-off, not silent acceptance
- Self-registration as `Admin` now blocked.
- Email enumeration oracle (406 vs 401) closed.
- Rate limiting added.
- CORS tightened.
- Stricter register-time password/email validation.

None of these should block a cutover on their own — they look like genuine improvements — but per the audit's own charter ("Treat the Rust backend as the source of truth… the Laravel backend must match it exactly"), each is a deviation that needs a recorded decision, not a silent inheritance from whatever the AI happened to generate.

**Recommendation:** Do not cut over until blockers 1–4 are resolved and blockers 5–7 have at least a documented remediation plan. Items in the "probably desired" list should be explicitly ratified (a one-line sign-off per item is enough) so this migration has a paper trail distinguishing "intentional improvement" from "accidental gap."
