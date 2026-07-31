# Auth Frontend Module

A ~10 minute orientation to the auth frontend. For backend architecture (routes, controllers, session vs. Sanctum), see `docs/AUTH_MODULE.md` — this file covers the Vue/Inertia side only.

## 1. Component architecture

Reusable UI primitives live in `resources/js/components/ui/*`, following the project's existing shadcn-vue/reka-ui convention (one folder per component, `index.ts` re-export). Auth-specific components live in `resources/js/components/auth/*`. Cross-cutting form structure lives in `resources/js/components/form/*`.

| Component | Location | Purpose |
|---|---|---|
| `Input` | `components/ui/input/Input.vue` | Base text input (pre-existing). Extended this work: `aria-[invalid=true]:*` error-state styling. |
| `PasswordInput` | `components/ui/password-input/PasswordInput.vue` | Wraps `Input`, adds an accessible show/hide toggle. Forwards all other attrs (`id`, `autocomplete`, `aria-*`, ...) straight through. |
| `Button` | `components/ui/button/Button.vue` | Base button (pre-existing, cva variants). Extended this work: `loading` prop (spinner + `aria-busy`, implies `disabled`). |
| `Checkbox`, `Label` | `components/ui/checkbox`, `components/ui/label` | Pre-existing, unchanged, reused as-is. |
| `InputError` | `components/InputError.vue` | Pre-existing. Extended: `id` prop + `role="alert"` so it can be wired via `aria-describedby`. |
| `FormField` | `components/form/FormField.vue` | Structural label + control-slot + hint + error composition. **Does not dictate control markup** — works uniformly with `Input`, `PasswordInput`, or anything else via a scoped slot. |
| `AuthStatusMessage` | `components/auth/AuthStatusMessage.vue` | The success/error/info status banner pattern (`role="status"`/`role="alert"` + matching `aria-live`). |
| `PasswordRequirements` | `components/auth/PasswordRequirements.vue` | Single source of truth for the non-authoritative password hint text. Must always match the actual backend rule (`min:8`) — never promise a rule the server doesn't enforce. |

## 2. Layouts

- `layouts/AuthLayout.vue` — thin dispatcher, delegates to `layouts/auth/AuthSplitLayout.vue`.
- `layouts/auth/AuthSplitLayout.vue` — the real layout: form card (left, `max-w-[420px]`) + `bg-brand-teal` branding panel with the LeasyBack logo and marketing copy (right, `lg:` and up only). Mobile shows a small logo above the form instead. Uses `min-h-dvh` (not `min-h-screen`) so a mobile on-screen keyboard doesn't clip the submit button, and `overflow-x-hidden` + `min-w-0` at the relevant grid/flex levels as cheap insurance against a very long unbroken string ever forcing horizontal scroll.
- `layouts/auth/AuthSimpleLayout.vue`, `layouts/auth/AuthCardLayout.vue` — pre-existing alternative layouts, confirmed unused by anything (checked via grep across `resources/js`, ignoring the auto-generated `components.d.ts` type-declaration file). Left in place rather than deleted: they're harmless, zero-maintenance, and a future page might reasonably want a plain centered-card layout instead of the branded split.

## 3. Pages

All 6 auth pages live in `resources/js/pages/auth/`: `Login.vue`, `Register.vue`, `ForgotPassword.vue`, `ResetPassword.vue`, `ConfirmPassword.vue`, `VerifyEmail.vue`. Every one of them was already using Inertia session auth (`useForm()` + named routes) before this work — nothing about *how* they authenticate was changed, only their markup, componentization, branding, and copy (see `docs/AUTH_FRONTEND_IMPLEMENTATION_PLAN.md` for the full history).

## 4. Inertia form conventions

Every page follows the same shape — no generic form abstraction wraps this, by design (a business-logic wrapper would hide what each page actually submits):

```ts
const form = useForm({ email: '', password: '' /* ... */ });

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'), // sensitive fields only
    });
};
```

- Sensitive fields (`password`, `password_confirmation`) are reset in `onFinish`, never retained longer than necessary.
- `form.errors.<field>` flows into `FormField`'s `error` prop; nothing parses or re-shapes Laravel's validation error format.
- Session/CSRF/redirect handling is entirely Inertia's default behavior — no custom fetch/axios/JSON error parsing exists or should be added for these routes (they redirect or return validation errors, never JSON).

## 5. Error handling

- **Validation (422):** rendered per-field via `FormField` → `InputError`, using Laravel's own error bag. No frontend duplication of backend validation rules.
- **Invalid credentials / deactivated account:** both produce the exact same generic message at login — by design, no enumeration. The frontend has no special case for "this account is deactivated"; it's indistinguishable from a wrong password on purpose.
- **Rate limiting, expired session, expired reset link:** all surface through the same `form.errors`/`status` mechanism already described — no custom handling was added because none was needed. (Checkpoint 5 found and fixed three real backend bugs in this area — see `docs/AUTH_FRONTEND_IMPLEMENTATION_PLAN.md`'s Checkpoint 5 log — but the frontend-side handling was already correct once the backend returned real text instead of raw translation keys.)

## 6. How to add another auth-adjacent page or field

1. Add the field to the page's `useForm({...})` call and its Form Request on the backend (see `docs/AUTH_MODULE.md`).
2. Wrap it in `<FormField :error="form.errors.x" label="...">`, using the scoped slot (`{ id, describedBy, invalid }`) to wire your control's `:id`, `:aria-invalid`, `:aria-describedby`.
3. Use `Input` or `PasswordInput` for the control unless there's a real reason for something else.
4. If it's a genuinely new page (not a field), give it its own `AuthLayout` wrapper with `title`/`description` props, matching the existing 6 pages' structure.
5. Keep copy German, matching the rest of the auth surface (no i18n library is installed — see the Locked Decisions in `docs/AUTH_FRONTEND_IMPLEMENTATION_PLAN.md`).
6. Run `npm run build`, `npm run lint`, `npx prettier --check`, and the relevant backend Feature tests.

## 7. Responsive rules

- Card is always `max-w-[420px]`, regardless of viewport — never let it stretch edge-to-edge on desktop or shrink illegibly on mobile.
- Use `min-h-dvh`, not `min-h-screen`/fixed heights — a validation error appearing or a mobile keyboard opening must never clip content.
- Any `flex justify-between` row with two text children (e.g. a label + a link) should get `flex-wrap` + a `gap`, not a bare `justify-between` — with `nowrap` (the default), long translated text or high browser zoom can force the row wider than its container since neither child is allowed to shrink below its own text's natural width.

## 8. Accessibility rules

- Every control has a real, visible `<Label>` — never a placeholder standing in for a label.
- `FormField` wires `aria-invalid`/`aria-describedby` automatically via its scoped slot; use them, don't hand-roll new ones.
- `PasswordInput`'s toggle is a real `<button type="button">` with `aria-label`/`aria-pressed`, not a bare icon.
- `AuthStatusMessage` picks `role="status"`/`aria-live="polite"` for success/info and `role="alert"`/`aria-live="assertive"` for errors — pass the right `variant`.
- No clickable non-interactive elements anywhere in this surface (`VerifyEmail`'s logout "link" is a real `<button>` via Inertia's `<Link as="button">`, not a styled `<div>`).
