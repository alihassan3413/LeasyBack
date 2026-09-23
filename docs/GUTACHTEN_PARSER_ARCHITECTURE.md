# Gutachten Extraction Architecture

How an uploaded appraisal report (*Gutachten*) becomes a reviewable proposal of repair
positions.

**Status (23.09.2026):** the pipeline and the rule-based parser are implemented and
covered by tests. Nothing triggers an extraction yet — there is no route, button or
upload hook that calls `AppraisalExtractionService::start()`, and no way to turn a
proposal into appraisal positions. The AI fallback is a contract with a disabled
implementation. See [Current state](#current-state).

Related: [`GUTACHTEN_PARSER_ACCURACY.md`](GUTACHTEN_PARSER_ACCURACY.md) (validation
against a real report), [`GUTACHTEN_PARSER_FLEETCASE_COMPARISON.md`](GUTACHTEN_PARSER_FLEETCASE_COMPARISON.md)
(comparison with the reference implementation).

---

## 1. Why this exists

`b2b.txt` §8 requires the system to store the appraisal, **extract the repair
positions**, show the original amount per position, use the reduced amount where
applicable, associate damage images, and let the administrator verify and correct the
extracted values.

Until now every position was typed in by hand in the admin order page. The
`b2b_appraisal_positions` table has carried a `source` column with a `manual` /
`extracted` distinction since it was created, but nothing ever wrote `extracted`.

Repair positions are the basis of everything downstream: workshops price them
(`WorkshopQuotation`), the customer offer is built from them (`RepairOfferService`), and
billing follows the accepted offer. An extraction error that reaches a position is
therefore an error that can reach a customer invoice — which is why nothing is applied
without an administrator reviewing it.

---

## 2. Where the Gutachten comes from

Two upload paths, both ending in a `vehicle_report_documents` row on the `documents`
disk:

| Path | Entry point | Result |
|---|---|---|
| Manual upload (both channels) | `POST admin/vehicles/{vehicleId}/reports` → `Admin\VehicleReportController::upload()` → `VehicleReportService::upload()` | `document_type = gutachten`, stored at `vehicle-reports/{auftragsnummer}/{filename}` |
| TÜV SÜD pull (B2C only) | `POST admin/vehicles/{vehicleId}/reports/pull` → `AppraisalDocumentPullService` → `TimService::sync()` → `VehicleReportService::transfer()` | TIM's own `doc_type` (`Bericht` / `AnsichtsFoto`), copied from S3 |

`TimService` parses only header fields (FIN, Gutachtennummer, date, mileage) and photo
URLs from TÜV SÜD's SOAP response. **It never returns repair positions or amounts** —
the PDF is the only source for those. B2B orders never use TÜV SÜD, so extraction has to
work on manually uploaded PDFs as well.

The upload flow is untouched by this work: nothing in `VehicleReportService` knows that
extraction exists.

---

## 3. The AppraisalExtraction pipeline

```
           Admin action (not wired yet)
                      │
                      ▼
      AppraisalExtractionService::start()
        guards → appraisal_extractions row (pending) → job
                      │
                      ▼
          ExtractAppraisalPositions (queue)
                      │
                      ▼
       AppraisalExtractionService::run()
         claim → read file → extract → validate → ready
                      │
        ┌─────────────┴─────────────┐
        ▼                           ▼
  AppraisalDocumentParser      AppraisalAiExtractor
   (PdfGutachtenParser)          (disabled today)
        │                           │
        └─────────────┬─────────────┘
                      ▼
          AppraisalProposalValidator
                      │
                      ▼
        proposal + warnings on the record
                      │
                      ▼
        Admin review → apply (not built yet)
```

### 3.1 The extraction record

`appraisal_extractions` (migration `2026_09_22_160921`), model
`Order\Models\AppraisalExtraction`. One row per extraction run:

| Group | Columns |
|---|---|
| Scope | `order_id`, `auftragsnummer`, `source_document_id` (null on document delete) |
| State | `status`, `source` (`parser` / `ai`), `extractor_version`, `input_sha256`, `attempts` |
| Result | `proposal` (json), `warnings` (json), `error_code`, `error_message` |
| Timing | `started_at`, `completed_at`, `failed_at`, `applied_at`, `discarded_at` |
| Audit | `requested_by_user_id`, `applied_by_user_id`, `discarded_by_user_id`, timestamps |

Statuses and the transitions the model enforces (`AppraisalExtractionStatus`):

```
pending ──► processing ──► ready ──► applied
   │            │            │
   │            │            └────► discarded
   │            ├────► failed ─────► processing (retry) ─► …
   │            └────► pending (crash release)
   └────► failed / discarded
```

`applied` and `discarded` are final. `AppraisalExtraction::transitionTo()` throws
`AppraisalExtractionException::invalidTransition()` on anything else, so no code path can
skip a state.

### 3.2 Starting a run

`AppraisalExtractionService::start()` refuses with HTTP 422 unless all hold:

- the document belongs to the order (same `auftragsnummer` **and** `vehicle_id`);
- the file is a PDF;
- the order status is in `AppraisalPositionService::EDITABLE_STATUSES`
  (`confirmed`, `vehicle_collected`, `inspected`);
- no offer has been accepted for the order.

It then locks the order row, returns any existing **active** run for the same document
(pending, processing or ready) instead of creating a duplicate, writes an
`APPRAISAL_EXTRACTION_REQUESTED` audit entry, and dispatches the job after commit.

### 3.3 Running it

`ExtractAppraisalPositions` is a `ShouldQueue`, `ShouldBeUnique` job: 3 tries, backoff
30 s / 120 s, 180 s timeout, unique per extraction id for 900 s. On final failure its
`failed()` hook records `unexpected_error` — the exception class only, never document
contents.

`AppraisalExtractionService::run()`:

1. **Claim** — atomically move `pending`/`failed` → `processing`, increment `attempts`.
   A run stuck in `processing` for longer than `STALE_PROCESSING_SECONDS` (600) is
   reclaimed; one that is genuinely in flight elsewhere is left alone. `claim()` reports
   whether *this* call took ownership, so two workers cannot process the same run.
2. **Read** the PDF from the `documents` disk into an `AppraisalExtractionInput`
   (contents, SHA-256, file name, and the vehicle's VIN for cross-checking).
3. **Extract**, parser first, AI second (see §4).
4. **Validate** the proposal (see §5).
5. **Store** as `ready` with the source, extractor version, proposal, warnings.

Failure handling distinguishes two kinds:

| Kind | Handling |
|---|---|
| `AppraisalExtractionException` (known) | `failed` with a stable `error_code`. Not retried — a scanned PDF will not become readable on attempt two. |
| Any other `Throwable` | Status released back to `pending` and the exception rethrown, so the queue retries. |

Error codes: `document_missing`, `document_unreadable`, `unsupported_document`,
`extractor_failed`, `no_extractor_available`, `invalid_proposal`, `invalid_transition`,
`unexpected_error`.

---

## 4. Parser-first, AI-fallback

Two contracts, both resolved from the container:

```php
interface AppraisalDocumentParser {
    public function version(): string;
    public function supports(AppraisalExtractionInput $input): bool;
    public function parse(AppraisalExtractionInput $input): AppraisalExtractionResult;
}

interface AppraisalAiExtractor {
    public function version(): string;
    public function isEnabled(): bool;
    public function extract(AppraisalExtractionInput $input): AppraisalExtractionResult;
}
```

`AppraisalExtractionService::extract()` builds an ordered candidate list — the parser if
`supports()` is true, then the AI extractor if `isEnabled()` is true — and tries each in
turn. A candidate counts as successful only when its proposal also **passes validation**,
so a parser that returns implausible amounts falls through to the next candidate rather
than producing a bad proposal. Each failure is recorded as a `parser_failed` / `ai_failed`
warning on the run, and the last exception is what marks it `failed`. With no candidates
at all the run fails as `no_extractor_available`.

### Why the parser goes first

| | Rule-based parser | AI extraction |
|---|---|---|
| Cost | None | Per page, per run |
| Latency | ~0.25 s for a 14-page report | Seconds to minutes |
| Determinism | Same input → same output, always | Varies between runs |
| Failure mode | Misses rows, or refuses the document | Can invent plausible values |
| Data protection | Nothing leaves the server | Customer documents sent to a third party |

Most Gutachten are generated by the same handful of systems, so their tables are regular
enough to parse. The AI path is reserved for what the parser genuinely cannot do —
scanned reports with no text layer, and unfamiliar layouts.

### The AI fallback strategy (not implemented)

`DisabledAppraisalAiExtractor` returns `isEnabled() === false` and throws if called. When
an implementation is added, the structure already dictates:

- it runs **only** when the parser did not produce a valid proposal;
- it produces the same `AppraisalExtractionResult`, so nothing downstream changes;
- it passes the same validator, so its output is held to the same plausibility rules;
- its `version()` is recorded in `extractor_version` for every run.

`supports()` returning false is the handover signal. `PdfGutachtenParser` returns false
for a non-PDF, an oversized file, a missing `pdftotext`, and `PdftotextExtractor` raises
`unsupported_document` when a PDF carries no usable text layer — precisely the scanned
documents where OCR-style extraction earns its cost. **No AI provider dependency exists
in the project**, and adding one needs approval per `CLAUDE.md`, plus a data-protection
decision about sending customer documents to a third party.

---

## 5. The parser

`Order\Services\Extraction\PdfGutachtenParser` composes four collaborators. All
vocabulary and thresholds live in `config/gutachten.php`, so a new layout is a
configuration change rather than a code change.

### 5.1 PdftotextExtractor

Implements `PdfTextExtractor`. Runs `pdftotext -layout -enc UTF-8 -q` through Symfony
Process with a fixed minimal environment, a configurable timeout (30 s), a page cap
(80) and a size cap (50 MB). The bytes are buffered to a temp file removed in a
`finally`; the `%PDF-` magic and size are checked **before** the binary runs.

Pages come from the form feeds `pdftotext` emits, so `page_number` on every extracted
line is a real page. Output below `min_characters` (400) raises
`unsupported_document` — that is the scanned-PDF signal.

> **Deployment requirement:** poppler-utils must be installed on app servers and in CI.
> Without it `isAvailable()` is false, `supports()` is false, and every run fails with
> `no_extractor_available`. Pin the version: `-layout` spacing can shift between
> poppler releases and the parser depends on that spacing.

### 5.2 GutachtenSectionScanner

Walks the page lines with a section state machine — damage table, missing parts, stop,
appendix — skipping configured noise (column headers, footers, page numbers, addresses).

A line becomes a candidate row when it carries **at least two amounts** and starts with a
row number (except in the missing-parts section). Wrapped table cells are repaired
forward (up to 3 following lines) and backward (1 preceding line), preserving a trailing
wrap dash. Rows are deduplicated by row number and capped at 200.

### 5.3 GutachtenRowSplitter

Turns one row into an `AppraisalProposalLine`:

1. **Dash split** — three or more ` - ` parts: last is the repair method, second-to-last
   the damage, the rest the component. Confidence 0.9.
2. **Keyword fallback** — locate a configured repair term, then a damage term before it.
   Confidence 0.7.
3. **Catch-all** — the whole text as the component. Confidence 0.45.
4. **Missing parts** — `"{part}"` + `fehlt` + `ersetzen`. Confidence 0.8.

`stripGroup` collapses a duplicated component-group prefix; a group name split across a
wrap (`Verkleidungen/` + `Abdeckungen`) is rejoined. Values are truncated to the
`AppraisalPositionService` limits (component 255, description 2000, method 255).

**Amount mapping:** the first amount becomes `original_amount_net` (Rep.Kosten), the last
becomes `chargeable_amount_net` (Minderwert) when the two differ; equal amounts leave
chargeable null. *This mapping is still an open business decision* — it determines what
workshops price and what the customer is charged.

### 5.4 GutachtenTotals

Detects the report total by pattern priority (`Gesamtsumme (ohne Mwst.)` >
`Summe Minderwerte` / `abrechnungsrelevante Minderwerte`), sums the lines using the
**effective** amount (chargeable when present, otherwise original — the same rule as
`AppraisalPosition::effectiveAmountNet()`), and emits `missing_positions`,
`sum_exceeds_total` or `appraisal_total_not_found`.

Unlike the FleetCase reference, a shortfall does **not** become a synthetic position: a
position is something a workshop prices and a customer pays for, so a "please check"
placeholder would be quoted on. The administrator gets a warning instead.

### 5.5 Provenance and confidence

Every line carries `page_number`, `source_text` (the original line, truncated to 500
characters) and `confidence`. All money is handled as **decimal strings** with bcmath —
never floats — from parsing through summing to storage.

> Confidence currently reflects *which parse path matched*, not whether the result is
> right. A row can score 0.90 and still be wrong (see the accuracy report). Treat it as a
> review-ordering hint, not a correctness measure.

---

## 6. Validation

`AppraisalProposalValidator` runs on every candidate result.

**Rejections** (throw `invalid_proposal`, next candidate is tried): an empty proposal, or
anything failing `AppraisalPositionService::rules([])` — which is reused directly, so an
extracted proposal can never contain a position a manual save would reject — plus a
strict check that every amount is a plain decimal.

**Warnings** (stored, non-blocking): `chargeable_exceeds_original`, `low_confidence`
(below 0.6), `duplicate_component`, `total_mismatch`, `vin_mismatch` (against the
vehicle record), `unexpected_currency`.

---

## 7. Current state

| Piece | State |
|---|---|
| `appraisal_extractions` table, model, status enum | Done |
| Extraction service, job, retry and staleness handling | Done |
| Contracts, safe defaults, test fakes | Done |
| `PdfGutachtenParser` + four collaborators | Done, bound as the parser |
| Validator | Done |
| Tests | 31 pipeline, 13 parsing unit, 9 extractor, 15 parser tests |
| Validated against a real Gutachten | Once — see the accuracy report |
| **Trigger** (route / button / upload hook) | **Missing** |
| **Apply step** (proposal → appraisal positions) | **Missing** (`applicableProposal()` prepares the guards only) |
| **Review UI** | **Missing** |
| **Image matching** | **Missing** |
| AI fallback implementation | Not started, deliberately |

`AppraisalPositionService` is untouched; no extraction path creates positions today.

---

## 8. Planned improvements

Ordered. Details and evidence in the accuracy report.

**Before anything is triggered in production**

1. **Preserve column structure** from `-layout` instead of collapsing whitespace, or move
   to `pdftotext -tsv`/`-bbox` for real coordinates. This is the root cause of the only
   field-level errors seen so far.
2. **Make confidence meaningful** — derive it from whether cells came from identified
   columns, not from how many dashes a line contains.
3. **Install and pin poppler** in the Docker image and CI, and record `pdftotext -v` in
   `extractor_version`.
4. **Build a golden corpus** of 10–20 real, anonymised Gutachten across appraisers, with
   hand-checked expected output (see `tests/Fixtures/Gutachten/README.md`).
5. **Confirm the amount mapping** (Rep.Kosten → original, Minderwert → chargeable) on a
   document where the two columns differ.

**Then**

6. Column identification from the table header instead of first/last position.
7. Exclude AW / Std / percentage tokens from the money pattern.
8. Per-appraiser configuration profiles, selected by a fingerprint line on page 1.
9. Deterministic image matching from the `Beschädigung N:` captions in the photo
   appendix — no AI needed.
10. The trigger, the apply step and the review UI.

**Only after measurement**

11. Decide whether an AI fallback is needed at all, and for which document types, based
    on the failure rate by `error_code` and the mismatch rate in production.
