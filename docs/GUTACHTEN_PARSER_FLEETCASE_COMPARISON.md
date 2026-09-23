# Gutachten Extraction — FleetCase Comparison

A technical comparison between the FleetCase *Werkstattangebote* prototype and the
LeasyBack extraction pipeline. FleetCase solved the same problem first; this document
records what was taken from it, what was deliberately done differently, and what it does
that LeasyBack still cannot.

**Reference source:** `FleetCase-Werkstattangebote-Quellcode/` — a Next.js / Cloudflare
Workers application, kept outside this repository. The extraction logic is one 90-line
file, `app/admin/gutachten-parser.mjs`, driven by `app/admin/AdminClient.tsx`.

**As of:** 23.09.2026. Companion documents:
[`GUTACHTEN_PARSER_ARCHITECTURE.md`](GUTACHTEN_PARSER_ARCHITECTURE.md),
[`GUTACHTEN_PARSER_ACCURACY.md`](GUTACHTEN_PARSER_ACCURACY.md).

---

## 1. At a glance

| Dimension | FleetCase | LeasyBack |
|---|---|---|
| Where it runs | Admin's browser, `pdfjs-dist` | Server, queued job, `pdftotext -layout` |
| Text model | Text items with x/y, rows grouped by y (±2.2), sorted by x | `-layout` output, one line per row, pages split on `\f` |
| Money | JavaScript float (`parseEuro`) | Decimal strings, bcmath throughout |
| Columns kept | Last amount only (Minderwert) | First → `original_amount_net`, last → `chargeable_amount_net` |
| Position fields | `damageLocation` (component + damage merged, ≤140), `repairMethod` (≤100) | `component` (≤255), `damage_description` (≤2000), `repair_method` (≤255) |
| Metadata | None — vehicle, plate and claim are typed by the admin | Appraisal number, date, VIN, currency |
| Provenance | None | `page_number`, `source_text`, `confidence` |
| Shortfall vs. total | Synthetic "please check" position appended | Warning only |
| Persistence | `items_json` on a `cases` row | `appraisal_extractions` row with full lifecycle and audit |
| Retry / failure model | None — re-upload the PDF | Queue retries, error codes, stale-run recovery |
| Images | Photo pages rendered, redacted, matched to positions | **None** |
| Vocabulary | Hard-coded arrays in the parser file | `config/gutachten.php` |

---

## 2. What FleetCase extracts

- **Damage positions** from the `Wertmindernde Faktoren` table: row number, a merged
  `damageLocation`, a `repairMethod`, and `sourceValue` taken from the **last** amount on
  the line. `targetValue` starts at 0 and is filled in later by the workshop.
- **Missing parts** (`Fehlteile`) as positions: `"{part} – fehlt"` with method `ersetzen`.
- **A report total** by pattern priority, a `reconciled` flag, and a **synthetic filler
  position** carrying any shortfall between the parsed rows and the stated total.
- **Photo pages**: pages containing a large raster image (≥400×260 px, ≥150k px², aspect
  0.25–4) are rendered to canvas at 1.35×, every text item except the captions is painted
  over, and the page is exported as JPEG (max 20 per case).
- **Image-to-position matching** (`assignImagePages`): first by damage number
  (`Beschädigung 3:` or a line starting `3 - …`), then by normalised word containment;
  an ambiguous match is deliberately left unassigned.

It does **not** extract the VIN, appraisal number, date, currency, page numbers,
confidence, or the Rep.Kosten column (parsed, then discarded).

---

## 3. What was reused conceptually

These are FleetCase's domain knowledge, not its implementation, and they carried over:

| Reused | Where it lives now |
|---|---|
| Section state machine and its headings | `config/gutachten.php` → `sections`, applied by `GutachtenSectionScanner` |
| "A table row carries at least two amounts" | `GutachtenSectionScanner` |
| Bidirectional wrapped-row repair | `GutachtenSectionScanner::assemble()` |
| Dash split first, keyword fallback second | `GutachtenRowSplitter` |
| Component-group, repair-term and damage-term vocabularies | `config/gutachten.php` |
| `stripGroup` de-duplication of the group prefix | `GutachtenRowSplitter::stripGroup()` |
| Noise filter and row de-duplication | `GutachtenSectionScanner` |
| Total detection by pattern priority | `GutachtenTotals::detect()` |
| Leaving an ambiguous image match unassigned | Not implemented yet — but the rule is adopted for when it is |

The two FleetCase test fixtures (a wrapped DEKRA table and a multi-page TÜV table) were
ported as PHP tests in `tests/Unit/Order/Extraction/GutachtenParsingTest.php`; they were
already known-good cases.

---

## 4. What was deliberately done differently

**Server-side, queued.** FleetCase parses in the browser because it has no backend
runtime for it. LeasyBack already has a queue, so extraction is a job with retries,
timeouts, audit entries and a persisted lifecycle. The browser concurrency helpers,
`requestAnimationFrame` yields and progress callbacks have no counterpart.

**Decimal strings, not floats.** `parseEuro` returns a JS float and sums with
`toFixed(2)`. Money in LeasyBack is a decimal string end to end, validated and summed
with bcmath, because these amounts reach invoices.

**Both amount columns.** FleetCase keeps only the Minderwert. The LeasyBack model has
`original_amount_net` and `chargeable_amount_net`, so both columns are preserved. *Still
an open business decision* — see the architecture document.

**Component and damage stay separate.** FleetCase concatenates them into a single
140-character field because its schema has one column; LeasyBack has two, with much
larger limits.

**No synthetic filler position.** FleetCase appends a "please check against the
Gutachten" line for any shortfall. In LeasyBack a position is what workshops price and
what the customer is billed for, so a placeholder would be quoted on. `GutachtenTotals`
emits `missing_positions` instead and the administrator decides.

**Provenance and confidence.** Page number, source text and a confidence value per line —
FleetCase has none of these. They exist for the review UI.

**Metadata extraction.** FleetCase's admin types the vehicle details into a form.
LeasyBack reads appraisal number, date, VIN and currency from the document and
cross-checks the VIN against the vehicle record.

**Vocabulary in configuration.** FleetCase hard-codes the arrays; LeasyBack keeps them in
`config/gutachten.php` and records a hash of the rules in `extractor_version`, so any
proposal can be traced to the rules that produced it.

**A defined fallback boundary.** FleetCase has no answer for a scanned PDF. In LeasyBack
`supports()` returning false, or `unsupported_document`, is the documented handover point
to a future AI extractor.

---

## 5. What FleetCase does that LeasyBack cannot

### 5.1 Images — the significant gap

FleetCase links damage positions to photos automatically. LeasyBack has **no image
extraction and no matching at all**: `damage_image_document_ids` is filled in by hand
through `DamageImagePicker` in the admin UI.

This does not need AI. The TÜV SÜD report validated in the accuracy report carries, on
its photo appendix page, five captions of the form:

```
Beschädigung 1: Verkleidungen/Abdeckungen: Heckdeckel, Innenverkleidung - Abrieb - Smart Repair
```

That is damage number, component group and full description — enough to map photos to
positions deterministically (in that document: damage 1 → 2 photos, damage 2 → 3 photos).
The parser currently stops at the appendix and reads none of it. TIM's
`assessment_documents` rows additionally carry `caption`, `image_kind` and `sort_order`
for pulled TÜV SÜD photos, giving a second matching key.

### 5.2 Page rendering and redaction

FleetCase renders photo pages and paints over every price before a workshop sees them,
because the workshop is shown the rendered page image. LeasyBack does not need this
today: workshops see structured positions with a `show_appraisal_amounts` flag, and
damage images are served as separate documents through a token-scoped route. If page
images are ever shown to workshops, `captionTextItemIndexes` becomes relevant again —
and `pdftotext` cannot do it, so a renderer would be required.

### 5.3 Coordinate-level text access

pdf.js gives FleetCase x/y for every text item. `pdftotext -layout` gives text whose
columns are indicated only by runs of spaces — and `PdftotextExtractor` currently
collapses those runs, discarding the very information `-layout` exists to provide. This
is the root cause of the field-level errors recorded in the accuracy report, and the
first planned improvement. `pdftotext -tsv` / `-bbox` would restore coordinates if
needed.

---

## 6. Where LeasyBack is ahead

- **Correct money handling** — decimal strings rather than floats.
- **A real lifecycle** — statuses with enforced transitions, retries, stale-run recovery,
  idempotency, audit entries, per-run error codes.
- **Reuse of the position rules** — the validator runs
  `AppraisalPositionService::rules([])`, so an extracted proposal can never contain
  something a manual save would reject.
- **Plausibility warnings** — VIN mismatch, duplicate components, chargeable above
  original, currency, total mismatch.
- **Review before anything is written** — no proposal becomes a position without an
  administrator applying it (that step is still to be built; FleetCase writes its parsed
  items straight onto the case).
- **Swappable extraction backends** behind contracts, with test fakes.
- **Configuration-driven vocabulary** and a version string that identifies the rules.

---

## 7. Summary

FleetCase is the better reference for *what German appraisal reports look like*: its
section headings, term vocabularies and wrapped-row heuristics were hard-won and are now
LeasyBack's too. LeasyBack is the more robust *system*: correct money types, a durable
lifecycle, validation tied to the position rules, and a defined place for an AI fallback.

The one capability FleetCase has and LeasyBack does not is **image matching**, and the
data needed for it is already in the documents LeasyBack processes.
