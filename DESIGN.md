---
name: LeasyBack
description: Current-state visual system for LeasyBack's B2C/B2B lease-return platform, captured as a pre-redesign baseline
colors:
  brand-orange: "#EF8450"
  brand-green: "#01B990"
  brand-teal: "#10393B"
  brand-green-gray: "#B7C2C2"
  brand-black: "#2E3E3F"
  surface-white: "#FFFFFF"
  admin-mist: "#F8FAF9"
  admin-hairline: "#EEF3F2"
  admin-label: "#9BB0AF"
typography:
  display:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(2rem, 4vw, 3rem)"
    fontWeight: 700
    lineHeight: 1.1
  heading:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.375rem"
    fontWeight: 700
    lineHeight: 1.25
  body:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.6875rem"
    fontWeight: 700
    letterSpacing: "0.1em"
rounded:
  control: "5px"
  token-sm: "8px"
  token-md: "10px"
  token-lg: "12px"
  card: "24px"
  card-elevated: "26px"
  modal-mask: "38px"
  pill: "9999px"
spacing:
  xs: "8px"
  sm: "12px"
  md: "16px"
  lg: "24px"
  xl: "28px"
components:
  button-primary:
    backgroundColor: "{colors.brand-orange}"
    textColor: "{colors.surface-white}"
    rounded: "{rounded.control}"
    padding: "12px 24px"
  button-primary-hover:
    backgroundColor: "{colors.brand-orange}"
  input:
    backgroundColor: "{colors.surface-white}"
    textColor: "{colors.brand-black}"
    rounded: "{rounded.pill}"
    padding: "10px 16px"
  card-content:
    backgroundColor: "{colors.surface-white}"
    rounded: "{rounded.card}"
    padding: "24px"
  card-elevated:
    backgroundColor: "{colors.brand-green}"
    textColor: "{colors.surface-white}"
    rounded: "{rounded.card-elevated}"
    padding: "28px"
---

# Design System: LeasyBack

## Overview

This document captures LeasyBack's visual system **as currently implemented** — a factual baseline recorded ahead of a planned redesign, not an aspirational description. A prior design critique and technical audit found this system reads as generic/"AI-generated" in places (excessive pill shapes, decorative gradients, inconsistent surface treatment); this file documents what those findings were measured against, so a future redesign has an accurate anti-reference rather than guessing at the starting point.

The product identity is built on three brand colors — dark teal, teal-green, and orange — applied with real consistency across both the B2C customer product and the B2B admin product (confirmed: no divergent palette between the two). Where the system is inconsistent is in *structure*: radius, shadow, card treatment, and pill usage vary from screen to screen with no single enforced rule, even though the color identity itself holds together.

**Key Characteristics:**
- One consistent three-color brand identity (dark teal / teal-green / orange) shared across every surface, customer and admin alike.
- Pill/full-radius shape is systemic, not incidental — it's baked into the base `Input` and `Badge` components themselves, not just applied ad hoc.
- Auth and landing screens use a sharper `5px` control radius that the rest of the product doesn't share.
- Two shadow vocabularies coexist: a brand-tinted soft-elevation family (canonical, see Elevation & Depth) and plain default-Tailwind-gray shadows left over from unstyled components.
- A stock shadcn-derived token layer (`--primary`, `--secondary`, `--accent`, blue `hsl(221 83% 53%)`) exists in parallel to the brand tokens and is largely, but not entirely, unused — it still paints a few unstyled components (e.g. the default checkbox) the wrong color.
- Single typeface throughout (Instrument Sans); hierarchy currently comes from ad hoc size/weight combinations rather than a small enforced scale (18–29 distinct size/weight pairs were measured on a single screen).

## Colors

The palette is genuinely consistent in *hue* across the whole product; the inconsistency is in how liberally the neutral/admin tints proliferate around it.

### Primary
- **Brand Orange** (`#EF8450`): the primary call-to-action color — main submit buttons, primary CTAs on the landing page and dashboard, active/featured accents (e.g. the "BELIEBT" pricing-tier badge).

### Secondary
- **Brand Teal-Green** (`#01B990`): the core brand accent — links, focus rings (`--ring` is set to this token), active navigation state, positive/active status color, table header fills, and one gradient stop in the sidebar identity panel.

### Neutral
- **Brand Ink** (`#10393B`): the dark anchor color, not a pure gray — sidebar background, dark CTA bands, heading text, and the other gradient stop in the sidebar panel. Functions as the system's "black."
- **Brand Muted** (`#B7C2C2`): secondary/meta text, borders, placeholder text.
- **Brand Body** (`#2E3E3F`): default body text color outside of headings.
- **Surface White** (`#FFFFFF`): card and page surface fill.
- **Admin Mist** (`#F8FAF9`): admin table-header and search-field background tint — scoped to `Admin/*/Index.vue` chrome (`.admin-th`, `.admin-search`), not used on customer-facing screens.
- **Admin Hairline** (`#EEF3F2`): border/divider color paired with Admin Mist.
- **Admin Label** (`#9BB0AF`): muted uppercase label/placeholder text on admin list chrome.

### Named Rules
**The Unused Blue Rule.** `--primary`/`--secondary`/`--accent`/`--chart-*` in `resources/css/app.css` still carry their original shadcn-scaffold values (a blue `hsl(221 83% 53%)` primary, generic chart colors) and are not part of the brand. They are not fully dead — the default `Checkbox` component and `<Button variant="default">` before an override both resolve to this blue — so any component left unstyled will silently paint the wrong brand color. Treat this as a known gap, not a second valid accent.

## Typography

**Body/Display Font:** Instrument Sans (with `ui-sans-serif, system-ui, sans-serif` fallback) — the only typeface in the product; 100% of measured text renders in it.

**Character:** A single geometric sans carries the whole system today. Hierarchy is currently produced by varying size and weight ad hoc per component rather than by a small, deliberate scale — screens were measured with 18 (landing) to 29 (customer dashboard) distinct size/weight combinations in simultaneous use.

### Hierarchy (as observed; not a prescriptive scale)
- **Display** (700, ~48px on landing hero): page/hero headline only.
- **Heading** (700, ~22–24px): section and card-title headings across admin and customer screens.
- **Body** (400, 14px): the dominant text size across the product — the single most common size/weight pair measured.
- **Secondary/Meta** (400, 12–13px, often `#B7C2C2` or similar muted tones): captions, helper text, timestamps.
- **Label/Eyebrow** (700, 11–11.5px, `letter-spacing: 0.1em`, uppercase): the "kicker" label above landing-page section headings, and admin table column headers (`.admin-th`).

### Named Rules
**The One-Family Rule.** There is no second typeface anywhere in the product (no distinct display/serif/mono face) — every weight and size variation comes from Instrument Sans alone.

## Layout

Both product halves share a fixed dark-teal-gradient sidebar shell (`AppSidebar.vue` / `AdminSidebar.vue`) with the main content area scrolling independently. Below `md`, the sidebar becomes an off-canvas drawer with a scrim and locked body scroll; the customer shell additionally switches to a bottom tab bar on mobile. The shell deliberately uses `100dvh` (not `100vh`) with `env(safe-area-inset-bottom)` padding on the mobile tab bar, specifically to survive mobile browsers' collapsing URL bar.

Content density is generally generous and uniform — most containers use the same ~16–28px internal padding regardless of whether the content inside is primary or secondary, and admin list pages layer several header rows (search, tab-segment control, stat-pill row, status-filter-chip row) before the actual data table starts. Tables switch to a card-per-row layout below `md` on the customer dashboard; admin tables remain tables at all widths, wrapped in a horizontal-scroll container.

Landing-page sections all share one repeating rhythm: a small uppercase "kicker" label, a centered heading, body copy, then a content grid — applied identically across all five marketing sections (Leistungen / Ablauf / Für wen / Stimmen / Fragen).

## Elevation & Depth

The canonical vocabulary is a **brand-tinted soft-shadow family** — every intentionally-designed shadow in the system uses `rgba(16, 57, 59, …)` (the brand-ink color) rather than pure black, at low opacity (4–24%) and a soft, diffuse spread. This is what should be treated as "the" shadow system going forward.

A second, **non-canonical** shadow vocabulary also exists on the same screens: plain default-Tailwind-gray shadows (`rgba(0, 0, 0, 0.1) 0px 10px 15px -3px, rgba(0, 0, 0, 0.1) 0px 4px 6px -4px` — Tailwind's stock `shadow-lg`) show up on components that were never given a brand-tinted override, e.g. the customer dashboard's floating "Einführung" button and the base `DialogContent` primitive. This is drift, not a second valid style.

### Shadow Vocabulary
- **Card rest** (`box-shadow: 0 6px 22px rgba(16, 57, 59, 0.04)`): the default elevation for `.content-card`, the standard white card surface used across ~14 files.
- **Identity/elevated card** (`box-shadow: 0 20px 45px rgba(1, 185, 144, 0.24)`): the gradient "identity card" surface (`.identity-card`) used at the top of every `Admin/*/Show.vue` page.
- **Sidebar panel** (`box-shadow: 0 8px 30px rgba(16, 57, 59, 0.18)`): the dark sidebar shell, both customer and admin.
- **Modal drop shadow** (`filter: drop-shadow(0 10px 30px rgba(0, 0, 0, 0.15))`): the one modal shadow that is not brand-tinted; applied via `filter` rather than `box-shadow` because the modal's shape comes from a CSS mask, not a border-radius.
- **Non-canonical / drift** (`rgba(0, 0, 0, 0.1) 0px 10px 15px -3px, rgba(0, 0, 0, 0.1) 0px 4px 6px -4px`): stock Tailwind `shadow-lg`, found on unstyled floating elements. Flagged, not recommended.

### Named Rules
**The Ink-Tint Rule.** A shadow belongs to the system only if its color is derived from `--brand-teal` (`rgba(16, 57, 59, …)`) or, for the one green-gradient surface, `--brand-green`. A plain black/gray shadow on a new component is a missed override, not a style choice.

## Shapes

There is no single radius scale in effect today; instead, several real, systemic — not accidental — radius values coexist, each tied to a specific role:

- **`5px`** — the sharp "control" radius used on primary CTA buttons on the landing and auth screens only (`Login.vue`, `HeroSection.vue`). Not used elsewhere in the product.
- **Pill (`9999px` / `rounded-full`)** — baked directly into the base `Input` component and the `Badge` `cva` variant set, so it is the *default* shape for any text field or badge in the system, not a one-off choice. Also used for most buttons outside auth/landing, nav pills, filter chips, and status indicators.
- **`8–12px`** (the unused shadcn `--radius` scale: `--radius-sm`/`--radius-md`/`--radius-lg`) — present in tokens, lightly used (base `Card`, `Dialog`), mostly bypassed by page-level hard-coded radii.
- **`24px`** — `.content-card`, the standard white card surface (14+ files).
- **`26px`** — `.identity-card`, the gradient "identity" surface at the top of admin detail pages.
- **`38px`** — the notch radius of the shared `AppModal`'s CSS-mask "inverted corner," the product's signature (if idiosyncratic) modal shape.

## Components

### Buttons
- **Shape:** Pill (`rounded-full`) is the default outside auth/landing; auth and landing primary CTAs instead use the sharp `5px` control radius. Both are real, current patterns — not yet reconciled.
- **Primary:** `background: #EF8450` (brand orange), white text, no border, `shadow-none` on auth/landing CTAs; elsewhere may carry the stock shadcn `shadow` class inherited from the base `Button` variant.
- **Hover / Focus:** primary buttons darken via `hover:bg-brand-orange/90`; keyboard focus uses the shared `focus-visible:ring-2 focus-visible:ring-ring` (green ring, since `--ring` resolves to brand-green).
- **Secondary / Ghost:** the base `Button` component's `cva` variants (`secondary`, `outline`, `ghost`, `link`) exist and use the unused shadcn `--secondary`/`--accent` tokens rather than brand tokens — a gap, not an intentional secondary palette.

### Badges / Status Pills
- **Style:** Full-pill shape, small text (`text-xs font-semibold`), tinted background at low opacity with a matching saturated text color — no border in the common case.
- **State:** Status color is currently determined independently in several places (admin lists, order timeline, vehicle rows) rather than by one shared mapping, so the same status can render in different colors depending on which screen renders it. The base `ui/badge` component's own `success`/`warning` variants exist but are rarely the actual source of a status color in practice.

### Cards / Containers
- **Corner Style:** `24px` for the standard `.content-card` surface; `26px` for the elevated gradient `.identity-card` surface.
- **Background:** white for `.content-card`; a `145deg` teal-green gradient (`#55bd99` → `#0a8d70`) for `.identity-card`.
- **Shadow Strategy:** see Elevation & Depth — brand-tinted, soft, low-opacity.
- **Border:** `.content-card` has a 1px `#EEF3F2` hairline border; `.identity-card` has a 1px `#01B990` border matching its gradient.
- **Internal Padding:** `24px` (`.content-card`), `28px` (`.identity-card`).
- **Nesting:** cards are currently nested inside cards in several places (stat sub-tiles inside a primary gradient stat card, a comparison box inside the landing hero card) — an observed pattern, not a recommended one.

### Inputs / Fields
- **Style:** Pill radius (`rounded-full`, baked into the base `Input` component itself), white background, `1px` border using the muted brand-gray token, `10px 16px`-scale padding.
- **Focus:** border shifts to brand-green (`focus-visible:border-brand-green`) plus a low-opacity green ring.
- **Error:** border and ring shift to the destructive token on `aria-invalid`.

### Navigation
- **Style:** A fixed dark-teal-gradient sidebar (`linear-gradient(180deg, #10393B 0%, #0D3133 100%)`) shared in structure by both the customer and admin shells, differing only in nav-item list and active-state fill. Active item is a solid brand-green pill/rounded-rect fill; a secondary gradient (`linear-gradient(150deg, #01B990, #10393B)`) appears on the user/role badge within the sidebar.
- **Mobile:** below `md`, the sidebar becomes an off-canvas drawer with scrim and scroll-lock (admin) or the customer shell switches to a bottom tab bar with safe-area padding.

### AppModal (signature component)
The product's default modal shape is distinctive and worth naming explicitly: a plain rounded-rectangle white surface has its top-right corner cut into a scalloped "inverted corner" notch via a layered CSS `mask`/`conic-gradient` (`--r: 38px` notch radius), with a `44–56px` circular emerald (`bg-emerald-500`, not a brand token) close button overhanging that corner. Used in 19+ places across the product (order/vehicle actions, onboarding, document upload). It is the single most idiosyncratic, custom-built visual element in the system — genuinely distinctive, though not yet reconciled with the brand's other shapes (it uses neither the `24px`/`26px` card radii nor the brand-green/orange/teal palette for its own chrome).

## Do's and Don'ts

### Do:
- **Do** use the three brand tokens (`--brand-orange`, `--brand-green`, `--brand-teal`) via their existing Tailwind utilities (`bg-brand-orange`, `text-brand-green`, etc.) rather than hex literals — the tokens already exist and are the normative source.
- **Do** treat brand-tinted shadows (`rgba(16, 57, 59, …)`) as the only canonical shadow family.
- **Do** keep the pill shape for inputs and badges — it's load-bearing in the base component kit, not a one-off.

### Don't:
- **Don't** hardcode brand hex values (`#10393B`, `#01B990`, `#EF8450`, etc.) directly in new markup — use the existing brand-token utilities instead. Hardcoded hex is the dominant pattern today (measured across 90+ files), not a model to extend.
- **Don't** leave a component on the stock shadcn `--primary`/`--secondary`/`--accent` blue — it does not match the brand and currently leaks through on unstyled components (e.g. the default `Checkbox`).
- **Don't** mix plain default-Tailwind-gray shadows (`shadow-lg`, `rgba(0,0,0,0.1)…`) into the same screen as the brand-tinted shadow family — treat any gray shadow found in the wild as an unfixed default, not a second valid style.
- **Don't** treat this file's inventory of real, current inconsistencies (dual radius language, drifting status colors, coexisting shadow vocabularies) as license to add a third variant of any of them — they are documented as known gaps to resolve, not options to choose from.
