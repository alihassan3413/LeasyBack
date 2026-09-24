# Gutachten parser fixtures

Validation evidence for the Gutachten extraction pipeline
(`App\Modules\UserProfile\Order\Services\Extraction`).

These files are **evidence, not test inputs**. No test loads them today. They exist so
that a change to the parser can be checked against a real appraisal report instead of a
hand-written sample, and so the accuracy report in
[`docs/GUTACHTEN_PARSER_ACCURACY.md`](../../../docs/GUTACHTEN_PARSER_ACCURACY.md) can be
reproduced.

---

## Source of the fixture

| | |
|---|---|
| Document | TÜV SÜD Auto Partner GmbH — *Minderwertgutachten*, Protokollnummer 42772146 |
| Vehicle | VIN `TMBJR7NU9R5024487`, licence plate `EU-ML 4904` |
| Inspection | 18.11.2025, Hamburg Flughafen P8 — report dated 02.12.2025 |
| Pages / size | 14 pages, 3.9 MB, 41 overview photos + 5 damage photos |
| Source PDF | **Not committed.** SHA-256 `ad52815b39e770943c552b10b109472a600eab8e10df262bbaad3347aaf7e2ba` |
| Captured | 23.09.2026 |

The PDF itself is deliberately kept out of the repository: it is 3.9 MB, mostly
photographs, and the parser never sees the binary — only the extracted text.

### Files

| File | What it is |
|---|---|
| `gut.txt` | Verbatim output of `pdftotext -layout` on the source PDF, poppler **26.09.0**. Pages are separated by form feeds (`\f`), exactly as `PdftotextExtractor` receives them. |
| `lines.json` | The proposal lines the parser produced from that PDF, as `AppraisalProposalLine::toArray()`. Includes the two known defects described below. |

Regenerating `gut.txt`:

```bash
pdftotext -layout -enc UTF-8 -q <source.pdf> gut.txt
```

`PdftotextExtractor` additionally collapses runs of spaces and tabs and drops blank
lines, so the text the scanner sees is *not* byte-identical to `gut.txt`. That collapsing
step is the cause of the component/repair-method defect recorded below.

### Data protection

This fixture carries a real vehicle's VIN, licence plate and order reference
(`MW2705350-NT1`). It contains no personal names, addresses, phone numbers or e-mail
addresses. Committing it verbatim was a deliberate decision so the VIN-extraction
assertion could be validated against the real document. Keep that in mind before adding
further samples — see the anonymisation rule below.

---

## Expected parser behaviour

Produced by `PdfGutachtenParser` version
`pdf-gutachten-1/pdftotext-layout-1/502fce3a` (the last segment is a hash of the parsing
rules in `config/gutachten.php`, so it changes whenever those rules change).

### Metadata — all correct

| Field | Value |
|---|---|
| `vin` | `TMBJR7NU9R5024487` |
| `appraisalNumber` | `42772146` (the Protokollnummer) |
| `appraisalDate` | `2025-12-02` |
| `currency` | `EUR` |
| `totalNet` | `200.00` (net; `238,00 €` in the PDF is gross) |

### Positions — 2 of 2 priced rows found, 10 of 12 fields correct

Ground truth, page 3 of the PDF:

```
Nr.  Bauteilgruppe        Beschreibung                                   Rep.Kosten  Minderwert
1    Verkleidungen/       Heckdeckel, Innenverkleidung - Abrieb -          80,00 €     80,00 €
     Abdeckungen          Smart Repair
2    Stossfänger hinten   Stossfänger hinten - verkratzt / verschürft -   120,00 €    120,00 €
                          Smart Repair
```

Position 2 is extracted correctly in every field. **Position 1 is not**, and this is
recorded in `lines.json` on purpose:

| Field | Correct value | `lines.json` |
|---|---|---|
| `component` | `Heckdeckel, Innenverkleidung` | `Verkleidungen/ Heckdeckel, Innenverkleidung` |
| `repair_method` | `Smart Repair` | `Abdeckungen Smart Repair` |

Both amounts, the damage description and the page number are right. The wrapped
`Bauteilgruppe` cell (`Verkleidungen/` + `Abdeckungen`) merges into the neighbouring
description cell once the column whitespace is collapsed.

`chargeable_amount_net` is `null` for both rows because Rep.Kosten and Minderwert are
equal — the parser only fills it when the Gutachten states a reduced amount.

### Other known behaviour on this document

- **No warnings** are produced: the positions sum to `200.00`, matching the stated total.
- The `Ausrüstung / Fahrzeugschlüssel - fehlt` table on page 4 is **not** extracted. It
  carries `0,00 €` amounts, and this layout has no `Fehlteile` heading for the
  missing-parts section to open on.
- The `Auftrags-Nummer / Referenz MW2705350-NT1` is **not** captured; only the
  Protokollnummer is.
- The appendix flag already trips on page 6, on the prose line
  `Anlagen: 41 Übersichtsfotos, 5 Beschädigungsfotos`. Harmless here (the table is on
  page 3), but scanning stops from that point on.
- The `Beschädigungsfotos` section on page 14 carries five captions of the form
  `Beschädigung 1: Verkleidungen/Abdeckungen: Heckdeckel, Innenverkleidung - Abrieb -
  Smart Repair`, mapping damage 1 → 2 photos and damage 2 → 3 photos. The parser reads
  none of it.

When a fix lands, `lines.json` should be regenerated and the differences called out in
the pull request — the two defects above are the first things that should change.

---

## Adding further Gutachten samples

More samples are wanted, especially from other appraisers (DAT, Audatex, GTÜ, KÜS,
Controlexpert) and any DEKRA report, since the parser's vocabulary is currently tuned to
TÜV SÜD and DEKRA layouts.

**One directory per sample**, named `<appraiser>-<reference>/`, for example
`tuv-sued-42772146/`. The two files at the root of this directory belong to the sample
described above and stay where they are for continuity; new samples should not be added
loose alongside them.

Each sample directory contains:

| File | Required | Content |
|---|---|---|
| `gut.txt` | yes | `pdftotext -layout -enc UTF-8 -q` output, poppler version recorded in the README |
| `lines.json` | yes | What the parser produces today, defects included |
| `README.md` | yes | Source, expected behaviour, known defects — same shape as this file |
| `expected.json` | optional | Hand-checked *correct* result, for when these become real test inputs |

Rules:

1. **Never commit the source PDF.** Record its SHA-256 and keep the file elsewhere.
2. **Anonymise unless there is a reason not to.** Replace VIN, licence plate and order
   reference with same-format placeholders (a VIN must stay 17 valid characters, or
   VIN extraction stops being exercised). The sample above is verbatim by explicit
   decision; that is the exception, not the rule.
3. **Never commit a sample containing personal data** — names, addresses, phone numbers,
   e-mail addresses, signatures. Check before adding; appraisal reports sometimes name
   the customer or the assessor.
4. **Record the poppler version.** Spacing in `-layout` output can shift between
   releases, and the parser depends on that spacing.
5. **Record what is wrong, not just what is right.** A fixture that hides current
   defects cannot show when they are fixed.

Related documentation:

- [`docs/GUTACHTEN_PARSER_ARCHITECTURE.md`](../../../docs/GUTACHTEN_PARSER_ARCHITECTURE.md) — how the pipeline fits together
- [`docs/GUTACHTEN_PARSER_ACCURACY.md`](../../../docs/GUTACHTEN_PARSER_ACCURACY.md) — the full validation report for this sample
- [`docs/GUTACHTEN_PARSER_FLEETCASE_COMPARISON.md`](../../../docs/GUTACHTEN_PARSER_FLEETCASE_COMPARISON.md) — comparison with the FleetCase reference implementation
