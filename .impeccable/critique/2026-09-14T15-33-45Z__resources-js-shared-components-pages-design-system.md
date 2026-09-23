---
target: resources/js (shared components, pages, design system)
total_score: 22
max_score: 36
na_heuristics: 10
p0_count: 2
p1_count: 3
target_identity: "file:D:\\Metru\\backend\\leasyback-backend\\resources\\js (shared components, pages, design system)"
timestamp: 2026-09-14T15-33-45Z
slug: resources-js-shared-components-pages-design-system
---
Method: dual-agent (A: design-review sub-agent · B: detector+evidence sub-agent)

Scope note: Assessment A read source across the shared UI kit (`resources/js/components/ui/**`), domain components (vehicle/admin/account/landing), and representative pages (Dashboard, vehicles/Show, orders/Show, Admin/Dashboard, Workshop/Quotation, auth/Login, Welcome), plus PRODUCT.md. Assessment B ran the static `impeccable detect` CLI against the same directories and a live puppeteer scan of the public landing page (`http://localhost:8000/`). Authenticated screens (Dashboard, Admin, Orders, Workshop) were evaluated from source only — no login credentials were available or attempted; the public landing page was additionally verified live in-browser.

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3/4 | Loading skeletons and toast progress exist; admin stat cards lack a distinct loading/error state |
| 2 | Match Between System & Real World | 3/4 | Correct German domain vocabulary, but iconography (donut chart, gradient tiles) reads as generic dashboard rather than automotive-specific |
| 3 | User Control and Freedom | 3/4 | Modals close/cancel cleanly, inline confirm-before-destructive is a good pattern; no visible undo anywhere |
| 4 | Consistency and Standards | 1/4 | Core failure — radius ranges from `rounded-[5px]` to `rounded-full` to `rounded-[24–26px]` with no scale; buttons/status colors independently reimplemented in 3–4+ places |
| 5 | Error Prevention | 3/4 | Form errors routed through a shared `InputError`/`FormField`; destructive actions require inline confirm |
| 6 | Recognition Rather Than Recall | 3/4 | Persistent nav/breadcrumbs; status uses color **and** label together, not color alone |
| 7 | Flexibility and Efficiency of Use | 2/4 | No keyboard shortcuts, no bulk actions, no dense table mode for admins triaging many orders |
| 8 | Aesthetic and Minimalist Design | 1/4 | Cards nested in cards, decorative blur blobs/gradients/pulsing glow/donut chart add noise unconnected to any decision |
| 9 | Error Recovery | 3/4 | Shared `InputError` + toast system with clear success/error/warning accents |
| 10 | Help and Documentation | n/a | No in-app help surface found; out of scope for a visual/component review |
| **Total** | | **22/36** | **Acceptable (61%)** — significant, fixable consistency problems, not a fundamentally broken product |

## Design Specificity Verdict

**LLM assessment:** This does not read as authored for an automotive lease-return operations product — it reads as a generic SaaS template with a brand color wash on top. The clearest evidence is the token layer itself: `resources/css/app.css` (`:root`) and `tailwind.config.js` still carry the **unmodified stock shadcn/ui default palette** (`--primary: hsl(221 83% 53%)`, `--radius: 0.75rem`, generic `--chart-1..5`) — and it doesn't match LeasyBack's real brand (dark teal `#10393b`, teal-green `#01B990`, orange `#EF8450`), which live in a separate `--brand-*` token block the code itself comments as "auth pages/components only." The shared UI kit (`ui/button`, `ui/card`, `ui/badge`, `ui/table`) was never adapted to the brand at all; every domain page then routes around it, hand-rolling bespoke hard-coded-hex CSS (`content-card`, `.lb-pg`, `.admin-th`, dozens of inline `#10393b`/`#01B990` literals). The result is three competing, undocumented systems in one app (shadcn defaults / global `app.css` component classes / per-page `<style scoped>` duplicates), layered with textbook generic-SaaS tells: blurred glow blobs behind stat cards, a pulsing box-shadow animation, a donut chart, gradient "hero" stat tiles, and a decorative conic-gradient masked-corner treatment on every modal. The landing hero and auth screens are the exception — a tighter, sharper `rounded-[5px]` vocabulary closer to the "precise, European" target — but that vocabulary is abandoned two clicks later in Dashboard/Admin, so the product feels like two apps stitched together rather than one confidently designed system.

**Deterministic scan:** The static per-file scan (`detect --json` against the UI kit, domain components, and page files) returned only 2 findings total — largely because this codebase is ~100% Tailwind utility classes with almost no `<style>` blocks, and the static scanner's regex mode matches literal CSS properties, not Tailwind class names (verified by running the detector against a synthetic file with blatant `rounded-3xl`/`shadow-2xl`/gradient classes, which also produced zero findings). The two static hits — `border-l-4` in `resources/js/components/account/DeleteAccountCard.vue:2` and a `box-shadow` glow rule in `resources/js/pages/Admin/Dashboard.vue:884` — are both judged likely false positives: the first is a single, purposeful "danger zone" accent border, not repeated decorative use; the second is a 6%-opacity neutral elevation shadow on a **white** card (the flagged `#10393b` is the text color, not a dark-page background). A supplementary live scan against the public landing page (puppeteer full render, since the CLI auto-detects URLs) surfaced 69 findings — most usefully 18 real, measured **WCAG AA contrast failures** (e.g. `1.7:1` on muted captions, `2.5–2.6:1` white text on brand-colored buttons/badges), 5 identical "kicker label above H2" repeats (one per section), a hero eyebrow pill, and 2 confirmed nested-card instances. 35 of the 69 were an `ai-color-palette` rule flagging "cyan neon on dark" — but the page has no cyan or purple anywhere; this is the detector's color-distance heuristic misbucketing the brand's saturated teal-green (`#01b990`) and should be discounted, not treated as 35 distinct problems.

**Visual overlay:** No user-visible browser overlay was produced — this CLI build has no `live-server` subcommand to inject the interactive overlay. As a substitute, Assessment B ran `detect --json` directly against the live URL (the CLI's own puppeteer render path) and did a full manual scroll-through with screenshots, both summarized above and below.

## Overall Impression

The product's actual differentiators — the Minderwert-Gutachten comparison card, the workflow-specific order-progress trail, dual color+label status indicators — are genuinely good and specific to this business. But almost none of that specificity survives into the shared component layer or the day-to-day operational screens, because the design system itself was never finished: it's stock shadcn defaults nobody wired to the brand, papered over per-page with duplicated hard-coded styles. That gap is exactly what reads as "AI-generated" — not one bad decision, but the absence of one enforced system, visible as inconsistent radius, four independent status-color implementations, decorative chrome with no informational purpose, and measurable accessibility failures. The single biggest opportunity: fix the token layer first (wire real brand colors into `--primary`/`--secondary`/`--accent`) and consolidate status/badge into one component — a large share of the "generic SaaS" complaint traces back to those two root causes.

## What's Working (preserve)

- `resources/js/components/landing/HeroSection.vue` — the Minderwert-Gutachten mockup (claim vs. LeasyBack price, damage-row comparison, savings bar) is a genuinely product-specific, non-generic signature element, with a tighter `rounded-[5px]` CTA language close to the target character. Anchor the redesign on this rather than replacing it.
- `resources/js/components/vehicle/OrderProgress.vue` — a purpose-built vertical status trail (numbered/checked circles, connecting line, cancelled/rejected states) specific to the lease-return workflow, not a generic stepper. Keep the interaction model even if the visual treatment changes.
- Status shown as **color dot + text label together**, not color alone (e.g. `VehicleRow.vue`, `orders/Show.vue`'s `STATUS_TONE`) — accessibility-conscious and worth preserving through any rework.
- `resources/js/components/ui/toast/ToastItem.vue` — a well-crafted, brand-consistent component (dark teal gradient, per-variant accent, drain-progress bar); a reasonable template for how the rest of the kit should look once unified.

## What's Inconsistent / Feels Generic / Repeated "AI-Slop" Patterns

- **Corner-radius vocabulary has no scale**: `rounded-[5px]` (landing/auth) vs. `rounded-full` (dashboard buttons, badges, sticky nav pill, footer social icons, "BELIEBT" tier badge) vs. `rounded-[24–26px]` (dashboard/admin cards) — three unrelated radii used with no evident logic.
- **Status/color logic reimplemented independently in 4+ places**: `VehicleRow.vue` (inline hex), `orders/Show.vue` (`STATUS_TONE` map), `AdminOffersCard.vue` (`STATUS_PILLS`), `Admin/Dashboard.vue` (`getAdminDashboardStatus`) — near-but-not-identical hex values for what's conceptually the same state (`#01B990` vs `#00856a` vs `#2c7a7d`).
- **Pill shape used indiscriminately**, blending real semantic status pills with pure decoration: sticky nav bar is a floating pill container, hero eyebrow chip is a pill, the "BELIEBT" popular-tier badge is a pill, footer social icons are pill/circle, and `ui/badge` itself defaults to `rounded-full` — yet most status badges in the actual product bypass this shared component and hand-roll their own shape/color anyway.
- **Decorative masked-corner modal chrome app-wide**: `resources/js/components/ui/modal/AppModal.vue` renders a custom conic/radial-gradient CSS mask plus an overhanging circular emerald close button on every modal — used in 19+ places (order creation, vehicle add/import, offer creation, document upload) — decorative, unrelated to any modal's content, and the single most repeated "template-y" chrome element in the product.
- **Admin/Dashboard.vue decorative cluster**: blurred glow blobs behind stat cards, a pulsing box-shadow animation on a stat icon, a donut chart, gradient-filled "hero" stat tiles — the densest concentration of generic-dashboard signifiers, on the screen the internal admin (primary daily operator) stares at first.
- **Mechanical landing-page templating**: an identical small tracked-uppercase "kicker" label sits above every section H2 (Leistungen/Ablauf/Für wen/Stimmen/Fragen — 5/5 sections, confirmed both by the detector and live visual inspection); the pricing section uses the classic "middle tier raised, inverted to dark background, rounded-full 'BELIEBT' badge" SaaS-pricing cliché; a dotted decorative arc motif is reused verbatim behind both the hero and the CTA band; the 6-card features grid mixes two unrelated card treatments in the same grid (top row: white card + green "Mehr erfahren →" link; bottom row: plain gray fill, no link) with no evident reason for the split.
- **18 measured WCAG AA contrast failures on the public landing page alone** (e.g. muted captions at ~1.7:1, white text on brand-colored buttons/badges at ~2.5–2.6:1) — not a matter of taste; these fail the accessibility commitment recorded in PRODUCT.md, and are the kind of "soft, low-contrast pastel" treatment the investor feedback is also describing.
- **The same visual language duplicated across three code layers** (`app.css`'s `@layer components` classes like `.content-card`/`.lb-pg`/`.admin-search`, re-declared nearly verbatim inside `<style scoped>` in `Admin/Dashboard.vue`) — a hygiene problem that will make executing any redesign consistently harder, since a single radius/shadow change has to be hunted down in multiple files.

## Shared Components Responsible

- `resources/css/app.css` (`:root` token block) and `tailwind.config.js` — unmodified shadcn defaults never wired to the real `--brand-*` tokens; root cause of most downstream duplication.
- `resources/js/components/ui/badge/index.ts` and `resources/js/components/ui/button/**` — defined but bypassed by page-local reimplementations for anything status-related.
- `resources/js/components/ui/modal/AppModal.vue` — the masked-corner decorative chrome propagates to every modal in the app from one file.
- `resources/js/pages/Admin/Dashboard.vue` — both the worst decorative offender (glow/blur/pulse/donut) and a source of duplicated CSS that re-declares `app.css`'s component classes locally.

## Highest-Impact Changes (ordered)

1. **[P0] Wire real brand tokens into `--primary`/`--secondary`/`--accent`/`--radius` in `app.css`/`tailwind.config.js`**, retiring the stock shadcn defaults, so `Button`, `Badge`, `Card` variants resolve to actual LeasyBack colors everywhere instead of every page hand-rolling hex. This single change likely dissolves a large share of the "AI-generated" complaint on its own.
2. **[P0] Consolidate status representation into one shared badge/tag component** with a fixed palette and a deliberately non-pill shape (small rounded-rect tag, not `rounded-full`) reserved specifically for status — replacing the 4 independent reimplementations in `VehicleRow.vue`, `orders/Show.vue`, `AdminOffersCard.vue`, and `Admin/Dashboard.vue`.
3. **[P1] Fix the 18 measured contrast failures** on the landing page (muted captions, white-on-brand-color button/badge text) — a compliance-relevant defect, not a style preference, and directly touches the accessibility commitment in PRODUCT.md.
4. **[P1] Replace `AppModal.vue`'s decorative masked-corner chrome** with the existing `ui/dialog` primitives (already in the kit) using a restrained, minimally-rounded surface — this one file change reaches 19+ usages across every core workflow (order creation, vehicle add/import, offer creation, document upload).
5. **[P1] Strip the decorative cluster in `Admin/Dashboard.vue`** (glow blobs, pulsing animation, donut chart, gradient hero tiles) in favor of a flat, data-forward layout — this is the page the primary daily operator (internal admin) works from, and currently carries the densest concentration of generic-dashboard chrome in the product.
6. **[P2] Resolve the landing page's mechanical templating**: vary or intentionally justify the repeated kicker-above-H2 pattern, reconcile the two different feature-card treatments in one grid, and reconsider the cliché raised-dark-middle-tier pricing card — these are the specific, nameable tells behind "feels AI-generated" on the page prospects see first.
7. **[P3] Remove duplicated CSS**: delete the page-local `<style scoped>` copies of `app.css`'s `.content-card`/`.lb-pg`/`.admin-search` classes (currently duplicated in `Admin/Dashboard.vue`) so future restyling is a one-file change, not a hunt across layers.

## Persona Red Flags

**Alex (power-user admin, repetitive order processing)**
- `Admin/Dashboard.vue`'s three gradient "hero" stat cards (~320px tall each) push the actionable panel (orders/users/vehicles lists) below the fold on anything under ~900px viewport height — pure chrome before the day's work starts.
- Every list (orders/users/vehicles panels, the vehicle table on `Dashboard.vue`) is single-select, one-row-at-a-time — no bulk status change, no multi-select, no keyboard row navigation — despite the role being defined by repetitive processing.
- Status colors must be relearned per screen (Dashboard vs. Admin Dashboard vs. Order Show each define near-but-not-identical hex for the same conceptual state) rather than recognized once.

**Sam (accessibility-dependent)**
- The 18 measured contrast failures (landing page) mean text is provably below WCAG AA in multiple recurring places, not an isolated slip.
- Heavy reliance on inline `:style="{ backgroundColor: ... }"` / hard-coded hex (`VehicleRow.vue`, `AdminOffersCard.vue`'s `STATUS_PILLS`) means there's no single place to verify or fix contrast as a set — some pastel-on-pastel combinations were likely never checked together.
- `AppModal.vue`'s card silhouette is composited entirely from a CSS gradient mask rather than a plain border/border-radius — worth verifying it degrades safely under forced-colors/high-contrast OS settings, since the shape itself depends on the mask rendering.
- Motion-reduction handling is inconsistent: `HeroSection.vue`'s entrance animation correctly guards with `motion-reduce:transition-none`, but `Admin/Dashboard.vue`'s infinite pulsing box-shadow keyframe has no such guard.

## Minor Observations

- `ui/badge`'s `success`/`warning` variants exist but are barely used — most of the app bypasses this component for status entirely, making it effectively dead code in practice.
- `Admin/Dashboard.vue` assigns three unrelated colors to the "orders/users/vehicles" stat-card icons with no evident color-to-meaning system (why is "vehicles" orange and "users" near-black?).
- `Dashboard.vue`'s table header sets `background-color: #01b990` via inline `style` on `TableRow`, bypassing `TableHead`'s own styling — another instance of the token-bypass pattern.
- The login screen's status banner (`rounded-[5px] border-green-300 bg-green-50`) is styled completely differently from every other feedback surface (toasts, badges) — a first-time user's very first interaction already shows visual inconsistency.
- Treat the detector's `ai-color-palette` (35 hits) finding as noise for this palette specifically — it's misclassifying the brand's teal-green as "cyan neon"; don't let that count drive prioritization.

## Provocative Questions

1. If the real brand tokens were wired into `--primary`/`--secondary`/`--accent` today with zero other changes, how much of the "generic SaaS" complaint would simply disappear — versus how much is genuinely about radius/shadow/decoration choices that would remain?
2. The landing page and auth screens already demonstrate the tighter, more European visual language the investor wants — was that a deliberate two-track decision, or did the marketing site simply get more design attention than the tool people use every day?
3. Given `Admin/Dashboard.vue` carries the heaviest decorative footprint on the page the primary daily operator looks at first, is investor-facing polish being prioritized over operator efficiency — and does that match what "professional, operational, restrained" is meant to mean here?
