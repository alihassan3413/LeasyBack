# Auth Module Guide

A ~10 minute orientation to how authentication works in this app today. For the exact API request/response contract, see the generated Scribe docs (below), not this file.

## 1. Purpose

The auth module owns:

- Inertia web registration and login (`resources/js/pages/auth/*`)
- Laravel session authentication for the web app
- Sanctum bearer-token authentication for the JSON API
- Logout (both session and token)
- Fetching the current authenticated user
- Password changes (API) and the settings-page password update (web)
- Forgot/reset password (web, via Laravel's password broker)
- Inactive-user enforcement (`is_active`) on every authenticated request
- User types/roles (`UserType` enum)
- Mail/queue behavior for registration email and password resets

## 2. Authentication architecture

Two independent authentication mechanisms exist side by side. There is no shared session between them and no JWT anywhere in this codebase.

**Web (Inertia)**
- Guard: `web` (session-based, `config/auth.php`)
- Login (`AuthenticatedSessionController::store`) calls `$request->session()->regenerate()` after a successful `Auth::attempt()` — prevents session fixation.
- Logout (`AuthenticatedSessionController::destroy`) calls `Auth::guard('web')->logout()`, then `$request->session()->invalidate()` and `regenerateToken()`.
- CSRF is enforced by the default `web` middleware group (Laravel's `ValidateCsrfToken`) for all state-changing requests.
- Standard Laravel password broker (`Password::broker()`) handles forgot/reset password; no custom reset logic exists.

**API (Sanctum)**
- Guard checked by Sanctum: `web` (`config/sanctum.php`) — but API consumers authenticate with a bearer token, not a cookie.
- `POST /api/auth/login` issues a token via `$user->createToken('auth-token')->plainTextToken`. The token name is currently hardcoded; there is no `device_name` parameter or multi-token-per-device tracking.
- Protected API routes require `Authorization: Bearer {token}` and are wrapped in `auth:sanctum` middleware.
- `POST /api/auth/logout` revokes only the token used on that request (`$user->currentAccessToken()->delete()`); other tokens issued to the same user are unaffected.
- Token expiration: `config('sanctum.expiration')`, currently 24 hours (`SANCTUM_TOKEN_EXPIRATION` env var).

**JWT is not used anywhere in this application.** Do not add it without a confirmed external requirement — see `docs/AUTH_PRODUCTION_IMPLEMENTATION_PLAN.md` §2 Decision 2.

## 3. User identifiers

- `users.id` is the internal auto-increment bigint primary key. All Eloquent relationships (`b2bCompanies`, `vehicles`, `leasybackProfile`, `preferences`, etc.) use it.
- **There is currently no separate public identifier column** (e.g. a UUID) on the `User` model — this is a deliberate greenfield decision (no legacy data to preserve; see the Implementation Plan §2 Decision 1).
- **Known gap:** `Api\AuthController`'s JSON responses (`register`, `login`, `me`) expose a field literally named `user_id`, but its value is `$user->id` — the internal bigint primary key returned directly, not a UUID or any other public-safe identifier. This is documented here and in the Scribe response examples rather than silently changed. If a non-guessable public identifier becomes a real requirement, add a dedicated column (e.g. `public_id`) rather than continuing to expose the bigint.

## 4. User types

`App\Enums\UserType` (backed string enum), actual stored values:

| Case | Stored value |
|---|---|
| `Privatkunde` | `Privatkunde` |
| `Firmenkunde` | `Firmenkunde` |
| `Werkstatt` | `Werksatatt` *(sic — the stored value has a typo relative to the case name; this is existing data/behavior, not changed here)* |
| `Admin` | `Admin` |

- `UserType::registrableValues()` returns everything except `Admin` — this is the enforced allow-list for both the API (`RegisterRequest`) and web (`Auth\RegisterRequest`) registration endpoints.
- **Admin cannot self-register** through either registration path. There is currently no admin-invite or promotion flow in this module; admin accounts are created via `AdminUserSeeder`.
- Both registration controllers set `$user->user_type` via **explicit attribute assignment after `User::create()`**, never through mass assignment — `user_type` and `is_active` are deliberately excluded from `User::$fillable` (see `app/Models/User.php`).
- The web registration form does not expose a user-type selector; it silently defaults every self-service signup to `Privatkunde`.
- **Inactive users (`is_active = false`) cannot authenticate or use an existing session/token** — enforced at three points: `Auth\LoginRequest::authenticate()` (web login), `Api\AuthController::login()` (API login), and the `active` middleware (`EnsureUserIsActive`) on every already-authenticated request, for both guards.

## 5. Code locations

| Concern | Path |
|---|---|
| Web auth controllers | `app/Http/Controllers/Auth/*` (`AuthenticatedSessionController`, `RegisteredUserController`, `PasswordResetLinkController`, `NewPasswordController`, `ConfirmablePasswordController`, `EmailVerification*Controller`, `VerifyEmailController`) |
| API auth controller | `app/Http/Controllers/Api/AuthController.php` |
| Web Form Requests | `app/Http/Requests/Auth/*` (`LoginRequest`, `RegisterRequest`), `app/Http/Requests/Settings/ProfileUpdateRequest.php` |
| API Form Requests | `app/Http/Requests/Api/*` (`RegisterRequest`, `LoginRequest`, `ChangePasswordRequest`) |
| Shared validation rule | `app/Rules/CaseInsensitiveUniqueEmail.php` |
| User model | `app/Models/User.php` |
| UserType enum | `app/Enums/UserType.php` |
| Inactive-user middleware | `app/Http/Middleware/EnsureUserIsActive.php` (aliased as `active` in `bootstrap/app.php`) |
| Web auth routes | `routes/auth.php`, `routes/settings.php` |
| API auth routes | `routes/api.php` |
| Auth guard/provider config | `config/auth.php` |
| Sanctum config | `config/sanctum.php` |
| Hashing config (Argon2id) | `config/hashing.php` |
| CORS config | `config/cors.php` |
| Mail config (SendGrid relay) | `config/mail.php` |
| Registration email | `app/Mail/RegistrationWelcome.php` |
| Password reset notification | Laravel's default `Illuminate\Auth\Notifications\ResetPassword` (not customized) |
| Production config sanity check | `app/Console/Commands/ValidateProductionConfig.php` (`php artisan config:validate-production`) |
| Tests | `tests/Feature/Api/AuthControllerTest.php`, `tests/Feature/Auth/*`, `tests/Feature/Settings/*`, `tests/Feature/CorsTest.php`, `tests/Feature/UserSchemaConstraintsTest.php`, `tests/Feature/ValidateProductionConfigCommandTest.php`, `tests/Unit/HashingDriverTest.php`, `tests/Unit/UserModelTest.php`, `tests/Unit/HttpsSchemeEnforcementTest.php`, `tests/Unit/SendGridMailTransportTest.php` |

## 6. Route overview

### Web/session routes

| Method | Route | Purpose | Middleware |
|---|---|---|---|
| GET | `/register` | Show registration form | `guest` |
| POST | `/register` | Create account, log in | `guest` |
| GET | `/login` | Show login form | `guest` |
| POST | `/login` | Authenticate, regenerate session | `guest` |
| GET | `/forgot-password` | Show request-reset-link form | `guest` |
| POST | `/forgot-password` | Send password reset email | `guest` |
| GET | `/reset-password/{token}` | Show reset form | `guest` |
| POST | `/reset-password` | Set new password | `guest` |
| GET | `/verify-email` | Show "verify your email" prompt | `auth`, `active` |
| GET | `/verify-email/{id}/{hash}` | Confirm email verification | `auth`, `active`, `signed`, `throttle:6,1` |
| POST | `/email/verification-notification` | Resend verification email | `auth`, `active`, `throttle:6,1` |
| GET/POST | `/confirm-password` | Re-confirm password for sensitive actions | `auth`, `active` |
| POST | `/logout` | Log out, invalidate session | `auth`, `active` |
| GET/PATCH/DELETE | `/settings/profile` | View/update/delete profile | `auth`, `active` |
| GET/PUT | `/settings/password` | View/update password | `auth`, `active` |

### API/Sanctum routes

| Method | Route | Purpose | Auth requirement |
|---|---|---|---|
| POST | `/api/auth/register` | Create account | None (rate limited `5/min`) |
| POST | `/api/auth/login` | Authenticate, issue token | None (rate limited `10/min`) |
| POST | `/api/auth/changepassword` | Change password | Bearer token (rate limited `5/min`) |
| POST | `/api/auth/logout` | Revoke current token | Bearer token |
| GET | `/api/auth/me` | Get current user | Bearer token |

All five API routes are also reachable without the `/api` prefix (e.g. `/auth/login`) — an unprefixed compatibility alias registered in `bootstrap/app.php` for the legacy `leasyback_web` SPA. Same controller, same behavior; not a separate implementation.

## 7. Important implementation rules

- Use Form Requests for all input validation; controllers stay thin (fetch/validate → call → respond).
- Normalize emails via case-insensitive comparison, not by mutating storage — `CaseInsensitiveUniqueEmail` compares with `LOWER(email)`, it does not lowercase what's stored.
- Enforce email uniqueness case-insensitively at both the application layer (`CaseInsensitiveUniqueEmail`) and the database layer (Postgres-only unique index on `LOWER(email)`, see `database/migrations/2026_07_31_000001_*`).
- Passwords are hashed with Argon2id (`config/hashing.php`), OWASP's current recommended default. `Hash::check()` on a non-Argon2id hash throws under `verify => true` — don't hand-roll hash comparisons.
- Never expose credentials, tokens, or password hashes in logs. `User::$hidden` already excludes `password`/`remember_token` from serialization; keep it that way.
- `user_type` and `is_active` must never be mass-assignable from public input — they are deliberately excluded from `User::$fillable`. Set them via explicit attribute assignment (`$user->user_type = ...; $user->save();`) or `forceFill()` in trusted internal code (seeders), never via `create($request->all())` or similar.
- Use the `active` middleware alongside `auth`/`auth:sanctum` on every route that shouldn't be reachable by a deactivated account.
- Queue outbound email (`Mail::to(...)->queue(...)`) rather than sending synchronously; a queued-mail failure must not fail the triggering request (see `AuthController::register()`'s try/catch around the welcome email).
- Do not add JWT without an approved, confirmed external-consumer requirement.

## 8. How to add or change an auth endpoint

1. Add the route in `routes/api.php` (or `routes/auth.php`/`routes/settings.php` for web).
2. Create/update a Form Request under `Requests/Api` or `Requests/Auth`/`Requests/Settings`.
3. Add/update the controller action — keep it thin, delegate validation to the Form Request.
4. Apply the correct middleware (`auth:sanctum`/`auth`, `active`, `throttle:N,1`).
5. Return the standard response shape (see §9).
6. Add Scribe metadata: `#[Endpoint]`, `#[Group]`, `#[Unauthenticated]`/`#[Authenticated]`, and `#[Response]` attributes on the controller method; add a `bodyParameters()` method to the Form Request only for fields needing a description/example beyond what the validation rules already infer.
7. Add/update tests covering the happy path, validation failures, and auth/authorization edge cases.
8. Regenerate docs: `php artisan scribe:generate`.
9. Run `vendor/bin/pint --dirty --format agent` and the relevant test suite.

## 9. Standard API response structure

Success:

```json
{
  "ok": true,
  "data": {},
  "message": "..."
}
```

Failure:

```json
{
  "ok": false,
  "data": null,
  "message": "...",
  "errors": {}
}
```

This contract is enforced two ways: each Form Request's `failedValidation()` returns it directly, and a scoped exception renderer in `bootstrap/app.php` (limited to `auth/*` and `api/auth/*`) converts any other exception — including framework-thrown `AuthenticationException`, 404s, and 429 throttling — into the same shape, never leaking a raw exception message or stack trace.

## 10. Local commands

```bash
php artisan route:list --path=auth
php artisan test
vendor/bin/pint --dirty --format agent
npm run build
php artisan scribe:generate
```

Docs, once generated: `http://localhost/docs` (HTML), `http://localhost/docs.openapi` (OpenAPI 3.0.3), `http://localhost/docs.postman` (Postman collection). Source: `storage/app/private/scribe/{openapi.yaml,collection.json}`, `resources/views/scribe/index.blade.php` — all regenerated by `scribe:generate`, not hand-edited (gitignored).

## 11. Troubleshooting

- **401 from API** — missing/expired/revoked Sanctum token, or credentials rejected by `Api\AuthController::login()` (wrong password, unknown email, and deactivated account all return the identical 401 "Invalid credentials." — by design, to avoid account enumeration).
- **419 from session/CSRF** — missing/stale CSRF token on a web `POST`; the frontend must resend the `XSRF-TOKEN` cookie/header.
- **Inactive user redirect/403** — `EnsureUserIsActive` fired: a web session gets logged out and redirected to `/login`; an API request gets a JSON 403 "This account has been deactivated." and its token is revoked.
- **Token "not working"** — check it hasn't expired (`sanctum.expiration`, 24h default) or already been revoked by a prior logout.
- **Email not sent** — registration email failures are caught and logged, not thrown (check `storage/logs/laravel.log` for "Registration email failed"); confirm `MAIL_MAILER`/`SENDGRID_API_KEY` are set for the target environment.
- **Queue worker not running** — queued mail/jobs sit in the `jobs` table until a worker runs (`php artisan queue:work`); failed attempts land in `failed_jobs`.
- **Docs not updating** — Scribe only regenerates on `php artisan scribe:generate`; there is no file watcher and no CI check yet (see §12).

## 12. PR review checklist

- [ ] Controller stays thin; validation lives in a Form Request.
- [ ] `user_type`/`is_active` are never set via mass assignment.
- [ ] New authenticated routes include the `active` middleware.
- [ ] Response bodies follow the `{ok, data, message[, errors]}` contract.
- [ ] No password, hash, or token value can end up in a log line.
- [ ] Tests cover the happy path, validation failure, and at least one auth/authorization edge case.
- [ ] Scribe metadata added/updated for any endpoint whose contract changed; `scribe:generate` re-run.
- [ ] `vendor/bin/pint --dirty --format agent` and `php artisan test` both pass.

**Recommended CI check (not yet implemented — no CI exists in this repo):**
1. `php artisan scribe:generate` and fail the build if `storage/app/private/scribe/openapi.yaml` changes vs. the committed reference (catches undocumented endpoint drift), if a reference copy is checked in for that purpose.
2. `php artisan test`
3. `vendor/bin/pint --test`
