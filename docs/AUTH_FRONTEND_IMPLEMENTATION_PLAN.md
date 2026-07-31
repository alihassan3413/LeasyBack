# Auth Frontend Implementation Plan

Status: **All 6 checkpoints complete. See Checkpoint Log at the end of this document, and `docs/AUTH_FRONTEND_MODULE.md` / `docs/AUTH_FRONTEND_REPORT.md` for the finished-state documentation.**

### Locked decisions (from Checkpoint 0 review)

1. **Copy language: German.** Replace English placeholder copy with the old app's German text throughout all 6 pages. No i18n library added.
2. **Shared props: apply the trim.** `HandleInertiaRequests::share()`'s `auth.user` becomes `{ name, email, user_type }`; `types/index.ts`'s `User` interface updated to match. Internal bigint `id` and timestamps are dropped from the wire.
3. **Branding: auth-scoped tokens.** New brand CSS variables, used only inside `components/auth/*` and the auth layout. Global `--primary`/theme and font stay untouched — dashboard/business pages are not restyled.
4. **Password hint: match backend reality.** Guidance text says "at least 8 characters" only (the actual `min:8` rule) — never displays the old app's stricter, backend-unenforced uppercase/special-character rule.

Scope: the auth pages under `resources/js/pages/auth/*` and their supporting layouts/components only. Dashboard, settings-beyond-password, and all business modules (UserProfile, DekraProcess, B2B, Vehicle, Workshop, Order) are out of scope and untouched.

---

## 1. Source-page inventory (`leasyback_web/src/views/auth`)

Read every file in that directory (6 total) plus its router entry, layout, store, validation schema, and shared `ui/form` components. Actual findings:

| File | Is it a core auth page? | Route | Fields | Calls |
|---|---|---|---|---|
| `LoginView.vue` | **Yes** | `/account/login` | email, password | `authApi.login()` → Sanctum API, token stored in `localStorage` via Pinia `persist` |
| `RegisterView.vue` | **Yes** | `/account/register` | **role** (dropdown: Privatkunde/Firmenkunde/Werksatatt), email, password — **no name field at all** | `authApi.register()` then auto-login |
| `ForgotPasswordView.vue` | **Yes, but non-functional** | `/account/forgot-password` | email | **Simulated** — `await new Promise(r => setTimeout(r, 800))`, no real API call exists. This page was never wired to a backend. |
| `B2CRegistrationView.vue` | **No — business onboarding**, not account auth | n/a | multi-step: customer data, vehicle data, appointment | `b2cRegistration.store` — post-signup profile completion wizard |
| `RegisterCompanyView.vue` | **No — B2B onboarding** | n/a | company/admin registration | `b2b.store` + `auth.store` |
| `RegisterWorkshopView.vue` | **No — workshop onboarding** | n/a | address, contact, account, legal/terms | `workshop.store` + `auth.store` |

**Pages that do NOT exist in the old app** (confirmed by reading the directory listing, not assumed): **ResetPassword, ConfirmPassword, VerifyEmail**. There is no reset-password flow, no re-confirm-password flow, and no email verification flow anywhere in `leasyback_web`. The 3-page "core" auth surface there is Login / Register / (a non-functional) Forgot-Password stub only.

### Supporting details found

- **Layout**: `layouts/AuthLayout.vue` — split-screen design: left = white card (max-width 420px) with the routed view inside; right = solid `bg-primary` (`#10393b`) branding panel with logo + marketing copy, hidden below `lg:`. Two decorative background path SVGs. This is the visual reference for the target `AuthLayout`.
- **Shared form components**: `components/ui/form/TextInput.vue` (label + input + inline error, password-toggle via `@iconify/vue` icon button), `FormLabel.vue` (already reka-ui/shadcn-style `Label` wrapper), `DropDown.vue` (role selector, register-only).
- **Duplicate button implementations found**: `components/ui/Button.vue` (custom variant/size system, used by Login/Register/ForgotPassword) **and** a separate shadcn-style `components/ui/button` (used only by `RegisterWorkshopView.vue`). Two parallel button systems in the old app — not something to carry over.
- **Validation**: `vee-validate` + `yup`. Login: email + required password. Register: role required, email, password (min 8, ≥1 uppercase, ≥1 special char — **stricter than the Laravel backend's `min:8` only**). This client-side password-strength check is guidance-only in the old app; the real backend already enforces `min:8|max:128` — the extra uppercase/special-char rules are **not** currently enforced server-side and should be treated as optional non-authoritative UX guidance if kept at all, never a hard blocker.
- **API layer**: `src/api/` (`authApi`), talks to the Sanctum API (`/auth/login`, `/auth/register`, etc.) via a configured Axios-like client, with the access token persisted to `localStorage` (`auth.store.ts`, `pinia-plugin-persistedstate`). **This entire mechanism is explicitly what we are not carrying over** — the Inertia app must use session auth, not this store/token pattern.
- **Remember-me**: does not exist in the old app at all.
- **Password visibility toggle**: exists (`TextInput`'s `showPasswordToggle` prop), icon-only toggle button with **no `aria-label`** — accessibility gap to fix, not repeat, in the new implementation.
- **Error display**: single top-of-form generic alert banner (`error` from the store, red box) *plus* per-field inline errors from `vee-validate`. The generic-invalid-credentials mapping (`401`/`406` → "E-Mail oder Passwort ist falsch.") is a relic of the Rust backend's known 406-on-wrong-password bug (see `docs/AUTH_PRODUCTION_IMPLEMENTATION_PLAN.md`) — the new Laravel backend only ever returns 401/422, so this dual-status handling is not needed.
- **Branding tokens** (`src/style.css`, Tailwind v4 `@theme`): `--color-custom-orange: #ef8450` (CTA/links), `--color-primary: #10393b` (dark teal, headings/branding panel), `--color-custom-green: #01b990` (secondary links/success), `--color-green-gray: #b7c2c2` (borders/help text), `--color-custom-black: #2e3e3f` (body text). Font: Inter/Manrope. Logo assets: `src/assets/logo/leasyback-logo.svg` (light-on-dark, for the branding panel), `leasyback-logo-dark.svg` (for the mobile-stacked header), plus two decorative path SVGs.
- **Text/copy**: entirely German, e.g. "Hallo! Willkommen zurück!", "Passwort vergessen?", "Sind Sie noch kein Kunde bei uns? Hier registrieren", password hint "Min. 8 Zeichen, mind. 1 Großbuchstabe, mind. 1 Sonderzeichen". No i18n library in the old app — these are hardcoded strings.
- **Responsive behavior**: card is `max-w-[420px]`, stacks to full-width on mobile with the branding panel hidden below `lg:`; logo re-shown above the form on mobile only. Reasonable baseline to match.
- **Accessibility issues found**: password-toggle button has no accessible name; no visible `aria-invalid`/`aria-describedby` wiring between inputs and their error `<p>`; generic error banner is a plain `<div>`, not an `aria-live` region.

---

## 2. Existing target-page inventory (`leasyback-backend/resources/js`)

This is the **stock Laravel Vue starter kit** (Breeze-equivalent) scaffolding — already using Inertia `useForm()` + named routes + session auth, i.e. already architecturally correct. Nothing here calls Axios or Sanctum for these pages.

| Page | Exists? | Wired to | Notes |
|---|---|---|---|
| `pages/auth/Login.vue` | Yes, functional | `POST /login` (`AuthenticatedSessionController::store`) | email, password, **remember** checkbox already present. `form.reset('password')` on finish already done correctly. |
| `pages/auth/Register.vue` | Yes, functional | `POST /register` | **name, email, password, password_confirmation** — already exactly matches the required contract (no user_type selector, no privileged fields sent). |
| `pages/auth/ForgotPassword.vue` | Yes, functional | `POST /forgot-password` (`password.email`) | Generic `status` message already used, no account-existence leak. |
| `pages/auth/ResetPassword.vue` | Yes, functional | `POST /reset-password` (`password.store`) | Token/email come from Inertia props (`defineProps`), never rendered as visible text — good. |
| `pages/auth/ConfirmPassword.vue` | Yes, functional | `POST /confirm-password` | Simple, already minimal. |
| `pages/auth/VerifyEmail.vue` | Yes, functional | `POST /email/verification-notification`, `POST /logout` | **Verification is actually enforced**: `routes/web.php`'s `/dashboard` route carries `['auth', 'active', 'verified']` middleware, so this page is load-bearing, not just leftover scaffolding. Keep and polish. |

All 6 pages already use `useForm()`, named `route()` calls, and session-cookie/CSRF auth exclusively — **no rework of the auth mechanism itself is needed**, only componentization, branding, and accessibility/responsive polish.

### Existing component system

- **shadcn-vue / reka-ui** is already installed and in use (`reka-ui ^2.9.6`, `lucide-vue-next ^0.468.0`, `@vueuse/core ^12.0.0`, `class-variance-authority`/`cn()` in `lib/utils.ts`). This *is* a good existing system — **do not install a second UI library** (e.g. do not add Headless UI or a separate icon set).
- `components/ui/button/Button.vue` — cva-based `buttonVariants` (variant/size), reka-ui `Primitive`, already supports `asChild`. Solid foundation for `AppButton`; needs a `loading` prop added.
- `components/ui/input/Input.vue` — bare styled `<input>`, no `type`-aware behavior, no `aria-invalid`/`aria-describedby`, no password-toggle, no error-state styling of its own (relies on parent).
- `components/ui/checkbox/Checkbox.vue` — reka-ui `CheckboxRoot`, solid, just needs a label/error wrapper for consistent auth usage.
- `components/ui/label/Label.vue` — thin reka-ui `Label` wrapper (not read in full but confirmed present and already used everywhere).
- `components/InputError.vue` — bare `<p>`, `v-show`, no `id`/`role="alert"` — not associated with its input via `aria-describedby` anywhere it's used.
- `components/TextLink.vue` — generic Inertia `<Link>` wrapper, reusable as-is.
- **4 layout variants already exist**: `layouts/AuthLayout.vue` (currently a thin wrapper delegating to `AuthSimpleLayout`), `auth/AuthSimpleLayout.vue` (plain centered card, no branding), `auth/AuthCardLayout.vue` (centered `Card` component), `auth/AuthSplitLayout.vue` (**already** a two-column split with a dark branding panel + logo + quote — structurally the closest match to the old app's `AuthLayout.vue`, just using generic starter-kit "name/quote" copy instead of LeasyBack branding). **No new layout needs to be built from scratch** — `AuthSplitLayout` is repurposed with real branding.
- **No password-visibility toggle exists anywhere in the target app.**
- **No `AppFormField`-style wrapper exists** — every page hand-repeats `<div class="grid gap-2"><Label/><Input/><InputError/></div>`.
- **No frontend test tooling** (no vitest/@vue/test-utils/playwright in `package.json`) — component-level automated tests are not currently possible without adding tooling, which is out of scope for this frontend task unless explicitly requested. Verification will rely on `npm run build` (type-checks via `vue-tsc` isn't wired either — confirm during Checkpoint 1), backend Feature tests (already cover the auth *routes*), and manual viewport/browser checks.
- **No i18n library** (`vue-i18n` not installed) on either the old or the new app. Both are effectively hardcoded-string apps. See §14 for the resulting decision.

### Backend contract (already reviewed in prior backend checkpoints)

- `routes/web.php` + `routes/auth.php` + `routes/settings.php`: session guard (`auth`), `active` middleware (blocks deactivated accounts, redirects to `/login`), `verified` middleware on `/dashboard` only.
- `app/Http/Requests/Auth/RegisterRequest.php`: requires `name`, `email` (case-insensitive unique), `password` (`min:8|max:128`, no uppercase/special-char rule server-side).
- `app/Http/Requests/Auth/LoginRequest.php`: `email`, `password`, folds `is_active` into the same `Auth::attempt()` credentials check — a deactivated account gets the exact same generic "these credentials do not match" message as a wrong password (no enumeration). The frontend does not need any special-case handling for "inactive user" on login; it's indistinguishable from a normal auth failure by design.
- **`HandleInertiaRequests::share()` currently exposes the raw `$request->user()` model as `auth.user`** — i.e. `id` (internal bigint), `name`, `email`, `email_verified_at`, `created_at`, `updated_at` (via the model's default `toArray()`/`toJson()`; `password`/`remember_token` are excluded via `User::$hidden`, so those are not leaked). **This over-exposes the internal bigint `id` and unnecessary timestamps to every single page**, and does **not** currently expose `user_type` at all, despite that being genuinely useful (e.g. for a future dashboard greeting or type-specific UI). See §10 for the concrete fix.
- `resources/js/types/index.ts`'s `User` interface mirrors this same over-exposed shape (`id`, `name`, `email`, `avatar`, `email_verified_at`, `created_at`, `updated_at`) — needs to be trimmed in lockstep with the backend change.

---

## 3. Final page list

Six pages, matching the target app's existing scaffolding exactly (no pages added or removed):

1. `Login.vue`
2. `Register.vue`
3. `ForgotPassword.vue`
4. `ResetPassword.vue`
5. `ConfirmPassword.vue`
6. `VerifyEmail.vue`

No `Reset`/`Confirm`/`VerifyEmail` pages exist in the old app to migrate *from* — these three are implemented purely against the Laravel/Fortify-style contract already present in the target backend, using the old app's Login/Register pages only for tone/branding consistency.

## 4. Route-to-page mapping

| Method | Route | Page | Guard |
|---|---|---|---|
| GET/POST | `/login` | `Login.vue` | `guest` |
| GET/POST | `/register` | `Register.vue` | `guest` |
| GET/POST | `/forgot-password` | `ForgotPassword.vue` | `guest` |
| GET/POST | `/reset-password/{token}` | `ResetPassword.vue` | `guest` |
| GET/POST | `/confirm-password` | `ConfirmPassword.vue` | `auth`, `active` |
| GET | `/verify-email` | `VerifyEmail.vue` | `auth`, `active` |
| POST | `/logout` | (no page — redirect only) | `auth`, `active` |

No route changes needed; all already exist and are correctly guarded.

## 5. Reusable component architecture (`components/ui` + a couple of thin auth-adjacent wrappers)

Built **only** where they provide real, repeated reuse across ≥3 of the 6 pages. Adapted to the project's actual existing naming (`components/ui/*`, PascalCal component filenames, no `App*` prefix currently used anywhere in the repo — introducing an `App*` prefix now would be inconsistent with the existing `Button`/`Input`/`Checkbox`/`Label` naming, so new primitives follow the same bare-name convention inside `components/ui/*`, and cross-cutting form-composition helpers go in `components/form/*`):

- `components/ui/input/Input.vue` — **extend in place** (not replace): add `aria-invalid`, `aria-describedby` passthrough, keep the existing `v-model` contract so `Login`/`Register`/`ForgotPassword`/`ResetPassword`/`ConfirmPassword` don't need behavioral changes, only prop additions.
- `components/ui/password-input/PasswordInput.vue` (**new**) — wraps `Input` with a show/hide toggle button (accessible name, `aria-pressed`, correct `autocomplete` passthrough, 44px min touch target). Used by Login, Register, ResetPassword, ConfirmPassword (4 of 6 pages — real reuse).
- `components/form/FormField.vue` (**new**) — the label + control-slot + `InputError` composition every page currently repeats by hand (`<div class="grid gap-2"><Label/><Input/><InputError/></div>` appears ~14 times across the 6 pages). Renders label (with optional required marker), a default slot for the control, optional help text, and wires `id`/`aria-describedby`/error automatically.
- `components/InputError.vue` — **extend in place**: add `id` prop + `role="alert"` so `FormField` can wire `aria-describedby` correctly. No visual change.
- `components/ui/checkbox/Checkbox.vue`, `components/ui/button/Button.vue`, `components/ui/label/Label.vue`, `components/TextLink.vue` — **reused as-is**, no changes needed beyond `Button` getting a `loading` prop (see below).

`components/ui/button/Button.vue` gets one small addition: a `loading` boolean prop that shows the existing `LoaderCircle` spinner internally and sets `aria-busy`/`disabled` — currently every page repeats `<LoaderCircle v-if="form.processing" .../>` by hand inside the button's default slot. Centralizing this removes ~6 duplicated spinner blocks. Not a new component, an addition to the existing one.

## 6. Auth-specific component architecture (`components/auth/*`, new directory)

Kept intentionally small — only what's genuinely auth-specific and reused across pages:

- `components/auth/AuthStatusMessage.vue` — the green "status" banner pattern currently hand-copied in `Login`, `ForgotPassword`, `VerifyEmail` (`v-if="status"` green text block). Becomes one component with `variant` (`success` | `error` | `info`) and `role="status" aria-live="polite"`.
- `components/auth/PasswordRequirements.vue` — optional, non-authoritative hint text component ("At least 8 characters") for `Register`/`ResetPassword`. Renders static guidance only; **never** blocks submission — the backend is the sole authority.

Not building: a dedicated `AuthHeader`/`AuthFooter`/`AuthDivider` — the existing `AuthSplitLayout`'s header/logo/link areas are simple enough that a wrapper would be over-componentization for markup that appears once per page and already varies (e.g. `VerifyEmail` has no "already have an account" footer link at all).

## 7. Layout architecture

- Repurpose **`layouts/auth/AuthSplitLayout.vue`** (already exists) as the primary layout, updated with real LeasyBack branding instead of generic starter-kit name/quote:
  - Left: form card, `max-w-[420px]`, matches old app's card sizing.
  - Right (`lg:` and up only): solid brand-teal panel with the LeasyBack logo + a short marketing line (copy to confirm with product/content owner — see §14 decision).
  - `AuthLayout.vue` (the thin dispatcher) is updated to delegate to `AuthSplitLayout` instead of `AuthSimpleLayout`.
- `AuthSimpleLayout.vue` and `AuthCardLayout.vue` are **left in place, untouched** — they may still be used elsewhere or are safe, low-cost scaffolding to keep rather than delete (deletion is out of scope per "do not remove unused old scaffolding" until Checkpoint 6's explicit safety check).
- Responsive behavior to implement in the split layout: full-width single column with branding panel hidden below `lg:` (matches old app), safe `min-h-dvh` (not a fixed viewport height, so mobile keyboard opening doesn't clip the submit button), no horizontal overflow at 320px.

## 8. Form architecture

Every page keeps its own `useForm({...})` + `submit()` function, matching the existing pattern exactly — **no generic form abstraction is introduced**. The only shared pieces are visual/structural (`FormField`, `PasswordInput`, `AuthStatusMessage`), never business logic. This matches the existing pages almost exactly already; changes are additive (wrap existing fields in `FormField`, swap the plain password `Input` for `PasswordInput`) rather than restructuring `submit()` logic.

## 9. Validation/error strategy

- Backend (Laravel Form Requests) remains the sole authority. No yup/vee-validate/zod is introduced — the target app doesn't have it and it would duplicate backend rules, which is explicitly against the brief.
- `form.errors.<field>` (Inertia) flows into `FormField`'s slot, which renders `InputError` and wires `aria-describedby`/`aria-invalid` on the control automatically.
- `PasswordRequirements` (optional hint) is presentation-only and never derives a client-side pass/fail state.
- Sensitive fields are reset via `form.reset(...)` in `onFinish`, matching the existing `Login`/`Register`/`ResetPassword`/`ConfirmPassword` pattern already in place — no change to this behavior, just confirmed as correct and kept consistent everywhere (`ForgotPassword`/`VerifyEmail` have no password field to reset).

## 10. Shared props and TypeScript types — **backend contract fix required**

Current: `HandleInertiaRequests::share()` sends the full `$request->user()` model (`id`, `name`, `email`, `email_verified_at`, `created_at`, `updated_at`); `types/index.ts`'s `User` mirrors it.

Proposed (small, additive backend change, done alongside the frontend work since the two files must stay in sync — flagged here for approval, not silently done):

```php
'auth' => [
    'user' => $request->user() ? [
        'name' => $request->user()->name,
        'email' => $request->user()->email,
        'user_type' => $request->user()->user_type,
    ] : null,
],
```

```ts
export interface User {
    name: string;
    email: string;
    user_type: string;
}
```

- **Internal bigint `id` is dropped from shared props entirely** — nothing in the current Vue frontend (auth or otherwise) references `auth.user.id`, so there is no relationship that "genuinely needs" it. If a future feature needs a stable per-user reference client-side, that's a new requirement to size separately, not something to smuggle back in here.
- `is_active`, `email_verified_at`, timestamps, and any Sanctum-related field are never included — consistent with the security rules in the brief.
- **No UUID is invented.** As documented in `docs/AUTH_MODULE.md`, there is currently no public-safe user identifier column on `User` at all — only the internal bigint `id`. Since the trimmed shared-prop shape above doesn't need to expose any identifier, this doesn't force the missing-UUID problem into the frontend; it's simply not sent. This is the "use the actual current backend contract, don't invent a UUID" instruction applied concretely.
- This is a **1-line, additive, backward-compatible-in-spirit change** to `HandleInertiaRequests.php` (still Auth-module territory, same file already documented in `docs/AUTH_MODULE.md` §5) — not a dashboard/business-module change.

## 11. Responsive strategy

Verify at 320px, 375px, 430px, tablet (`sm`/`md`), laptop (`lg`), and large desktop (`xl`+) for every page:
- Split layout collapses to single column below `lg:` (branding panel `hidden lg:flex`), matching the old app.
- Card never exceeds `max-w-[420px]` regardless of viewport width (avoids the "excessively wide desktop card" failure mode).
- `PasswordInput`'s toggle button is a real `min-w-11 min-h-11` tap target, positioned so it never overlaps input text at any width (right-padding on the input reserves space, not overlaid arbitrarily).
- Layout uses `min-h-dvh` (dynamic viewport height), not `min-h-screen`, so mobile on-screen keyboards don't push the submit button out of reach or cause layout jump.
- No fixed pixel heights on the card or panel; content-driven height throughout so a validation error appearing doesn't clip anything.

## 12. Accessibility strategy

- Every input keeps a real, visible `<Label for="...">` (already the case in the target scaffolding) — never placeholder-only labels.
- `FormField` wires `aria-invalid="true"` and `aria-describedby="{id}-error"` on the control whenever `form.errors[field]` is set; `InputError` renders with that matching `id` and `role="alert"`.
- `PasswordInput`'s toggle is a real `<button type="button">` with `aria-label="Show password"/"Hide password"` (translated string, updated based on state) and `aria-pressed`.
- `AuthStatusMessage` uses `role="status" aria-live="polite"` for success/info, `role="alert" aria-live="assertive"` for error variants.
- Buttons keep `type="submit"`/`type="button"` explicitly (already correct in the existing pages) — no clickable `<div>`s anywhere in this scope.
- Heading hierarchy: layout renders the page `title` as a single `<h1>` (already the case in `AuthSplitLayout`), pages never introduce a competing `h1`.
- Landmarks: layout's outer element becomes `<main>` (currently a plain `<div>`) so the auth flow has a proper landmark; the branding-panel side becomes `aria-hidden="true"` on narrow viewports implicitly via `hidden`, and is decorative-only (`role="presentation"` or no redundant text duplication) on wide viewports.
- Focus rings: already provided by the existing `focus-visible:ring-2` utility on `Input`/`Button`/`Checkbox` — kept, not overridden.

## 13. Branding/assets migration

- **Colors**: add LeasyBack's 5 brand tokens as **new, additively-named** CSS variables in `resources/css/app.css` (e.g. `--brand-teal: #10393b`, `--brand-orange: #ef8450`, `--brand-green: #01b990`, `--brand-green-gray: #b7c2c2`, `--brand-black: #2e3e3f`), used only inside `components/auth/*` and the auth layout. **The existing global `--primary`/`--color-primary` tokens (generic blue) are left untouched** — changing them would restyle every button/link/focus-ring app-wide, including the dashboard and sidebar, which is out of scope. This is the concrete way to bring brand identity to auth without a wider blast radius; flagged here for explicit sign-off since it's a real design decision, not purely mechanical.
- **Logo**: copy `leasyback-logo.svg` (and the dark variant if needed for a light-background spot) from `leasyback_web/src/assets/logo/` into `resources/js/../public` or `resources/images` (confirm target app's asset convention during Checkpoint 2) and use it in place of the generic `AppLogoIcon` inside the auth layout only — `AppLogoIcon` itself is left untouched since it's used elsewhere (dashboard header).
- **Decorative path SVGs** (`path-green.svg`, `path-orange.svg`): optional — nice-to-have visual parity, low priority, can be dropped without harming functionality if time-boxed.
- **Font**: old app uses Inter/Manrope; target app uses "Instrument Sans" app-wide. **Recommendation: do not change the global font** for the same blast-radius reason as colors — this is a decision to flag, not silently pick.

## 14. Backend/frontend contract mismatches & decisions (resolved at Checkpoint 0 review)

1. **Shared prop shape** (§10) — **Approved.** Apply the `HandleInertiaRequests.php` + `types/index.ts` trim in Checkpoint 2.
2. **Language/copy** — **Resolved: German.** Old app is 100% German, target scaffolding is 100% generic English SaaS copy, neither has i18n. Replace the English placeholder strings with the equivalent German copy from the old app; no i18n library added.
3. **Password complexity guidance** — **Resolved: match backend reality.** Old app enforces (client-side only) uppercase + special-char rules beyond the backend's `min:8`. The new `PasswordRequirements` hint reflects only the *actual* backend rule (`min:8`) — showing a hint that promises a rule the server doesn't check would be actively misleading.
4. **Branding colors/font vs. global theme** (§13) — **Resolved: scoped brand tokens for auth only**, app-wide theme/font untouched.
5. **`ForgotPassword`'s old-app page was never functional** (simulated only) — nothing to "match" behaviorally; the target's already-working `password.email` flow is simply the correct implementation and needs no behavioral compromise for parity.

## 15. Files to create

- `resources/js/components/ui/password-input/PasswordInput.vue` (+ `index.ts` if the project's per-component `index.ts` re-export convention is followed, matching `ui/input`, `ui/checkbox`, etc.)
- `resources/js/components/form/FormField.vue`
- `resources/js/components/auth/AuthStatusMessage.vue`
- `resources/js/components/auth/PasswordRequirements.vue`
- `docs/AUTH_FRONTEND_MODULE.md` (at completion)
- `docs/AUTH_FRONTEND_REPORT.md` (at completion)

## 16. Files to modify

- `resources/js/pages/auth/Login.vue`, `Register.vue`, `ForgotPassword.vue`, `ResetPassword.vue`, `ConfirmPassword.vue`, `VerifyEmail.vue` — adopt `FormField`/`PasswordInput`/`AuthStatusMessage`, branding.
- `resources/js/layouts/AuthLayout.vue` — delegate to `AuthSplitLayout` instead of `AuthSimpleLayout`.
- `resources/js/layouts/auth/AuthSplitLayout.vue` — real branding (logo, copy, brand-teal panel) instead of generic name/quote.
- `resources/js/components/ui/input/Input.vue` — add `aria-invalid`/`aria-describedby` passthrough.
- `resources/js/components/ui/button/Button.vue` — add `loading` prop.
- `resources/js/components/InputError.vue` — add `id` prop + `role="alert"`.
- `resources/css/app.css` — add scoped brand color tokens.
- `app/Http/Middleware/HandleInertiaRequests.php` — trim shared `auth.user` shape (§10; requires sign-off).
- `resources/js/types/index.ts` — trim `User` interface to match (§10).
- `docs/AUTH_MODULE.md` — update §5/§6 if the shared-prop shape changes (cross-reference).

## 17. Files to retire or ignore

- Nothing is deleted this round. `AuthSimpleLayout.vue`/`AuthCardLayout.vue` are kept unused-but-present; a safety check on whether anything else references them happens in Checkpoint 6, not before.
- The entire `leasyback_web` app is a separate repository/reference only — nothing there is ever modified or deleted as part of this work.

## 18. Ordered implementation checkpoints

Matches the brief exactly:

- **Checkpoint 0** (this document) — inspection + plan. ✅ Done, awaiting approval.
- **Checkpoint 1** — Reusable UI primitives: `PasswordInput`, `FormField`, `Button` `loading` prop, `InputError` `id`/`role` additions. No page rewrites yet.
- **Checkpoint 2** — `AuthSplitLayout` branding, brand color tokens, logo asset migration, `types/index.ts` + `HandleInertiaRequests.php` shared-prop trim (pending sign-off from §14.1).
- **Checkpoint 3** — Login + Register pages: adopt the new primitives/layout, German copy (pending §14.2 decision), full responsive/accessibility pass.
- **Checkpoint 4** — ForgotPassword, ResetPassword, ConfirmPassword.
- **Checkpoint 5** — VerifyEmail + edge states (logout trigger, inactive-account message already generic-by-design, rate-limit/expired-session behavior confirmed to already redirect/422 correctly with no extra frontend handling needed).
- **Checkpoint 6** — Final pass: `npm run build`, `php artisan test`, `vendor/bin/pint --dirty --test`, manual mobile/desktop check across all 6 pages, `docs/AUTH_FRONTEND_MODULE.md` + `docs/AUTH_FRONTEND_REPORT.md`.

Each checkpoint stops for review before the next begins, per the brief.

---

## Report

**1. Old auth pages found:** Login, Register (role+email+password, no name field), and a non-functional Forgot-Password stub. `B2CRegistrationView`/`RegisterCompanyView`/`RegisterWorkshopView` are business-onboarding wizards, not core auth, and are out of scope.

**2. Target auth pages found:** All 6 (Login, Register, ForgotPassword, ResetPassword, ConfirmPassword, VerifyEmail) already exist and are already functional, session-based, and architecturally correct (Inertia `useForm()`, no Axios/Sanctum/localStorage). Verification is genuinely enforced (`/dashboard` requires it). This checkpoint set is about componentization, branding, and accessibility/responsive polish — not building auth mechanics from scratch.

**3. Reusable components proposed:** `PasswordInput` (new), `FormField` (new), `AuthStatusMessage` (new, auth-specific), `PasswordRequirements` (new, auth-specific, non-authoritative), plus small additive changes to the existing `Input`, `Button`, `InputError`.

**4. Files to create:** listed in §15 (4 components + 2 end-of-work docs).

**5. Files to modify:** listed in §16 (6 pages, 2 layouts, 3 existing components, 1 CSS file, 1 backend middleware, 1 types file, 1 doc cross-reference).

**6. Backend/frontend mismatches:** shared `auth.user` prop currently leaks the internal bigint `id` and unnecessary timestamps while omitting the useful `user_type` (§10) — proposed a small, additive fix. No UUID is invented for the missing public-identifier gap.

**7. Branding/assets to migrate:** 5 brand color tokens, LeasyBack logo SVG, optionally the two decorative path SVGs; recommended as auth-scoped tokens rather than a global retheme.

**8. Risks:** (a) language/copy decision (German vs. English) directly affects every page's text — needs a decision before Checkpoint 3; (b) no frontend test tooling exists, so verification is build + backend Feature tests + manual viewport checks, not automated component tests; (c) global-theme vs. scoped-brand-tokens is a real visual-scope decision, not purely mechanical.

**9. Implementation checkpoints:** as listed in §18 above (1 through 6, each stopping for review).

**10. Decisions — resolved:** German copy; apply the shared-prop trim; auth-scoped brand tokens (global theme/font untouched); password hint matches backend reality (`min:8` only). See the "Locked decisions" block at the top of this document.

Checkpoint 0 approved. No frontend or backend files have been modified yet — Checkpoint 1 begins on request.

---

## Checkpoint Log

### Checkpoint 1 — Reusable UI primitives (complete)

**Created:**
- `resources/js/components/form/FormField.vue` — structural label + control-slot + hint + error composition. Uses Vue 3.5's `useId()` for an SSR-safe auto-generated id when the caller doesn't supply one. Exposes a scoped slot (`{ id, describedBy, invalid }`) so the caller's own control (`Input`, `PasswordInput`, `Checkbox`, ...) stays correctly wired via `aria-describedby`/`aria-invalid` without `FormField` dictating what that control is. Required-field marker uses a visible `aria-hidden` asterisk plus a `sr-only` "(erforderlich)" text, since an asterisk alone isn't reliably announced.
- `resources/js/components/ui/password-input/PasswordInput.vue` (+ `index.ts`, matching the existing per-component re-export convention) — wraps the existing `Input`, forwards all unrecognized attrs (`id`, `autocomplete`, `required`, `aria-*`, etc.) straight through via `useAttrs()` + `inheritAttrs: false`, and adds a real `<button type="button">` toggle (not a clickable icon/div) with `aria-label`/`aria-pressed`, a 44px (`w-11 h-11`) touch target, and `pr-11` reserved on the input so the toggle never overlaps text at any width.

**Modified (small, additive — no existing page behavior changed, since no pages use these yet):**
- `resources/js/components/ui/input/Input.vue` — added Tailwind `aria-[invalid=true]:*` styling. No new prop was needed: `aria-invalid`/`aria-describedby`/`id`/etc. already flow through automatically via Vue's attribute fallthrough (single root element, `inheritAttrs` not disabled) — confirmed by inspection rather than assumed. The gap was purely visual (no error-state styling existed), now fixed by keying off the real `aria-invalid` DOM attribute instead of adding a redundant boolean prop.
- `resources/js/components/ui/button/Button.vue` — added `loading` (shows the existing `LoaderCircle` spinner, sets `aria-busy`) and made `disabled` an explicit prop so `loading` correctly implies disabled (blocks duplicate submission) even when the caller also passes its own `:disabled`. `buttonVariants`' existing `[&_svg]:size-4` rule already sizes the spinner correctly with no extra CSS.
- `resources/js/components/InputError.vue` — added an `id` prop and `role="alert"` so `FormField` can associate it via `aria-describedby`. No visual change.

**Not changed:** `Checkbox`, `Label`, `TextLink` — confirmed adequate as-is, no modification needed this checkpoint. No auth pages were touched (`AuthStatusMessage`/`PasswordRequirements` and actual page adoption are Checkpoint 2/3 per the plan).

**Verification:**
- `npm run build` — succeeded, 2546 modules transformed, no errors.
- `npm run lint` (ESLint) — clean, no errors/warnings on the new or modified files.
- `npx prettier --check` on all 6 touched files — clean.
- `php artisan test --compact` — 99 passed, 4 skipped (Postgres-only, correctly skipped on sqlite), 0 failed — unchanged from before this checkpoint, as expected (no backend files touched).
- `vendor/bin/pint --dirty --test` — passed (no PHP files were modified this checkpoint).
- No automated component-level tests were run because none exist for this project (`package.json` has no vitest/@vue/test-utils/playwright) — confirmed absent during Checkpoint 0, not newly discovered. Manual visual verification of these primitives happens once pages adopt them (Checkpoint 3+); in isolation there's no page to render them on yet.

### Checkpoint 2 — Auth layout, shared architecture, branding (complete)

**Plan deviation found and corrected before implementing:** the Checkpoint 0 plan proposed trimming the shared `auth.user` prop to `{name, email, user_type}`. Before touching `HandleInertiaRequests.php`, grepped every actual usage of `auth.user`/`page.props.auth` across `resources/js` (not just the auth pages) and found two real, out-of-scope usages the original plan missed: `Settings/Profile.vue` reads `user.email_verified_at` for its verify-email banner, and `AppHeader.vue`/`UserInfo.vue` read `user.avatar` for the avatar-fallback pattern. Applying the originally-planned trim as written would have silently broken both. Revised shape, applied instead: keep `email_verified_at` (genuinely used); keep `avatar` **type-only** (optional, so `UserInfo.vue` keeps compiling and behaving exactly as today — it was never actually populated with real data even before this change, since the raw model only ever exposed `avatar_path`, a private storage path, under that name, never `avatar`); drop `id`/`created_at`/`updated_at` (confirmed unused anywhere in `resources/js`); add `user_type` (new, per the locked decision).

**Modified:**
- `app/Http/Middleware/HandleInertiaRequests.php` — `auth.user` is now an explicit array (`name`, `email`, `email_verified_at`, `user_type`) instead of the raw Eloquent model, per the revised shape above.
- `resources/js/types/index.ts` — `User` interface updated to match; imports the new `UserType` union.
- `resources/js/layouts/AuthLayout.vue` — now delegates to `AuthSplitLayout` instead of `AuthSimpleLayout`.
- `resources/js/layouts/auth/AuthSplitLayout.vue` — rebuilt with real LeasyBack branding: form card (left) + `bg-brand-teal` panel with the real logo and the old app's German marketing copy (right, `lg:` and up only); mobile shows a small logo above the form instead. Changed `h-dvh` → `min-h-dvh` and outer `<div>` → `<main>` (landmark) per the plan's responsive/accessibility strategy. `AuthSimpleLayout.vue`/`AuthCardLayout.vue` left untouched, per plan.
- `resources/css/app.css` — added 5 brand tokens (`--brand-teal`, `--brand-orange`, `--brand-green`, `--brand-green-gray`, `--brand-black`) plus their `@theme inline` mappings, generating `bg-brand-teal`/`text-brand-orange`/etc. utilities. Scoped: the existing `--primary`/theme tokens and font are untouched, so nothing outside auth is restyled.

**Created:**
- `resources/js/types/auth.ts` — `UserType` string-literal union mirroring `App\Enums\UserType`, including the real `'Werksatatt'` value (the backend's existing typo, intentionally preserved rather than silently "corrected" on the frontend).
- `resources/js/components/auth/AuthStatusMessage.vue` — the green/red/muted status-banner pattern currently hand-copied in `Login`/`ForgotPassword`/`VerifyEmail`, with `role="status"`/`role="alert"` + matching `aria-live` by variant. Not yet wired into any page (page adoption is Checkpoints 3–5).
- `resources/js/components/auth/PasswordRequirements.vue` — static "Mindestens 8 Zeichen." hint, matching the locked decision (backend reality, not the old app's stricter unenforced rule). Not yet wired into any page.
- `public/leasyback-logo.svg`, `public/leasyback-logo-dark.svg` — copied from the old app's asset folder, used by the new `AuthSplitLayout`.
- `tests/Feature/HandleInertiaRequestsTest.php` — 2 new tests: asserts the trimmed shape for an authenticated user (present: name/email/user_type/email_verified_at; absent: id/avatar/created_at/updated_at) and that `auth.user` is `null` for a guest.

**Not changed:** `public/logo.svg` — a pre-existing, unrelated, unused asset (different dimensions/viewBox than LeasyBack's logo, likely generic starter-kit placeholder) — left alone rather than guessed at or overwritten. `name`/`quote` shared props in `HandleInertiaRequests` are now unused by anything (the old `AuthSplitLayout` was their only consumer) but were left in place — removing them wasn't asked for in this checkpoint and is out of scope.

**Verification:**
- `npm run build` — succeeded after each change (types, layout, CSS, components).
- `npm run lint` (ESLint) — clean.
- `npx prettier --check` — clean on all touched/created files (2 files needed `--write` for Tailwind class-order sorting, then verified clean).
- `php artisan test --compact` — **101 passed** (up from 99 — the 2 new `HandleInertiaRequestsTest` cases), 4 skipped, 0 failed.
- `vendor/bin/pint --dirty --test` — passed.
- **Manual verification, honestly scoped:** started a local dev server and confirmed via HTTP: `/login` returns 200, the Inertia JSON payload correctly resolves to the `auth/Login` component with `auth.user: null` for a guest, and both new logo assets serve with 200. Attempted a real visual/browser check (screenshot at mobile/desktop widths) via the Chrome browser tool, but the browser extension was not connected in this environment, so **the actual rendered visual appearance (brand colors, logo placement, responsive layout) has not been confirmed by eye** — only by source review and the HTTP/JSON checks above. This is flagged explicitly rather than implied; a real visual check is recommended before treating the branding as final, and should happen in Checkpoint 6's manual pass at the latest (or sooner if a browser workflow becomes available).
- Discovered and fixed, unrelated to this checkpoint's code: the local dev SQLite database hadn't been migrated (`no such table: sessions`) — a pre-existing environment gap, not caused by this work; resolved with `php artisan migrate`.

### Checkpoint 3 — Login and Register pages (complete)

**Modified:**
- `resources/js/pages/auth/Login.vue` — rewritten to use `FormField` (email, password) and `PasswordInput`, `AuthStatusMessage` for the `status` banner, `Button`'s new `loading` prop instead of a hand-copied `LoaderCircle`. All copy switched to German (locked decision): title "Anmelden", "E-Mail-Adresse", "Passwort", "Angemeldet bleiben" (remember me), "Passwort vergessen?", "Anmelden" (submit), "Sie sind noch kein Kunde bei uns? Hier registrieren" (matching the old app's exact footer wording). Behavior unchanged: still `POST /login` via `useForm()`, still `form.reset('password')` on finish, still session/CSRF auth throughout — no mechanism was touched, only markup/copy.
- `resources/js/pages/auth/Register.vue` — same treatment: `FormField` for all 4 fields (name, email, password, password confirmation), `PasswordInput` for both password fields, `PasswordRequirements` under the password field (wired into `aria-describedby` alongside the error, so screen readers get both). German copy: "Konto erstellen", "Passwort bestätigen", "Sie haben bereits ein Konto? Zum Login". Fields/payload unchanged (`name`, `email`, `password`, `password_confirmation` — no `user_type` selector, matching the locked product rule that public registration always defaults to Privatkunde server-side).

**Small correction made mid-implementation:** first draft of `FormField` added a `label-end` slot (for Login's "forgot password?" link next to the password label), but a `flex justify-between` row with only one child (the slot) doesn't right-align it — `justify-between` needs two items to space apart. Caught this before it shipped by mentally tracing the render, reverted the slot addition, and instead composed the label+link row directly inside `FormField`'s existing default (scoped) slot for that one field — no template addition to `FormField` was actually needed for this case, keeping it simpler than first planned.

**Verification:**
- `npm run build` — succeeded; `PasswordInput.vue` and `FormField.vue` now appear as their own chunks (confirms they're actually wired into pages, not just built in isolation like Checkpoint 1).
- `npm run lint` (ESLint) — clean.
- `npx prettier --check` — clean (2 files needed `--write` for Tailwind class-order, then verified).
- `php artisan test --compact` — 101 passed, 4 skipped, 0 failed (unchanged — no backend files touched this checkpoint, confirms the existing `Auth/AuthenticationTest.php`/`Auth/RegistrationTest.php` Feature tests, which exercise the real `POST /login`/`POST /register` routes, still pass against the untouched backend).
- `vendor/bin/pint --dirty --test` — passed (no PHP touched).
- Manual HTTP check: `/login` and `/register` both return 200 and resolve to the correct Inertia components (`auth/Login`, `auth/Register`).
- **Same honest gap as Checkpoint 2**: no real visual/screenshot verification was possible (Chrome browser tool still not connected in this environment). The actual rendered appearance of the new `FormField`/`PasswordInput` composition — spacing, the password-toggle button's position, responsive behavior — has not been confirmed by eye, only by source review, `npm run build` succeeding, and the HTTP/component checks above. Still recommending a real visual pass before Checkpoint 6 signs off.

### Checkpoint 4 — Password flows (complete)

**Modified:**
- `resources/js/pages/auth/ForgotPassword.vue` — `FormField` for the email field, `AuthStatusMessage` for the `status` banner, `Button`'s `loading` prop. Fixed `autocomplete="off"` → `autocomplete="email"` in passing (inconsistent with every other email field in the app, which all correctly use `autocomplete="email"` — a small, obviously-safe fix directly on a line already being touched, not a drive-by change elsewhere). German copy: "Passwort zurücksetzen", "Link zum Zurücksetzen senden", "Oder zurück zum Login". No account-existence leak preserved — still a generic `status` message either way, unchanged from before.
- `resources/js/pages/auth/ResetPassword.vue` — `FormField` for all 3 fields, `PasswordInput` for both password fields, `PasswordRequirements` added under the new-password field (this page also sets a new password, so the same `min:8` guidance applies here as on Register). The `token` is still never rendered as visible text — only carried in `form.token`, submitted silently in the POST body. Email field stays `readonly`, unchanged. German copy: "Neues Passwort festlegen", "Neues Passwort", "Passwort bestätigen".
- `resources/js/pages/auth/ConfirmPassword.vue` — `FormField` + `PasswordInput` for the single password field. This also fixed a real pre-existing bug spotted during Checkpoint 0's inventory: the old markup used `<Label htmlFor="password">` (a React-ism; Vue's native attribute is `for`, so this label was never actually associated with its input) — replaced entirely by `FormField`'s own correctly-`for`-wired `Label`, so this is fixed as a side effect of the componentization rather than a separate drive-by edit. German copy: "Passwort bestätigen" (title and button), description translated.

**Minor, deliberate deviation from the old Breeze scaffolding:** dropped the `name="email"`/`name="password"` HTML attributes that existed on these three pages (they were never present on Login/Register to begin with, and have no functional effect here since Inertia's `useForm()` submits via XHR, not native form submission — `autocomplete`, which is preserved and correct on every field, is what actually drives browser/password-manager autofill behavior). Done for consistency: every auth page now follows the same convention rather than mixing two.

**Verification:**
- `npm run build` — succeeded.
- `npm run lint` (ESLint) — clean.
- `npx prettier --check` — clean (1 file needed `--write` for line-wrap formatting).
- `php artisan test --compact` — 101 passed, 4 skipped, 0 failed (unchanged — no backend files touched).
- `vendor/bin/pint --dirty --test` — passed.
- Manual HTTP check: `/forgot-password` → 200, resolves to `auth/ForgotPassword`; `/reset-password/{token}?email=...` → 200, resolves to `auth/ResetPassword`; `/confirm-password` → 302 (correctly redirects a guest to login — this route requires an authenticated session, so a 302 here is the *expected*, correct result, not a failure). Verifying `ConfirmPassword`'s authenticated rendering would require a real logged-in session, which wasn't set up via curl — not verified this checkpoint.
- **Same honest visual-verification gap as Checkpoints 2–3**: still no working browser tool in this environment. Rendered appearance unverified by eye.

### Checkpoint 5 — VerifyEmail + edge states (complete)

**`resources/js/pages/auth/VerifyEmail.vue` modified:** `AuthStatusMessage` for the "verification link sent" banner, `Button`'s `loading` prop. German copy: "E-Mail-Adresse bestätigen", "Bestätigungslink erneut senden", "Abmelden". The logout link stays exactly as it was (`TextLink` rendered `as="button"`, `method="post"` — a real `<button>` triggering an Inertia POST, not a clickable non-interactive element).

**Three real, pre-existing bugs found and fixed during this checkpoint's edge-state review** — not new features, not scope creep: this app has no `lang/` directory (confirmed absent in Checkpoint 0), and several backend files called `trans()`/`__()` on raw Laravel translation keys expecting one to exist. Without a matching translation, `trans()`/`__()` returns the key **verbatim** — so real users were hitting literal broken strings on exactly the pages this checkpoint set out to review ("rate-limit response", "password-reset status", "expired reset link" are called out explicitly in the brief's error-handling section). Found by reasoning through what `Auth\LoginRequest` and the password-reset controllers actually return, then confirmed via the existing test suite (no test asserted message *content*, only presence — so nothing had caught this before):

1. **`app/Http/Requests/Auth/LoginRequest.php`** — wrong password/deactivated-account failure used `trans('auth.failed')` → was rendering as the literal string `"auth.failed"`. Fixed to a real German message: "Diese Anmeldedaten stimmen nicht mit unseren Aufzeichnungen überein."
2. **Same file** — rate-limit lockout used `trans('auth.throttle', ['seconds' => ..., 'minutes' => ...])` → was rendering as the literal string `"auth.throttle"` with the placeholders never substituted (substitution only happens against a *found* translation line, never the fallback key itself). Fixed to `"Zu viele Anmeldeversuche. Bitte versuche es in {$seconds} Sekunden erneut."` — note the unused `minutes` value is also gone; the original stock Laravel translation this was copied from only ever used `:seconds` anyway.
3. **`app/Http/Controllers/Auth/NewPasswordController.php`** — the post-reset status and the invalid-token/invalid-user/throttled error messages used `__($status)` on `Password::PasswordReset`/`InvalidToken`/`InvalidUser`/`ResetThrottled` → same bug, e.g. a successful reset would have shown literal `"passwords.reset"`. Fixed with an explicit `match` over the real status constants, each mapped to a proper German message.
4. **`app/Http/Controllers/Auth/PasswordResetLinkController.php`** — the "reset link sent" status was already a hardcoded (not translation-key) string, just in English and wrapped in a no-op `__()`. Translated to German, preserving its already-correct enumeration-safe phrasing ("sent if the account exists" — not "sent" unconditionally).
5. **`app/Http/Middleware/EnsureUserIsActive.php`** — the web-session branch's deactivated-account redirect message was hardcoded English, which would now sit inside an otherwise fully-German login page. Translated to "Dieser Account wurde deaktiviert." The Sanctum/API branch's JSON message was deliberately left in English — that's the external API's own contract (documented in `docs/AUTH_MODULE.md`), a separate surface with its own consistently-English error envelope; changing just one message there would have made the API *less* consistent, not more.

**Tests updated/added to lock in these fixes** (existing test files extended, no new test files):
- `tests/Feature/Auth/AuthenticationTest.php` — `test_users_can_not_authenticate_with_invalid_password` and `test_deactivated_user_cannot_authenticate_via_the_login_screen` now assert the real German message instead of only presence; `test_login_is_rate_limited_after_too_many_failed_attempts` asserts the message's fixed prefix (the exact seconds-remaining count is timing-dependent, so only the prefix is asserted, not the full string); `test_deactivated_user_is_logged_out_of_an_existing_session` now also asserts the new German status message.
- `tests/Feature/Auth/PasswordResetTest.php` — `test_password_reset_request_gives_a_generic_response_for_a_nonexistent_email` updated to the new German status string; `test_password_can_be_reset_with_valid_token` now also asserts the success status message; `test_password_reset_fails_with_an_invalid_token` and `test_password_reset_fails_with_an_expired_token` now assert the specific German error message instead of only presence.

**Verification:**
- `npm run build` — succeeded.
- `npm run lint` (ESLint) — clean.
- `npx prettier --check` — clean.
- `php artisan test --compact` — **101 passed** (251 assertions, up from 246 — the strengthened assertions above; no new test methods were added, existing ones were made stricter), 4 skipped, 0 failed.
- `vendor/bin/pint --dirty --format agent` — auto-fixed `fully_qualified_strict_types`/`ordered_imports` on the two password controllers, then reverified clean with `--test`.
- Manual HTTP check: `/verify-email` → 302 for a guest (correct — requires `auth`+`active`); the existing `test_email_verification_screen_can_be_rendered` (part of the 101 passing) already covers the authenticated 200 case.
- **Same honest visual-verification gap as every prior checkpoint**: still no working browser tool in this environment.

### Checkpoint 6 — Final review (complete)

**Unused old scaffolding:** confirmed via grep that `AuthSimpleLayout.vue`/`AuthCardLayout.vue` are referenced nowhere except an auto-generated `components.d.ts` type file (not a real usage). Decision: **kept, not deleted** — they're harmless, zero-maintenance, and deleting pre-existing framework scaffolding for no functional benefit isn't a compelling enough reason to take an irreversible-in-spirit action.

**Visual verification — finally closed the gap flagged in every prior checkpoint.** The Chrome extension was still not connected, but a real Chrome binary was available on the machine, so headless screenshots were used instead (`chrome.exe --headless=new --screenshot`). This surfaced a real investigation worth recording honestly:

1. An initial mobile screenshot at `--window-size=390,844` showed every page's content cut off at the right edge (labels, links, and footer text all clipped identically, every time). This looked like a genuine horizontal-overflow bug.
2. Two follow-up fixes were tried (adding `min-w-0` to the layout's grid item to prevent a CSS grid auto-track blowout; then restructuring `<main>` to use flex instead of grid below `lg:`, plus `overflow-x-hidden`) — **neither changed the screenshot's output at all**, which was itself the tell that something was wrong with the *measurement*, not necessarily the page.
3. A tiny temporary diagnostic (`onMounted` writing `window.innerWidth` etc. into `document.title`, read back via `--dump-dom` with no browser extension or DevTools protocol required) revealed the actual rendered viewport was **~500px**, not the requested 390px — this specific Chrome installation enforces a hard ~500px minimum viewport width in headless mode. The screenshots had been correctly capturing a 390×844 *pixel crop* of a page that was genuinely laid out for ~500px — i.e., the content was never actually overflowing; the capture window was just smaller than the real viewport.
4. Confirmed by matching `--window-size` to the actual floor (500px): zero overflow, clean layout, at every page checked.
5. All temporary diagnostic code (the `onMounted` title-hijack, a bright-red canary background used to confirm changes were even being reflected) was removed. The `min-w-0`/`overflow-x-hidden` additions and Login's `flex-wrap` fix on the password/forgot-link row were **kept anyway** — not because they fixed a confirmed bug (there wasn't one), but because they're single-utility-class, zero-downside insurance directly matching the brief's own requirements (no horizontal overflow, long translated text doesn't break layout), and the flex/grid restructuring that added real complexity without a real payoff was reverted back to the simpler original `grid`-based structure.

**True visual confirmation obtained** (screenshots taken, viewed, and inspected) at the actual matched viewport (~500px "mobile", 1440-1462px desktop), in both light and dark color schemes, across Login, Register, and ResetPassword: correct branding (teal panel, real logo, orange/green accents), correct German copy throughout, `FormField`/`PasswordInput`/`PasswordRequirements` all rendering and positioned correctly, no horizontal overflow, dark mode (the app's pre-existing `prefers-color-scheme` support, not something built this project) renders legibly with the fixed brand-teal panel unaffected by the theme switch as intended.

**Known, honest limitation:** true narrow-mobile widths (320–430px, as the original brief asked to check) could not be captured via this Chrome installation's headless mode due to its ~500px floor — confirmed empirically, not assumed. The ~500px result is a reasonable proxy (small-tablet/large-phone-landscape range) and the CSS itself has no fixed pixel widths or non-wrapping constructs that would behave differently at 320–430px based on code review, but a literal screenshot at those exact widths was not obtained in this environment.

**Verification:**
- `npm run build` — succeeded.
- `npm run lint` (ESLint) — clean.
- `npx prettier --check` — clean on every file touched across all 6 checkpoints.
- `php artisan test --compact` — 101 passed, 4 skipped, 0 failed.
- `vendor/bin/pint --dirty --test` — passed.
- Real screenshots taken and visually inspected (see above) — the honest gap flagged in Checkpoints 2–5 is now closed, with the caveat noted above about the exact 320–430px range.
