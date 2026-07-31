# LeasyBack Landing Page — Design & Build Brief

Hand this whole document to the Claude session that will build the page, along with your visual inspiration (screenshot/reference site). It's self-contained — that Claude doesn't need anything else from this repo to get started, though it will need read/write access to the actual project files to implement it.

---

## 1. What LeasyBack is

LeasyBack is a German-market vehicle-leasing buyback / minderwert (diminished value) service. The core consumer pitch, already used verbatim on the existing auth pages:

> "Sicher dir jetzt dein kostenloses Minderwert-Gutachten, erhalte günstige **Reparaturangebote** und gib dein Leasingfahrzeug mit bis zu 42 % Ersparnis stressfrei zurück!"

("Get your free diminished-value assessment now, receive affordable repair offers, and hand back your leased vehicle stress-free with savings of up to 42%.")

There are three customer types the product serves: **Privatkunde** (private/individual customers — the primary audience), **Firmenkunde** (business customers), and **Werkstatt** (repair workshops, a partner/supply-side audience). The landing page's primary audience is almost certainly Privatkunde, with the door open to Firmenkunde.

**All copy must be in German**, matching the tone already established elsewhere in the app: direct, benefit-led, confident, not overly formal corporate-speak, but not casual/slangy either.

---

## 2. Tech stack (must build within this)

- **Laravel 12** (PHP 8.4) + **Inertia.js v2** + **Vue 3** (`<script setup lang="ts">` everywhere, no Options API)
- **Tailwind CSS v4** (config lives in CSS via `@theme`, not a `tailwind.config.js` file)
- Component library already installed: **reka-ui** (headless primitives) wrapped in a **shadcn-vue-style** component set under `resources/js/components/ui/*`
- Icons: **lucide-vue-next** — this is the only icon library in the project. Don't add a second one (no Font Awesome, no Heroicons, no @iconify).
- No animation library is installed (no GSAP, no Framer Motion equivalent, no AOS). If you want scroll/entrance animations, use Tailwind's built-in transition utilities or plain CSS — don't add a new dependency for this unless there's a strong reason.
- Routing: Ziggy (`route('name')` helper is available globally in every Vue page/component, backed by Laravel's named routes)

### Where this page lives

- Route: `Route::get('/', ...)->name('home')` in `routes/web.php` (already exists, don't touch the route itself)
- Page component: `resources/js/pages/Welcome.vue` — **this currently contains the generic, unbranded Laravel starter-kit placeholder** (a big black Laravel logo, "Let's get started", links to Laravel docs/Laracasts). It needs to be **fully replaced**, not incrementally edited.
- If the page benefits from being split into pieces (Hero, Features, Testimonials, CTA, Footer, etc.), create new components under a new `resources/js/components/landing/` directory. Don't force everything into one giant file, but also don't over-fragment a simple page — use judgment based on how much content the actual design has.

### What NOT to touch

- Don't modify `routes/web.php`'s route definition, `app/Http/Controllers`, or anything under `resources/js/pages/auth/*`, `resources/js/pages/settings/*`, `resources/js/pages/Dashboard.vue`, or any business-module code. This is a single-page, frontend-only addition.
- Don't touch `resources/js/layouts/AuthLayout.vue` or the auth split-layout — the landing page is a **public marketing page**, not part of the auth flow, and shouldn't reuse that layout (it's the wrong shape: two-column split screen designed for a form card, not a full-width landing page).
- Don't add a second UI/icon/animation library.
- Dark mode is currently **disabled app-wide** (forced to light only, on purpose — see `resources/js/composables/useAppearance.ts`). Don't add `dark:` variants for this page; it only needs to look correct in light mode right now.

---

## 3. Brand colors

These exact tokens already exist in `resources/css/app.css` under `@theme inline` — **reuse them, don't invent new hex values or duplicate them under different names.**

| Token (Tailwind class) | Hex | Role |
|---|---|---|
| `brand-teal` (`bg-brand-teal`, `text-brand-teal`) | `#10393B` | Primary brand color — dark teal. Used for headings, the branding panel background on auth pages, and should anchor this page's primary UI chrome (e.g. header background, key headings, primary dark sections). |
| `brand-orange` (`bg-brand-orange`, `text-brand-orange`) | `#EF8450` | Call-to-action color. Every button that means "take the next step" (primary CTAs, "Jetzt starten", "Kostenlos registrieren") uses this. Also used inline to highlight a key word in body copy (see the pitch sentence above — "Reparaturangebote" is styled `text-brand-orange` inside an otherwise white/dark paragraph). |
| `brand-green` (`bg-brand-green`, `text-brand-green`) | `#01B990` | Secondary accent — used for secondary links/actions (e.g. "Passwort vergessen?" style links) and success states. Also appears in the wordmark itself (the "Back" half of the "LeasyBack" logotype is this green). |
| `brand-green-gray` (`border-brand-green-gray`, `text-brand-green-gray`) | `#B7C2C2` | Neutral border/muted-text color — input borders, subtle hint text, dividers. |
| `brand-black` (`text-brand-black`) | `#2E3E3F` | Primary body-text color (not pure black — a soft near-black with a slight teal cast). |

There's also a soft off-white background used specifically for the auth pages (`#FAFAFA`, applied as a literal `style="background-color: #fafafa"` in `AuthSplitLayout.vue`, not yet promoted to a token) — reasonable to reuse for this page's light background sections too, or just use Tailwind's `bg-white`, whichever suits the actual design better.

**Do not use** the generic `--color-primary` / `--color-secondary` / `--color-accent` tokens also defined in `app.css` — those are a leftover generic blue theme used by the dashboard/sidebar and are **not** the LeasyBack brand color. If you see a blue button anywhere while building, that's the wrong token.

### Logo & decorative assets (already in `public/`, reference by absolute path e.g. `/leasyback-logo.svg`)

- `/leasyback-logo.svg` — full-color wordmark ("Leasy" in white/dark, "Back" in `brand-green`), designed for use **on a dark (`brand-teal`) background**.
- `/leasyback-logo-dark.svg` — same wordmark, designed for use on a **light background**.
- `/path-green.svg`, `/path-orange.svg` — thin decorative diagonal dashed-line SVGs used as subtle background flourishes on the auth pages (absolutely positioned, low opacity on the orange one). Reuse these on the landing page too if it suits the design (they're the closest thing this brand has to an established "decorative motif") — but don't feel obligated to force them in if your inspiration design doesn't call for it.

---

## 4. Typography

Current app-wide font: **Instrument Sans**, loaded via Bunny Fonts (a privacy-friendly Google Fonts mirror) in `resources/views/app.blade.php`:

```html
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet">
```

Weights loaded: 400 (regular), 500 (medium), 600 (semibold). If your landing page design needs a bolder weight (700/800 for a big hero headline), add it to that same Bunny Fonts URL rather than loading a whole separate font — e.g. `instrument-sans:400,500,600,700`.

If your inspiration design specifically calls for a different typeface (e.g. something more distinctive for a marketing page vs. the app's UI font), that's a legitimate call to make — just flag it as a deliberate choice rather than silently diverging, and prefer Bunny Fonts (same privacy/licensing model already in use) over Google Fonts directly if you do swap it.

---

## 5. Design language already established (match this, don't reinvent)

The auth pages (Login, Register, etc. — see `resources/js/pages/auth/*` and `resources/js/layouts/auth/AuthSplitLayout.vue`) already established a concrete visual language for this brand. The landing page should feel like the **same product**, not a different one:

- **Buttons/CTAs**: `rounded-[5px]` (a tight, small radius — not the generic shadcn `--radius` of 0.75rem used elsewhere), `bg-brand-orange`, bold text, generous padding (`py-3`), full-width on mobile.
- **Cards/panels**: white background, `border-brand-green-gray`, soft shadow (`shadow-[0_4px_4px_rgba(0,0,0,0.25)]`), `rounded-[10px]`.
- **Links**: secondary/tertiary links use `brand-green`, bold, underlined with a thin underline (`underline decoration-[1.12px] underline-offset-[2.8px]`).
- **Headings**: bold, `brand-teal` or `brand-black` depending on background.

The existing `Button` component (`resources/js/components/ui/button/Button.vue`) supports a `loading` prop and `class` overrides — reuse it with a `class="bg-brand-orange ..."` override (see any auth page for the exact pattern) rather than writing a raw `<button>`, so form-related buttons stay consistent. For a landing page's own new components (hero, feature cards, etc.), use your judgment on whether to reuse existing primitives (`Input`, `Button`) or write plain marked-up HTML — landing pages often have one-off visual treatments that don't need a shared component.

---

## 6. Responsive & accessibility bar (non-negotiable, matches the rest of the app)

- No horizontal overflow at any width, including narrow phones (~360–430px) — test this specifically; a CSS grid/flex "blowout" from long unwrapped text is a real, easy-to-hit bug in this codebase (it happened once already on the auth pages).
- Real semantic HTML: one `<h1>`, sensible heading hierarchy after it, `<nav>`/`<main>`/`<footer>` landmarks, no clickable `<div>`s (use `<button>` or Inertia's `<Link>`).
- Visible focus states on every interactive element (the shared `Button`/`Input` components already have this via `focus-visible:ring-2` — preserve it if you build custom interactive elements).
- Images need real `alt` text (or `alt=""` if purely decorative, like the path SVGs).

---

## 7. Verification before calling it done

```bash
npm run build       # must succeed with no errors
npm run lint         # ESLint, must be clean
npx prettier --check resources/js/pages/Welcome.vue resources/js/components/landing/*.vue  # (adjust paths to whatever you actually create)
```

Then start `php artisan serve` and actually look at the rendered page (a real browser or a headless-Chrome screenshot) at a few widths before declaring it finished — don't just trust that the code "should" look right.

---

## 8. What to hand back when done

- The new/changed Vue files (page + any new landing-specific components).
- A short note on any deliberate deviations from this brief (e.g., "used a different font because X", "didn't reuse the decorative paths because Y") — same spirit as flagging assumptions rather than silently making them.
