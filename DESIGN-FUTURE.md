<!-- TARGET DIRECTION: a not-yet-built redesign contract, not the current implementation. DESIGN.md remains the accurate baseline of what is actually shipped until this direction is built; at that point the shipped documenter rewrites DESIGN.md from the built result, not from this file verbatim. Brand colors are carried over unchanged from DESIGN.md — everything else here is a deliberate departure from it. -->

---
name: LeasyBack Future Design System
description: Target visual language after modernization — premium automotive/operational, not yet implemented
colors:
  primary: "#EF8450"
  secondary: "#01B990"
  danger: "#C0392B"
  neutral-ink: "#10393B"
  neutral-muted: "#B7C2C2"
  neutral-text: "#2E3E3F"
  surface-white: "#FFFFFF"
  surface-ground: "#FAFAFA"
  surface-bordered: "#F7F9F8"
typography:
  display:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "32px"
    fontWeight: 700
    lineHeight: 1.1
  h1:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "24px"
    fontWeight: 700
    lineHeight: 1.2
  h2:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "20px"
    fontWeight: 700
    lineHeight: 1.25
  h3:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "16px"
    fontWeight: 600
    lineHeight: 1.3
  body:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.5
  small:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "12.5px"
    fontWeight: 400
    lineHeight: 1.4
  label:
    fontFamily: "Instrument Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "11px"
    fontWeight: 700
    letterSpacing: "0.08em"
rounded:
  radius-sm: "6px"
  radius-md: "10px"
  radius-lg: "12px"
  radius-pill: "9999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "24px"
  xxl: "32px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.surface-white}"
    rounded: "{rounded.radius-sm}"
    padding: "10px 20px"
  input:
    backgroundColor: "{colors.surface-white}"
    textColor: "{colors.neutral-text}"
    rounded: "{rounded.radius-sm}"
    padding: "10px 14px"
  status-tag:
    backgroundColor: "{colors.surface-white}"
    rounded: "{rounded.radius-sm}"
    padding: "2px 8px"
  card:
    backgroundColor: "{colors.surface-white}"
    rounded: "{rounded.radius-md}"
    padding: "20px"
---

# LeasyBack Future Design System

This document is **not** a description of the current interface — that is what `DESIGN.md` records, and it stays the accurate baseline. This is the target design language LeasyBack modernizes into, grounded in the visual critique and audit already run against the live product, and in the brief given for this direction:

> Premium automotive software. European operational platform. Trustworthy enterprise product. Precise and calm. Human-designed.

**Governing principles, in order of authority when two rules conflict:**

1. **Data over decoration.** An element earns its place by carrying information or enabling an action, never by looking finished.
2. **Structure over cards.** Alignment, dividers, and hierarchy separate content before a bordered container does.
3. **Typography over containers.** A heading and spacing change communicate hierarchy before a new box does.
4. **Density over excessive whitespace.** This is operational software used daily, not a marketing surface — padding is spent on priority, not applied uniformly.
5. **Intentional components over generated patterns.** Every shape, radius, and shadow value traces to a rule in this document, never to whatever a component library defaulted to.

---

# Visual Personality

LeasyBack should read like operations software a serious European fleet-logistics company built for itself — not like a startup's landing-page-generator output. **Precise**: every value in this document is deliberate and finite; nothing is "roughly rounded" or "about that shadow." **Calm**: no motion, gradient, or shape competes with the data for attention. **Confident**: the product doesn't need a blur blob or a mascot modal to feel approachable — consistent behavior and honest density earn trust instead. **Human-designed**: the tell of a generated interface is uniformity without reason (every corner the same radius, every container a card, every list a grid of tiles); the tell of a designed one is restraint applied *unevenly*, on purpose, because different content has different weight.

**The Restraint Rule.** When a decision is ambiguous, remove the border, shadow, or decoration and let alignment, typography, and whitespace carry the hierarchy instead. A screen that needs a gradient to feel finished is not finished.

# Color System

Color strategy: **Restrained.** Neutral surfaces and neutral-ink carry most of every screen; `primary` and `secondary` are spent narrowly and specifically, never spread across every component. This is correct for Operate-mode software — brand lives in precise, consistent details, not in surface area.

- **Primary** — `#EF8450` (brand orange). The interactive/action color: primary buttons, the one CTA per view, primary-tier active states. Kept exactly as-is from the current brand.
- **Secondary** — `#01B990` (brand teal-green). The supporting accent: links, focus rings, positive-status color, secondary emphasis. Kept exactly as-is.
- **Danger** — `#C0392B` (a new, muted brick-red — not previously in the brand palette, needed because the brand has no red). Destructive actions and critical/cancelled status only. Desaturated on purpose: this is a warning tone appropriate to enterprise software, not an alarm color.
- **Neutral** — brand teal (`#10393B`) functions as the system's structural "ink," not a background gray: headings, primary body text on light surfaces, the sidebar identity panel. Paired with a muted gray-green (`#B7C2C2`) for secondary text and borders, and a near-black (`#2E3E3F`) for default body copy.
- **Surfaces** — white (`#FFFFFF`) for content-tier and elevated surfaces; a near-white ground (`#FAFAFA`) for the page background; a very light bordered tint (`#F7F9F8`) for the rare flat section that needs to read as a distinct zone without becoming a card.

**The Two-Accent Rule.** Only `primary` and `secondary` ever appear as fills on interactive elements. `danger` appears only on destructive actions and critical status. Nothing else introduces a new hue.

# Radius System

Four tokens, each with exactly one job — no fifth, ad hoc value anywhere:

| Token | Value | Allowed on |
|---|---|---|
| `radius-sm` | `6px` | Buttons, inputs, table cells, status tags, filter controls — every interactive control |
| `radius-md` | `10px` | Cards, panels, bordered sections |
| `radius-lg` | `12px` | Modals, dropdowns, popovers — elevated surfaces only |
| `radius-pill` | `9999px` | **Reserved exclusively** for a genuine tag/filter-toggle chip — never a button, input, card, modal, or stat tile |

**Important — remove full-pill defaults.** Today, pill radius is the *baked-in default* of the base `Input` and `Badge` components, which is why it propagates everywhere. In this system, no component defaults to `radius-pill`; a component only receives it when it is explicitly a tag/chip. Inputs move to `radius-sm`. Status indicators move to `radius-sm` with a leading dot (see Status System) — not `radius-pill`.

# Surface System

Exactly three tiers, nothing floats outside them:

- **Flat content** — the default. Tables, lists, detail sections, most of every screen. No border required if spacing alone separates it from its neighbor; `background: surface-white` or `surface-ground`, no shadow.
- **Bordered section** — flat content that needs a visible boundary without becoming "a card": a single `1px` hairline border (`neutral-muted` at ~30% opacity), `radius-md`, `surface-white` or `surface-bordered` fill, no shadow. This is the default for anything that today would reflexively become a `.content-card`.
- **Elevated card** — reserved for a genuinely self-contained, comparably-weighted unit competing for attention (a single vehicle summary, a single document tile) — `radius-md`, `elevation-1` at most, used sparingly.
- **Modal** — the only surface that earns `elevation-2`. `radius-lg`, plain rectangle, no exceptions.

**Rule: never nest cards.** A card, bordered section, or modal never contains another bordered/shadowed container. A sub-grouping inside one uses a divider, a single flat background tint, or spacing and typography — never a second box.

# Shadow System

Three values only, and the first is the default:

| Token | Value | Used for |
|---|---|---|
| `shadow-none` | none | Flat content and bordered sections — the default for almost everything |
| `shadow-subtle` | `0 1px 2px rgba(16, 57, 59, 0.06)` | The rare elevated card that needs a whisper of lift instead of (or in addition to) a border |
| `shadow-overlay` | `0 12px 32px rgba(16, 57, 59, 0.16)` | Modals, dropdowns, popovers, toasts — the only tier that floats |

**No decorative shadows.** Every shadow value is derived from `neutral-ink` (`rgba(16, 57, 59, …)`) — no plain black/gray shadow, no colored glow, no shadow that exists to make a surface "feel designed" rather than to communicate that it is floating above something.

# Typography

One typeface — Instrument Sans stays; a single disciplined workhorse sans is correct for operational software, the fix is the scale, not the font. Seven roles, nothing outside them:

| Role | Size / Weight | Use |
|---|---|---|
| Display | 32px / 700 | Once per product, for a true top-level moment — not a per-page default |
| H1 | 24px / 700 | Page title — one per screen |
| H2 | 20px / 700 | Section heading |
| H3 | 16px / 600 | Card/widget/table-section title |
| Body | 14px / 400 | Default text — the dominant size everywhere |
| Small | 12.5px / 400, muted | Captions, timestamps, secondary detail |
| Label | 11px / 700, `0.08em` tracked, uppercase | Table column headers and rare emphasis — not a decorative kicker repeated above every heading |

This replaces the 18–29 ad hoc size/weight combinations measured per screen today with seven, used consistently.

# Spacing

| Token | Value | Spent on |
|---|---|---|
| `xs` | 4px | Icon-to-label gaps, tight inline groups |
| `sm` | 8px | Dense table/list row padding |
| `md` | 12px | Standard control padding, form field gaps |
| `lg` | 16px | Section internal padding |
| `xl` | 24px | Section-to-section gaps, card padding |
| `xxl` | 32px | Page-level top margin, the rare true top-level moment |

Spacing communicates priority, not uniformity — primary content and primary actions get `xl`/`xxl`; secondary and tertiary content (table rows, meta text, dense admin lists) stay at `sm`/`md`. The same padding value does not apply to every container regardless of importance.

# Buttons

- **Primary:** `primary` fill, white text, `radius-sm`, `shadow-none`, bold label. One per view.
- **Secondary:** `neutral-ink` outline or ghost fill, `radius-sm`. For the second-priority action beside a primary.
- **Tertiary:** text-only, `neutral-ink`, underline on hover. For low-emphasis actions (cancel, "view details").
- **Danger:** `danger` fill or outline, paired with the existing inline confirm-before-action pattern (already good UX today — keep it).

**Avoid:** pill buttons (every button uses `radius-sm`, never `radius-pill`) and oversized CTA styling (no button grows beyond its content's needs to "feel important" — importance comes from being the one primary action on the screen, not from size).

# Inputs

- **Height:** 40px standard control height.
- **Radius:** `radius-sm` — a deliberate change from today's baked-in pill shape.
- **Borders:** `1px` hairline, `neutral-muted`, `surface-white` fill, no shadow at rest.
- **Focus:** border shifts to `secondary` plus a thin 2px ring at low opacity — a border shift, not a glow.
- **Errors:** border and a small inline message below the field shift to `danger`; never color alone.
- Label always visible above the field, never placeholder-only.

# Status System

One unified status component, consumed everywhere — replacing the 4–6 independently reimplemented (and in places contradictory) status-color mappings found in the audit.

**Colors:**

| Semantic role | Color | Example statuses |
|---|---|---|
| Neutral / pending | `neutral-muted` | Requested, draft |
| Active / in progress | `neutral-ink` | Collected, in workshop |
| Positive / complete | `secondary` | Approved, delivered |
| Attention | `primary` | Awaiting response, action needed |
| Critical / cancelled | `danger` | Cancelled, rejected |

**Shape:** a small rounded-rect tag, `radius-sm`, with a leading 6–8px dot plus the status label. Never color alone.

**When badge/pill is allowed:** `radius-pill` is allowed only on a genuine filter/toggle chip (e.g. a status filter control the user clicks to change what's shown) — never on a status *indicator* rendered on a row, card, or timeline step. A filter chip and a status tag are different components even when they show the same word, because one is interactive selection and the other is a fact about a record.

# Tables

LeasyBack is operational software; tables are the primary vehicle for vehicles, orders, and customers — not card grids.

**Prefer:** dense rows, real column dividers (hairline, not shadow), alignment (numbers right-aligned, text left-aligned, status tags in their own column), a sticky header, a visible active-sort indicator, and the horizontal-scroll wrapper already correctly implemented today. Row hover gets a flat background tint, never a shadow or lift.

**Avoid:** card grids for everything. A dataset (vehicles, orders, customers, line items) is a table or a divided list; a card is reserved for the single self-contained unit described in Surface System, not the default way to show a collection.

# Timeline

Based on the pattern already in `resources/js/pages/vehicles/Show.vue`, which is the closest thing in the product today to this target character — refine, don't replace:

- Vertical dot-and-line trail, one row per step.
- Thinner connecting line, smaller dots than today's implementation.
- Timestamps set in the `Small` type role, right-aligned or visually secondary.
- No card wrapper around individual steps.
- The current step gets exactly one clear mark — a filled dot plus bold label — not a duplicated status tag repeating the same state beside it.

# Empty States

**Remove generic SaaS empty states** — no icon-in-circle, no decorative illustration. Follow the one place the product already gets this right (`vehicles/Show.vue`'s documents section): a single calm sentence in `Body` or `Small` type, and — only when a real action exists — one text link, not a button-in-a-box.

# Modals

Replace the current masked-corner "inverted corner" chrome with a professional, unremarkable dialog:

- Plain rectangular surface, `radius-lg`, `shadow-overlay`.
- Close button: a standard 40px control fixed at the top-right corner, inline with the surface edge — not overhanging it, not `emerald-500` (use `neutral-ink` at rest, `primary` or a darker tone on hover).
- Reserve modals for short, focused tasks. Prefer an inline expansion panel (the existing vehicle expanded-panel pattern is good and should extend to more places) for longer flows rather than a large modal.

# Navigation

Keep the dark-teal-gradient sidebar as a brand asset — it is genuinely distinctive and stays. Rules for the rebuild:

- **One** shared sidebar component parameterized by role (customer/admin) and nav-item list — not two ~90%-duplicated implementations.
- Active item: solid fill on a `radius-sm` rect, not a pill.
- Collapse/expand toggle always carries an accessible name; collapsed-state item labels are reachable on keyboard focus, not hover-only.

# Dashboard

- Every tile shows a real number, status, or actionable item — never a decorative wrapper around one.
- One KPI-tile visual language, reused consistently — not several different stat-tile treatments on the same screen.
- Order content by priority-to-act (what needs attention first), not a uniform grid of equally-weighted tiles.
- The admin dashboard is where the primary daily operator works — it earns the *least* decoration in the product, not the most.

**Avoid:** donut charts for simple counts (use a plain number list or a bar instead), gradient stat cards, glow effects, and nested cards (see Surface System's no-nesting rule — this is the screen that violates it most today).

# Motion

Restrained motion only:

- Motion communicates a real state change (loading, success, a transition between two real states) — never ambient or decorative. No pulsing glows, no floating blobs, no idle motion.
- `prefers-reduced-motion` is respected everywhere, including the app shell and sidebar — not only the landing page, which is the one place it's already handled correctly today.
- Transitions stay short (150–200ms) and purposeful. Entrance animation on real data never delays comprehension of that data.

# Migration order

Recommended implementation order, front-loading the highest-leverage, lowest-risk changes:

1. **Tokens first.** Land the Color/Radius/Shadow/Typography/Spacing systems above as real design tokens (replacing the unused shadcn scaffold and the ad hoc hex) with zero visual changes to page structure. This is mechanical and de-risks everything after it.
2. **Status System.** Consolidate the 4–6 independent status-color implementations into the one unified component. High leverage — touches every list, timeline, and detail screen — and low risk, since it's a single new component with many call-site swaps rather than a structural redesign.
3. **`vehicles/Show.vue` as the first reference page** (see below) — the proving ground for Surface System, Timeline, Empty States, and Buttons/Inputs together on one real screen.
4. **Shared primitives**: `AppModal` → professional dialog, `Input`/`Button` off pill defaults, the sidebar duplication fix — once proven on the reference page, these propagate automatically to every consumer.
5. **Tables and list pages** (Admin Vehicles/Orders/Customers, customer Dashboard) — apply Surface System and Tables rules; remove card-grid patterns and excess filter-pill sprawl.
6. **Admin Dashboard last** — it carries the densest concentration of patterns being removed (donut chart, gradient stat cards, glow effects, nested cards) and depends on the Status System and shared primitives already being in place; rebuilding it first would mean rebuilding it twice.

**The first reference page is `resources/js/pages/vehicles/Show.vue`. Why:**

- It's the actual operational core of the product — where a customer or admin tracks a live lease-return day to day — not marketing chrome or a stat overview, so fixing it demonstrates the new direction where it matters most.
- It already contains the app's best instincts (a real timeline, divider-based key-value data, minimal decorative chrome) alongside enough of its worst habits (a pill status badge, icon-in-square accents, some card stacking) to prove out fixes to nearly every shared primitive this document defines — Status System, Surface System, Timeline, Empty States, Buttons, Inputs — without requiring the noisier Admin Dashboard rebuild (donut chart, gradient stat tiles, filter-chip sprawl) in the same pass.
- It's scoped tightly enough — one page plus its near-identical sibling `orders/Show.vue` — to ship as a visible, concrete before/after that validates every token and component rule in this document before they propagate to the rest of the product.
