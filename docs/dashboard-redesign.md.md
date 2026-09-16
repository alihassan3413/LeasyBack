# LeasyBack Dashboard Redesign Spec

This document is the single source of truth for redesigning the customer dashboard ("Mein Dashboard"). It is written so Claude Code can implement it phase by phase. UI copy is German; everything else is English.

---

## 0. How to use this with Claude Code

1. Save this file in the repo as `docs/dashboard-redesign.md`.
2. Add one line to your `CLAUDE.md`: `For any dashboard UI work, follow docs/dashboard-redesign.md. Section 11 is the anti-template checklist.`
3. Run the phases in **Section 10** one at a time. Paste each prompt, review the result, commit, then move on. Do not ask for everything in one prompt.
4. After each phase, have Claude Code take screenshots at 1440px and 390px and check them against **Section 11**.

Anything marked **[VERIFY]** is an assumption about your product or data. Confirm or correct it before Phase 1.

---

## 1. Goals and non-goals

### Goals
- The dashboard answers one question within 3 seconds: **"What do I need to do next, for which car?"**
- Every block on the screen either shows the user's real data or offers an action they can take right now.
- The Leasingrückgabe is explained as **one process with steps**, not as four separate products.
- The screen stops looking like a generic SaaS template (see Section 11).

### Non-goals
- No changes to backend logic, pricing, or the booking flow itself.
- No new features beyond what is needed to show existing data better.
- No marketing content. Selling services belongs on the website or in the empty state, not in a logged-in dashboard for an active customer.

---

## 2. Diagnosis: what is wrong today

| # | Current element | Problem | Decision |
|---|---|---|---|
| 1 | Title "Mein Dashboard" + slogan "Leistung wählen, Fahrzeug zuordnen, Termin buchen." | Generic title, three-part slogan adds nothing | Replace with plain page title "Übersicht". Remove slogan. |
| 2 | FUHRPARK card with "1 Fahrzeug" and a full orange bar | A progress bar for 1 vehicle carries no information; orange reads as a warning | Replace with a vehicle list (Section 5.4). Status distribution bar only when there are 2+ vehicles. |
| 3 | RÜCKGABEN card with "0% abgeschlossen" and an empty bar | Empty KPI, no action, contradicts "Eingeplant 1" | Remove as standalone card. Return progress lives in the timeline (Section 5.5). |
| 4 | Uppercase letter-spaced labels (FUHRPARK, RÜCKGABEN, LEISTUNGEN) | Strongest template tell | Remove all. Use sentence-case section headings only where a heading is actually needed. |
| 5 | Icon inside a pale rounded tile on every card (6 tiles) | Repetitive, decorative, no information | Remove icon tiles. Icons only where they aid scanning (nav, status). |
| 6 | Four equal service cards in a 2x2 grid | Three of four are not actionable; two are sub-steps of the first | Merge Leasingrückgabe, Instandsetzung, Nachkontrolle into one process timeline. Abholung becomes a one-line note. |
| 7 | Exactly three checkmark bullets per card | Artificial symmetry | Remove bullet lists. Describe each step in one sentence inside the timeline. |
| 8 | Pill badges "Teil der Leasingrückgabe", "Bald verfügbar" | Badges explain a structural problem instead of fixing it | Structure removes the need for the first; "Bald verfügbar" survives only on the Abholung note. |
| 9 | Em dash in copy ("Werkstattnetz — nur was…") | Common generated-copy pattern | Rewrite as two plain sentences. |
| 10 | Icon-only sidebar + footer sentence explaining where Aufträge and Fahrzeuge are | Footer is a patch for missing nav labels | Sidebar shows labels on desktop. Delete footer sentence. |
| 11 | Floating "Einführung" button bottom right | Covers content, feels like a chat widget | Move into a help menu in the top bar. |
| 12 | "B2" avatar at bottom of sidebar, logout icon above it | Unclear who "B2" is | Account menu in top bar showing company name, with logout inside. |
| 13 | No license plate, model, dates anywhere | Nothing personal, feels like a demo | Real vehicle data is the core of the new layout. |

**Data inconsistency to resolve [VERIFY]:** Fuhrpark says "Eingeplant 1" while Rückgaben says "Noch keine Rückgabe gestartet". Decide what "Eingeplant" means (vehicle registered with a planned return date, or return booked?) and use one consistent status model (Section 6).

---

## 3. Design direction

### The idea
The product is about handing a car back with a clean paper trail: inspection report, repair, re-inspection, handover protocol. The visual language borrows from that world: **the license plate as the identifier for everything**, and **a clear step-by-step process like a service record**. The design should feel calm, precise and trustworthy, like a well-kept Fahrzeugakte, not like a startup landing page.

### Spend boldness in one place
The one memorable element is the **license plate component** (Section 5.3). It appears wherever a vehicle is referenced. Everything else stays quiet: white surfaces, thin borders, one brand green, one signal color.

### Principles
1. **Data first, explanation second.** Explanations of the service appear inline in the process, not as marketing cards.
2. **One primary action per screen.** Only the "next step" panel has a filled primary button.
3. **Hierarchy through size, weight and space**, not through boxes, tiles and badges.
4. **Status colors carry meaning.** Color is never decoration. Green means done, amber means "your action needed", grey means upcoming.
5. **Empty states give direction.** Every empty state has one sentence and one button.

---

## 4. Design tokens

Keep the existing brand green from the logo; reduce how often the mint accent appears. Replace the current orange (it sits close to a very common generated-UI accent and reads as a warning).

### 4.1 Color

```css
:root {
  /* Brand */
  --lb-green-900: #123331;   /* sidebar background, primary button */
  --lb-green-700: #1F4E48;   /* primary button hover, headings on tint */
  --lb-green-500: #2F9E77;   /* "done" state, active nav indicator ONLY */
  --lb-green-50:  #EAF4F0;   /* next-step panel background */

  /* Neutrals (cool, not cream) */
  --lb-canvas:    #F4F6F5;   /* page background behind content */
  --lb-surface:   #FFFFFF;   /* panels */
  --lb-line:      #D9E0DE;   /* borders, dividers */
  --lb-text:      #14231F;   /* body and headings */
  --lb-text-muted:#5A6B67;   /* secondary text, min 4.5:1 on white */

  /* Signal */
  --lb-amber-700: #9A5B00;   /* "Aktion erforderlich" text */
  --lb-amber-100: #FDF1D8;   /* "Aktion erforderlich" background */
  --lb-red-700:   #A8322A;   /* errors only */

  /* EU plate */
  --lb-plate-blue:#1A4A9C;
}
```

Rules:
- `--lb-green-500` may appear at most on: active nav item, completed timeline steps, success toasts. Not on icons, not on backgrounds.
- No gradients. No colored shadows.
- Dark mode is out of scope unless the app already supports it [VERIFY].

### 4.2 Typography

- **UI font:** keep the current brand sans if it is a deliberate brand choice [VERIFY]. If it was a template default, switch to **Figtree** (Google Fonts) for all UI text.
- **Plate and figures font:** **Barlow Semi Condensed**, weight 600. Used only inside the plate component and for dates/counts inside the timeline. Its DIN-like, signage feel ties to the automotive subject.
- Always enable tabular numbers on dates, counts and plates: `font-variant-numeric: tabular-nums;`
- No all-caps text anywhere. No letter-spaced labels.

Type scale (px / line-height / weight):

| Token | Size | LH | Weight | Use |
|---|---|---|---|---|
| `display` | 30 | 36 | 600 | Page title "Übersicht" |
| `title` | 22 | 28 | 600 | Next-step heading |
| `heading` | 18 | 24 | 600 | Section headings |
| `body` | 16 | 24 | 400 | Default text |
| `body-strong` | 16 | 24 | 600 | Vehicle model, step names |
| `small` | 14 | 20 | 400 | Meta info, helper text |
| `micro` | 13 | 18 | 500 | Status labels |

Line length for any paragraph: max 70ch.

### 4.3 Spacing, radius, elevation

- Spacing scale (px): 4, 8, 12, 16, 24, 32, 48, 64. Nothing in between.
- Page content: max-width 1120px, left-aligned inside the main area, 32px horizontal padding desktop, 16px mobile.
- Vertical rhythm between major sections: 48px.
- **Radius hierarchy** (not one radius on everything):
  - App shell / sidebar: 16px
  - Panels: 12px
  - Buttons, inputs: 8px
  - Status labels, plate: 4px
- **Elevation:** no box shadows on panels. Use `1px solid var(--lb-line)`. Only popovers/menus get a shadow: `0 8px 24px rgba(18, 51, 49, 0.12)`.

### 4.4 Icons
- One icon set, 20px, 1.5px stroke (e.g. Lucide or whatever is already installed [VERIFY]).
- Never place icons inside tinted tiles.
- Icons allowed in: sidebar nav, top bar buttons, timeline step markers (only check mark for done), status labels (optional).

### 4.5 Motion
- No entrance animations on page load. No hover lift on panels.
- Allowed: 150ms color transition on buttons/links, 200ms expand/collapse for timeline step details, menu open/close.
- Respect `prefers-reduced-motion: reduce` by disabling all transitions.

---

## 5. Layout and components

### 5.1 Page structure (desktop, 1 vehicle, return not started)

```
┌──────────────┬──────────────────────────────────────────────────────────┐
│ LeasyBack    │                                   [? Hilfe] [Firma ▾]   │
│              ├──────────────────────────────────────────────────────────┤
│ ▌Übersicht   │  Übersicht                                               │
│  Fahrzeuge   │                                                          │
│  Aufträge    │  ┌────────────────────────────────────────────────────┐  │
│  Team        │  │ Nächster Schritt                                   │  │
│  Berichte    │  │ Gutachtertermin für Ihren VW Golf buchen           │  │
│              │  │ [B·LB 2041]  Leasingende 30.11.2026, in 76 Tagen   │  │
│              │  │                                                    │  │
│              │  │ [ Termin buchen ]   Fahrzeugdetails ansehen        │  │
│              │  └────────────────────────────────────────────────────┘  │
│              │                                                          │
│              │  So läuft Ihre Leasingrückgabe ab                        │
│              │  ┌────────────────────────────────────────────────────┐  │
│              │  │ 1  Termin buchen            ● Aktion erforderlich  │  │
│              │  │ 2  Gutachten                   offen               │  │
│              │  │ 3  Instandsetzung              offen               │  │
│              │  │ 4  Nachkontrolle               offen               │  │
│              │  │ 5  Rückgabe an Leasinggeber    offen               │  │
│              │  └────────────────────────────────────────────────────┘  │
│              │                                                          │
│              │  Ihre Fahrzeuge                     Alle Fahrzeuge       │
│              │  ┌────────────────────────────────────────────────────┐  │
│              │  │ [B·LB 2041]  VW Golf 1.5 TSI   Leasingende 30.11.  │  │
│              │  │              Eingeplant                            │  │
│              │  └────────────────────────────────────────────────────┘  │
│              │                                                          │
│              │  Abholung und Auslieferung ist bald verfügbar.           │
│              │  Wir informieren Sie, sobald Sie Transporte buchen       │
│              │  können.                                                 │
└──────────────┴──────────────────────────────────────────────────────────┘
```

Order of sections is fixed: Next step, Process timeline, Vehicles, Coming-soon note.

### 5.2 App shell

**Sidebar (desktop ≥ 1024px)**
- Width 240px expanded, 72px collapsed. Default: expanded. Remember choice in localStorage.
- Background `--lb-green-900`, text white at 80% opacity, active item white 100%.
- Active item: 3px left bar in `--lb-green-500` plus background `rgba(255,255,255,0.08)`. Remove the current mint filled tile.
- Nav items with labels [VERIFY mapping against current icons]:
  1. Übersicht
  2. Fahrzeuge
  3. Aufträge
  4. Team
  5. Berichte
  6. (Profile icon moves to the account menu, remove from nav)
- Collapsed state shows tooltips with the label on hover and focus.
- Remove the logout icon and "B2" avatar from the sidebar bottom.

**Top bar**
- Height 64px, white, bottom border `--lb-line`.
- Right side: notification bell (plain icon button, no dark filled circle), "Hilfe" menu button, account menu.
- Hilfe menu contains: "Einführung starten", "Häufige Fragen", "Support kontaktieren" [VERIFY which exist].
- Account menu trigger shows company name (e.g. "Muster GmbH") with initials avatar. Menu contains: "Profil", "Einstellungen", "Abmelden".
- Notification bell shows a small numeric badge only when unread count > 0.

**Mobile (< 1024px)**
- Sidebar becomes a bottom tab bar with the 4 most important items (Übersicht, Fahrzeuge, Aufträge, Mehr).
- Top bar keeps logo left, bell and account right.

### 5.3 License plate component `<Plate />`

The signature element. Used in the next-step panel, vehicle list, timeline header, and anywhere a vehicle is referenced.

- Props: `value: string` (e.g. `"B LB 2041"`), `size: "sm" | "md"`.
- Structure: blue band on the left (width 14px sm / 18px md) with small "D" in white, then plate text.
- Plate body: white background, `1.5px solid var(--lb-text)`, radius 4px.
- Text: Barlow Semi Condensed 600, `sm` 14px, `md` 18px, letter-spacing 0.02em, tabular nums, color `--lb-text`.
- Padding: `sm` 2px 8px, `md` 4px 10px.
- Render the separator between city code and letters as a slightly wider gap, not a character.
- Accessibility: `role="img"` and `aria-label="Kennzeichen B LB 2041"`.
- Do not add shadows, gradients, or emboss effects. Keep it flat and clean.

### 5.4 Next-step panel `<NextStepPanel />`

The only place with a filled primary button.

- Background `--lb-green-50`, no border, radius 12px, padding 32px (24px mobile).
- Content:
  - Small label: "Nächster Schritt" (`small`, `--lb-text-muted`, sentence case)
  - Heading: action + vehicle, e.g. "Gutachtertermin für Ihren VW Golf buchen" (`title`)
  - Meta row: `<Plate size="sm" />`, then "Leasingende 30.11.2026, in 76 Tagen" (`small`)
  - If the deadline is within 30 days, the meta text switches to `--lb-amber-700` and reads "Leasingende in 21 Tagen. Buchen Sie zeitnah, damit genug Zeit für Reparaturen bleibt."
  - Actions: primary button (filled `--lb-green-900`, white text, 44px height) plus text link secondary.
- Content is derived from state (Section 6). If multiple vehicles need action, show the most urgent one and a link "2 weitere Fahrzeuge benötigen Ihre Aktion".

### 5.5 Return process timeline `<ReturnTimeline />`

Replaces the three service cards. This is a real sequence, so numbered steps are appropriate.

- Section heading: "So läuft Ihre Leasingrückgabe ab" when no return is active; "Rückgabe VW Golf" with `<Plate size="sm" />` when a return is active.
- Panel: white, border, radius 12px.
- Each step is a row: step marker, name, status label, and an expandable description.
- Vertical connector line 2px between markers, `--lb-line`; segment between two completed steps turns `--lb-green-500`.

Step marker states:

| State | Marker | Status label |
|---|---|---|
| `done` | Filled green circle with white check | "Erledigt am 04.10.2026" (muted) |
| `action_required` | Amber ring, number inside | Amber label "Aktion erforderlich" |
| `in_progress` | Dark green ring, number inside | "Läuft" |
| `upcoming` | Grey ring, number inside | "Offen" (muted) |
| `skipped` | Grey dashed ring | "Nicht nötig" (muted) |

Steps and descriptions (German copy, one or two plain sentences each):

1. **Termin buchen**
   Wählen Sie einen Termin für die Begutachtung. Wir holen das Fahrzeug an Ihrem Standort ab oder Sie bringen es zur Prüfstation. [VERIFY: pickup is "bald verfügbar", so for now: "Sie bringen das Fahrzeug zur Prüfstation."]
2. **Gutachten**
   Ein unabhängiger Gutachter prüft das Fahrzeug nach den Bedingungen Ihres Leasingvertrags. Das Gutachten finden Sie danach in Ihrem Konto.
3. **Instandsetzung**
   Wir holen Angebote aus unserem Werkstattnetz ein. Repariert wird nur, was die Leasingbedingungen verlangen, und erst nach Ihrer Freigabe.
   - When offers are ready, state becomes `action_required` with button "Angebote vergleichen".
   - If the report shows no damage, state becomes `skipped`.
4. **Nachkontrolle**
   Derselbe Gutachter prüft die Reparatur und erstellt ein Nachgutachten als Nachweis.
5. **Rückgabe an Leasinggeber**
   Das Fahrzeug wird mit Übergabeprotokoll an Ihren Leasinggeber übergeben.

Behavior:
- The current step is expanded by default; others are collapsed and expand on click (button with `aria-expanded`).
- When no return is active, all steps show descriptions collapsed and state `upcoming`, except step 1 which is `action_required` if a vehicle exists.
- Steps with a document (Gutachten, Nachgutachten, Übergabeprotokoll) show a text link "Gutachten öffnen" when `done`.

### 5.6 Vehicle list `<VehicleList />`

Replaces the Fuhrpark KPI card.

- Heading row: "Ihre Fahrzeuge" left, text link "Alle Fahrzeuge" right (only if > 3 vehicles).
- Panel with rows separated by 1px dividers. Show max 3 rows, sorted by urgency (Section 6.2).
- Row content (desktop, one line): `<Plate size="md" />`, model (`body-strong`), status label, "Leasingende 30.11.2026" right-aligned, chevron icon. Whole row is a link to the vehicle detail page.
- Row content (mobile): plate and model on line 1, status and date on line 2.
- Status summary bar: render **only if 2 or more vehicles**. Segmented horizontal bar, 8px height, with a legend in plain text below: "3 eingeplant, 1 in Reparatur, 2 zurückgegeben". Segment colors: upcoming grey, in progress `--lb-green-700`, action required amber, done `--lb-green-500`.

### 5.7 Coming-soon note

- Plain text paragraph, `small`, `--lb-text-muted`, no card, no icon tile, no badge.
- Copy: "Abholung und Auslieferung ist bald verfügbar. Wir benachrichtigen Sie, sobald Sie Transporte buchen können."
- Optional text link: "Benachrichtigung aktivieren" [VERIFY if this exists; otherwise omit].

### 5.8 Removed components
Delete from the dashboard (and delete the component files if they are unused elsewhere):
- Fuhrpark KPI card
- Rückgaben KPI card
- ServiceCard grid (all four)
- Uppercase section eyebrow component
- Footer navigation hint sentence
- Floating "Einführung" button

---

## 6. State model

### 6.1 Vehicle and return statuses [VERIFY against backend enums]

Use one consistent set of labels everywhere (dashboard, vehicle page, orders page):

| Backend status (assumed) | UI label | Color |
|---|---|---|
| `registered` | Eingeplant | grey |
| `appointment_booked` | Termin gebucht | dark green |
| `inspection` | Im Gutachten | dark green |
| `offers_ready` | Angebote freigeben | amber |
| `repair` | In Reparatur | dark green |
| `reinspection` | In Nachkontrolle | dark green |
| `returned` | Zurückgegeben | green |

"Eingeplant" means: vehicle is registered with a lease end date, no return process started. This resolves the contradiction in the current UI.

### 6.2 Urgency order (for next-step panel and vehicle list sorting)
1. Any `offers_ready` (user must approve)
2. `registered` with lease end in ≤ 30 days
3. `registered`, sorted by lease end ascending
4. Active returns (`appointment_booked` through `reinspection`), sorted by lease end
5. `returned`, most recent first

### 6.3 Dashboard states

| State | Next-step panel | Timeline | Vehicle list |
|---|---|---|---|
| **A. No vehicles** | Heading "Fügen Sie Ihr erstes Fahrzeug hinzu", text "Danach buchen Sie den Termin für die Begutachtung.", button "Fahrzeug hinzufügen" | Shown, all steps `upcoming`, as explanation | Hidden |
| **B. Vehicles, no return started** | "Gutachtertermin für Ihren [Modell] buchen", button "Termin buchen" | Generic, step 1 `action_required` | Shown |
| **C. Return active, waiting on LeasyBack** | Heading "Ihre Rückgabe läuft", text "Wir melden uns, sobald die Angebote vorliegen.", no primary button, secondary link "Auftrag ansehen" | For that vehicle, current step `in_progress` | Shown |
| **D. Return active, user action needed** | "Angebote für Ihren [Modell] freigeben", button "Angebote vergleichen" | Step 3 `action_required` | Shown |
| **E. All vehicles returned** | "Alle Fahrzeuge sind zurückgegeben", button "Fahrzeug hinzufügen" | Hidden | Shown with returned status |
| **Loading** | Skeleton blocks matching final layout, no shimmer animation | Skeleton | Skeleton |
| **Error** | Inline message "Die Übersicht konnte nicht geladen werden. Prüfen Sie Ihre Verbindung und laden Sie die Seite neu." with button "Neu laden" | Hidden | Hidden |

When multiple returns are active, the timeline shows the most urgent one and a select control above it: "Rückgabe für" + dropdown of plates.

---

## 7. Copy deck (German)

Tone: formal "Sie", plain verbs, sentence case, no slogans, no em dashes, no arrows in button or link text. An action keeps its name through the flow ("Termin buchen" button leads to a confirmation "Termin gebucht").

| Key | Text |
|---|---|
| `page.title` | Übersicht |
| `nextStep.label` | Nächster Schritt |
| `nextStep.book.heading` | Gutachtertermin für Ihren {model} buchen |
| `nextStep.book.cta` | Termin buchen |
| `nextStep.approve.heading` | Angebote für Ihren {model} freigeben |
| `nextStep.approve.cta` | Angebote vergleichen |
| `nextStep.running.heading` | Ihre Rückgabe läuft |
| `nextStep.running.text` | Wir melden uns, sobald {nextMilestone}. |
| `nextStep.empty.heading` | Fügen Sie Ihr erstes Fahrzeug hinzu |
| `nextStep.empty.text` | Danach buchen Sie den Termin für die Begutachtung. |
| `nextStep.empty.cta` | Fahrzeug hinzufügen |
| `nextStep.allDone.heading` | Alle Fahrzeuge sind zurückgegeben |
| `nextStep.secondary` | Fahrzeugdetails ansehen |
| `nextStep.more` | {count} weitere Fahrzeuge benötigen Ihre Aktion |
| `meta.leaseEnd` | Leasingende {date}, in {days} Tagen |
| `meta.leaseEndUrgent` | Leasingende in {days} Tagen. Buchen Sie zeitnah, damit genug Zeit für Reparaturen bleibt. |
| `timeline.heading.generic` | So läuft Ihre Leasingrückgabe ab |
| `timeline.heading.active` | Rückgabe {model} |
| `timeline.status.done` | Erledigt am {date} |
| `timeline.status.action` | Aktion erforderlich |
| `timeline.status.running` | Läuft |
| `timeline.status.upcoming` | Offen |
| `timeline.status.skipped` | Nicht nötig |
| `vehicles.heading` | Ihre Fahrzeuge |
| `vehicles.all` | Alle Fahrzeuge |
| `comingSoon.pickup` | Abholung und Auslieferung ist bald verfügbar. Wir benachrichtigen Sie, sobald Sie Transporte buchen können. |
| `error.load` | Die Übersicht konnte nicht geladen werden. Prüfen Sie Ihre Verbindung und laden Sie die Seite neu. |
| `error.retry` | Neu laden |
| `help.menu` | Hilfe |
| `help.tour` | Einführung starten |
| `account.logout` | Abmelden |

If the app uses an i18n system, add these keys there. Otherwise put them in one `dashboard.copy.ts` file.

---

## 8. Data requirements [VERIFY]

The dashboard needs these fields per vehicle. Have Claude Code check which already exist in the API.

```ts
type Vehicle = {
  id: string;
  plate: string;            // "B LB 2041"
  make: string;             // "VW"
  model: string;            // "Golf 1.5 TSI"
  leaseEndDate: string;     // ISO date
  lessor?: string;          // Leasinggeber name
  status: VehicleStatus;    // see 6.1
};

type ReturnProcess = {
  id: string;
  vehicleId: string;
  steps: Array<{
    key: "booking" | "inspection" | "repair" | "reinspection" | "handover";
    state: "done" | "action_required" | "in_progress" | "upcoming" | "skipped";
    completedAt?: string;
    documentUrl?: string;
  }>;
};
```

If fields are missing, show the component without them (e.g. no lease end text) rather than inventing placeholders. Do not ship fake data.

Derive next step and urgency in one pure function `getDashboardState(vehicles, returns)` with unit tests for states A to E.

---

## 9. Accessibility and responsive

- Color contrast: all text ≥ 4.5:1, large text and UI borders of interactive elements ≥ 3:1.
- Status is never color-only: every status has a text label.
- Visible focus ring on all interactive elements: `outline: 2px solid var(--lb-green-700); outline-offset: 2px;`
- Keyboard: sidebar, menus, timeline expanders and vehicle rows fully operable; menus close on Escape and return focus to trigger.
- Semantic structure: one `h1` ("Übersicht"), `h2` for section headings, timeline as `ol`.
- Hit targets ≥ 44x44px on touch.
- Breakpoints: 390px (mobile), 768px (tablet), 1024px (sidebar appears), 1440px (reference desktop).
- Nothing scrolls horizontally at 390px. Plates never wrap.
- `lang="de"` on the document.

---

## 10. Implementation phases (prompts for Claude Code)

Run each prompt separately. Commit after each phase.

### Phase 0: Audit (no code changes)
```
Read docs/dashboard-redesign.md fully. Do not change any code yet.
Inspect the repo and report:
1. Framework, styling approach (Tailwind, CSS modules, etc.), component library, icon set.
2. File path of the current dashboard page and every component it uses.
3. Where design tokens/colors are currently defined.
4. Which fields from Section 8 already exist in the API/types and which are missing.
5. The real vehicle status enum values, mapped against Section 6.1.
6. Every item marked [VERIFY] with what you found.
Then propose a file-by-file plan for Phases 1 to 6.
```

### Phase 1: Tokens and fonts
```
Implement Section 4 of docs/dashboard-redesign.md.
Add the color, type, spacing, radius tokens in the project's existing styling system
(extend Tailwind config if Tailwind is used, otherwise CSS variables).
Load Barlow Semi Condensed (600) and, only if we decided to switch, Figtree.
Do not restyle any pages yet. Show me a summary of the token names you added.
```

### Phase 2: App shell
```
Implement Section 5.2 of docs/dashboard-redesign.md: sidebar with labels and collapse,
top bar with Hilfe menu and account menu, mobile bottom tab bar.
Remove the floating Einführung button, the sidebar avatar and logout icon.
Keep all existing routes working. Check keyboard navigation and Escape handling on menus.
Take screenshots at 1440px and 390px when done.
```

### Phase 3: State logic and Plate
```
Implement Section 6 and Section 5.3 of docs/dashboard-redesign.md.
1. Create getDashboardState(vehicles, returns) as a pure function returning the
   state (A to E), the next-step content and sorted vehicles. Add unit tests for all states
   and the urgency ordering in 6.2.
2. Create the <Plate /> component exactly as specified, including aria-label.
Use the copy keys from Section 7.
```

### Phase 4: Next-step panel and vehicle list
```
Implement Sections 5.4 and 5.6 of docs/dashboard-redesign.md using getDashboardState.
Replace the Fuhrpark and Rückgaben cards on the dashboard.
Status summary bar only renders with 2 or more vehicles.
Add loading skeletons and the error state from 6.3.
Screenshots at 1440px and 390px for states A, B and D (use test fixtures, not production data).
```

### Phase 5: Return timeline and cleanup
```
Implement Sections 5.5, 5.7 and 5.8 of docs/dashboard-redesign.md.
Replace the four service cards with <ReturnTimeline /> and the coming-soon note.
Delete the removed components if nothing else imports them.
Remove the page subtitle slogan and the footer hint sentence.
Screenshots at 1440px and 390px for states B, C and D.
```

### Phase 6: Review pass
```
Review the finished dashboard against Section 9 and Section 11 of docs/dashboard-redesign.md.
Take screenshots at 390, 768, 1024 and 1440px.
List every checklist item as pass or fail with evidence, then fix the failures.
Run the linter, type checker and tests.
```

---

## 11. Anti-template checklist

The redesign is not done until every item passes.

**Typography and labels**
- [ ] No uppercase or letter-spaced labels anywhere on the dashboard.
- [ ] No eyebrow label above headings except "Nächster Schritt".
- [ ] No single highlighted word in a heading.
- [ ] Tabular numbers on all dates, counts, plates.

**Structure**
- [ ] No grid of equal-sized feature cards.
- [ ] No icons inside tinted rounded tiles.
- [ ] No lists of exactly three checkmark bullets.
- [ ] Numbered markers appear only in the timeline, because it is a real sequence.
- [ ] Only one filled primary button visible at a time.
- [ ] Radius differs by hierarchy (shell 16, panel 12, control 8, label/plate 4).
- [ ] No box shadows on panels, no gradients.

**Content**
- [ ] No slogan or marketing sentence in the logged-in dashboard.
- [ ] No em dashes in UI copy.
- [ ] No arrows appended to button or link text.
- [ ] No middle-dot separated meta strings.
- [ ] Every empty state has one sentence and one action.
- [ ] Real vehicle data (plate, model, lease end) visible in states B to E.
- [ ] Status labels identical across dashboard, vehicle page and orders page.

**Behavior and quality**
- [ ] No page-load or scroll entrance animations.
- [ ] `prefers-reduced-motion` respected.
- [ ] Keyboard focus visible on every interactive element.
- [ ] No horizontal scroll at 390px.
- [ ] Unit tests for getDashboardState pass for states A to E.

**Final gut check**
- [ ] Cover the logo: could this screen belong to any other SaaS product? If yes, the plate and timeline are not prominent enough.
- [ ] Remove one more thing that is not needed.

---

## 12. Open questions for the product owner

1. What exactly does "Eingeplant" mean in the backend, and when does a return count as "gestartet"?
2. Do customers typically have 1 vehicle or many? If fleets of 20+ are common, the vehicle list needs filtering and the dashboard should lead with fleet status instead of a single next step.
3. Is the current body font a brand decision or a template default?
4. Does the Hilfe menu have destinations beyond the Einführung tour?
5. Can users sign up for a notification when Abholung and Auslieferung launches?
6. Is the lease end date always known when a vehicle is added?