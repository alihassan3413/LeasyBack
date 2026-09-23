# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Private customers (B2C) — primary audience.** Individuals returning a leased vehicle in the coming months who want to avoid lease-end damage-cost surprises.
- **Company/fleet customers (B2B).** Corporate customers handling multiple leased-vehicle returns per year through a company account, with collective billing, roles, and reporting. An active, growing product line, not a priority replacement for B2C.
- **Workshop partners.** Repair shops that receive quotation requests generated from vehicle appraisals and submit repair offers.
- **LeasyBack administrators.** Internal staff (a main administrator plus internal admins) who coordinate the entire return process on behalf of customers: confirming collection, uploading appraisals, requesting/evaluating workshop quotations, presenting offers, managing statuses, documents, and invoicing.

## Product Purpose

LeasyBack coordinates the full operational process of returning a leased vehicle end-to-end, on behalf of the customer: order creation, vehicle collection, initial appraisal, workshop repair quotations, customer approval of a selected repair offer, workshop commissioning, repair, return to the leasing company, and final appraisal/invoicing. Success means the customer is not surprised by lease-end damage costs and pays only for repairs they actually approve.

## Positioning

A free depreciation/damage assessment ("Minderwert-Gutachten") plus access to a vetted workshop network for repair quotes, so the customer only pays for repairs they choose to commission — positioned as materially cheaper than going through the leasing company's own return process. The landing page currently states "up to 42% savings"; this is present marketing copy, not confirmed as independently validated data — future work must not treat it as proven fact or extend it with additional invented statistics or testimonials.

## Operating Context

- German-market product; customer-facing UI is in German ("Leasingrückgabe").
- B2C and B2B are two operational channels that must stay strictly separate in process, but share one underlying order/timeline model — no duplicate status or task systems (see Capabilities and Constraints).
- B2B company structure: company master data, commercial settings, vehicles, orders, users & roles, service fee agreement, statistics, audit history.
- Vehicle inspection/appraisal involves DEKRA-process and TÜV SÜD-related workflows.
- Payments are processed via Stripe. A Lexware invoicing integration exists in code but was dropped by product decision — not an active integration path.

## Capabilities and Constraints

- **Single source of truth:** every status change, appointment, document, quotation, approval, and admin action belongs to one central order. No duplicate status systems, parallel task systems, or separate copies of order data.
- **Channel separation:** B2B customers never interact directly with inspection stations, workshops, or the leasing company — LeasyBack coordinates entirely on their behalf. B2C and B2B share the same order/timeline renderer but diverge in allowed statuses and fields.
- **B2B roles:** main administrator (unrestricted; only role that manages other internal LeasyBack administrators), company user – full access, company user – read-only, company user – create-orders. Each company must always retain at least one active full-access user.
- **Dropped integration:** Lexware invoicing was explicitly dropped by product decision; do not treat it as a live requirement.

## Brand Commitments

- Existing LeasyBack brand identity and colors must be preserved.
- Investor feedback: the current UI reads as generic/"AI-generated" SaaS (excessive rounded corners, pills, cards-in-cards, gradients, shadows, oversized whitespace, decorative animation). A visual redesign toward a precise, professional, automotive/operational, restrained, structured, confident, European, human-designed character is wanted. Through that redesign: preserve all workflows, permissions, business logic, and existing information architecture (unless there is a clear UX reason to change it), plus accessibility and responsive behavior. The concrete visual direction and system decisions are deliberately not made here — they belong to a dedicated redesign session.

## Evidence on Hand

- Landing page (`resources/js/pages/Welcome.vue` and `resources/js/components/landing/*`) states "kostenloses Minderwert-Gutachten" (free depreciation assessment) and "bis zu 42 % Ersparnis" (up to 42% savings). Treated as current marketing copy, not independently confirmed data.
- `b2b.txt` (repo root) is the authoritative B2B requirements specification.
- `B2B_IMPLEMENTATION_HANDOFF.md` documents B2B implementation history, phase-by-phase, including the dropped Lexware integration and known open items.

## Product Principles

1. One order is the single source of truth — never duplicate status, task, or document systems across channels.
2. B2C and B2B are strictly separated in process but share underlying models; B2B customers never deal directly with stations, workshops, or the leasing company.
3. Customers should never be surprised by lease-end costs — assessment is free, and repair costs require explicit customer approval before commitment.
4. B2C (private customers) is the primary audience; B2B is an active, growing extension, not a priority replacement.
5. Any visual or structural change — including the planned redesign — must preserve existing brand identity, permissions, business logic, information architecture, accessibility, and responsive behavior.

## Accessibility & Inclusion

No formally documented accessibility standard is confirmed. Existing accessibility and responsive behavior are a hard constraint to preserve through any future redesign.
