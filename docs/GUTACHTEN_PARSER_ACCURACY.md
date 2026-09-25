# Gutachten Parser — Accuracy Report

Validation of `PdfGutachtenParser` against a real TÜV SÜD appraisal report.

**Run on:** 23.09.2026
**Parser version:** `pdf-gutachten-1/pdftotext-layout-1/502fce3a`
**Tooling:** poppler 26.09.0 (`pdftotext -layout -enc UTF-8 -q`)
**Evidence:** [`tests/Fixtures/Gutachten/`](../tests/Fixtures/Gutachten/README.md) —
`gut.txt` (extracted text) and `lines.json` (parser output)

Companion documents: [`GUTACHTEN_PARSER_ARCHITECTURE.md`](GUTACHTEN_PARSER_ARCHITECTURE.md),
[`GUTACHTEN_PARSER_FLEETCASE_COMPARISON.md`](GUTACHTEN_PARSER_FLEETCASE_COMPARISON.md).

---

## Summary

| Measure | Result |
|---|---|
| Positions found | **2 of 2** priced rows (100 %) |
| Field accuracy | **10 of 12** checked fields (83 %) |
| Metadata | **4 of 4** correct |
| Total detection | Correct — net, not gross |
| Warnings raised | **None** |
| Runtime | 0.25 s end to end (`pdftotext` itself 21 ms) for a 14-page, 3.9 MB PDF |

Both priced positions were found with correct amounts and page numbers, and every
metadata field is right. **One position has a corrupted component and repair method, and
no warning was raised** — a silent error, which is the worst failure mode in an
administrator-reviewed flow.

---

## 1. The document

TÜV SÜD Auto Partner GmbH *Minderwertgutachten*, Protokollnummer 42772146: 14 pages,
3.9 MB, 41 overview photos and 5 damage photos, vehicle VIN `TMBJR7NU9R5024487`,
inspected 18.11.2025, report dated 02.12.2025. Full provenance in the fixture README.

---

## 2. Metadata

| Field | In the PDF | Parser | Verdict |
|---|---|---|---|
| VIN | `FIN: TMBJR7NU9R5024487` | `TMBJR7NU9R5024487` | Correct |
| Appraisal number | `PROTOKOLLNUMMER: 42772146` | `42772146` | Correct |
| Date | `Datum: 02.12.2025` | `2025-12-02` | Correct |
| Currency | `€` throughout | `EUR` | Correct |
| Total (net) | `abrechnungsrelevante Minderwerte 200,00 €` | `200.00` | Correct — `238,00 €` is the gross figure and was not taken |

Metadata extraction is robust on this layout because the footer repeats FIN,
Protokollnummer and date on every page, and the parser scans the first four pages.

**Not captured:** the document also states `Auftrags-Nummer / Referenz MW2705350-NT1`
on pages 1 and 2. The parser has no pattern for it, so the reference is lost. Whether it
matters depends on whether that number is needed to reconcile with TÜV SÜD orders — worth
deciding before the trigger is built.

---

## 3. Appraisal positions

Ground truth, page 3 of the PDF (`gut.txt`, lines 161–170):

```
Nr.  Bauteilgruppe        Beschreibung                                   Rep.Kosten  Minderwert

1    Verkleidungen/       Heckdeckel, Innenverkleidung - Abrieb -          80,00 €     80,00 €
     Abdeckungen          Smart Repair

2    Stossfänger hinten   Stossfänger hinten - verkratzt / verschürft -   120,00 €    120,00 €
                          Smart Repair

Summe (netto):                                                            200,00 €    200,00 €
```

### Position 1 — Verkleidungen/Abdeckungen

| Field | Correct | Parser | Verdict |
|---|---|---|---|
| component | `Heckdeckel, Innenverkleidung` | `Verkleidungen/ Heckdeckel, Innenverkleidung` | **Wrong** — group fragment leaked in |
| damage_description | `Abrieb` | `Abrieb` | Correct |
| repair_method | `Smart Repair` | `Abdeckungen Smart Repair` | **Wrong** — group fragment leaked in |
| original_amount_net | 80,00 € | `80.00` | Correct |
| chargeable_amount_net | 80,00 € (equal) | `null` | Correct by design |
| page_number | 3 | `3` | Correct |

Confidence reported: **0.90**.

### Position 2 — Stossfänger hinten

| Field | Correct | Parser | Verdict |
|---|---|---|---|
| component | `Stossfänger hinten` | `Stossfänger hinten` | Correct — duplicated group collapsed |
| damage_description | `verkratzt / verschürft` | `verkratzt / verschürft` | Correct |
| repair_method | `Smart Repair` | `Smart Repair` | Correct |
| original_amount_net | 120,00 € | `120.00` | Correct |
| chargeable_amount_net | 120,00 € (equal) | `null` | Correct by design |
| page_number | 3 | `3` | Correct |

Confidence reported: **0.90**.

`chargeable_amount_net` is null in both rows because Rep.Kosten and Minderwert are equal;
the parser fills it only when the report states a reduced amount. As a consequence **the
amount-column mapping was not exercised by this document** — validating it needs a
Gutachten where the two columns differ.

### Root cause of the position 1 defect

With `-layout`, the wrapped continuation line holds two cells separated only by spaces:

```
        Abdeckungen                     Smart Repair
```

`PdftotextExtractor` collapses runs of spaces and tabs into one space, so the column
boundary is destroyed before the scanner sees the line, which becomes the single string
`Abdeckungen Smart Repair`. The scanner appends it to row 1, the dash split then yields
three parts, and `Abdeckungen` ends up attached to the repair method.

The `rejoinSplitGroup()` guard added for exactly this case did not fire: it expects the
second half of a split group name at the start of the **damage description**, not at the
start of the **repair method**.

Position 2 survived only because its continuation line contained a single cell.

In short: the parser discards the column information that `-layout` exists to provide and
then tries to reconstruct it with dash heuristics.

---

## 4. What else the parser did

**Not extracted — the second table on page 4:**

```
1    Ausrüstung    Fahrzeugschlüssel - fehlt - entkleben/     0,00 €    0,00 €
                   neutralisieren
```

Missed for two independent reasons: the section never opened (this layout has **no**
`Fehlteile` heading anywhere — the word does not occur in the document; the table sits
under a bare `Nr. Bauteilgruppe …` header, which is in the noise list), and both amounts
are `0,00 €` so the row would have been dropped anyway. Harmless here because the value is
zero, but in this layout a *priced* missing-parts table would be missed entirely.

**Totals.** The match came from `abrechnungsrelevante Minderwerte` on the summary page.
The in-table `Summe (netto):` line is **not** among the configured total patterns, so
without the summary page no total would have been found. Reconciliation is genuinely
correct: 80 + 120 = 200 = the stated total, hence no warning.

**Appendix flag fires early.** The appendix pattern already matches on page 6, on the
prose line `Anlagen: 41 Übersichtsfotos, 5 Beschädigungsfotos`, so scanning stops from
there. No loss here — the damage table is on page 3 — but in a layout where that line
appears before the table, the parser would silently extract nothing.

---

## 5. Photos, damage numbers and image matching

| Question | Finding |
|---|---|
| Is there a `Beschädigungsfotos` section? | Yes — the real heading is on page 14, holding all five damage photos |
| Are damage numbers present? | Yes: `Beschädigung 1: Verkleidungen/Abdeckungen: Heckdeckel, Innenverkleidung - Abrieb - Smart Repair` |
| Can photos be mapped to positions? | Yes, unambiguously: damage 1 → 2 photos, damage 2 → 3 photos (5 total, matching `Anlagen: 41 Übersichtsfotos, 5 Beschädigungsfotos`) |
| Does the parser use any of it? | **No.** The appendix is skipped and the proposal carries no image data |

The captions repeat both the component group and the full description, so even a
word-based fallback would match. This is achievable deterministically, without AI.

---

## 6. Risk assessment against this document

Predictions made before the run, and how they held up:

| Predicted risk | Outcome |
|---|---|
| Wrapped-row repair mixes text between cells | **Confirmed, and worse than expected** — multi-column continuation lines corrupt two fields |
| Layout-specific vocabulary | **Confirmed** — no `Fehlteile` heading; `Summe (netto)` is not a known total pattern |
| `stripGroup` heuristics lose information | **Held up** — correctly collapsed the duplicated `Stossfänger hinten` |
| Column semantics (Rep.Kosten vs Minderwert) | **Untested** — both columns are equal in this report |
| AW / Std tokens read as money | Not triggered here |
| Row-number de-duplication across tables | Not triggered — the second table was skipped for other reasons |
| Silent errors | **Confirmed** — the corrupted position scored 0.90 with no warning |

---

## 7. Findings

Ranked by impact. None of these are fixed yet.

1. **Preserve column structure from `-layout`** instead of collapsing whitespace — or
   switch to `pdftotext -tsv` / `-bbox` for real coordinates. Root cause of the only
   field-level errors in this run, and a column-aware scanner would remove most of the
   dash heuristics.
2. **Confidence is not yet trustworthy.** A corrupted row scored 0.90 because the dash
   split "succeeded". It should reflect whether cells came from identified columns.
3. **Add this layout's vocabulary**: `Summe (netto)` as a total pattern, and the
   heading-less second table as a missing-parts section.
4. **Narrow the appendix trigger** so a prose mention of `Beschädigungsfotos` cannot end
   scanning; require a standalone heading.
5. **Image matching is achievable now, deterministically**, from the `Beschädigung N:`
   captions.
6. **Decide on `Auftrags-Nummer / Referenz`** — capture it or consciously ignore it.
7. **The Rep.Kosten / Minderwert mapping still needs a document where the columns
   differ** before it can be considered validated.

---

## 8. Reproducing this report

```bash
# 1. Text extraction (poppler 26.09.0)
pdftotext -layout -enc UTF-8 -q <source.pdf> gut.txt

# 2. Parser output — resolve AppraisalDocumentParser from the container,
#    build an AppraisalExtractionInput from the PDF bytes, call parse().
#    The proposal lines are what tests/Fixtures/Gutachten/lines.json holds.
```

The source PDF is not committed; its SHA-256 is recorded in the fixture README. Both
evidence files were produced by the run described above and should be regenerated
whenever the parser changes, with the differences called out in the pull request.

**Environment note.** poppler is a deployment requirement, not just a development
convenience: without `pdftotext` every extraction fails with `no_extractor_available`.
Pin the version in CI and the production image — `-layout` spacing can shift between
poppler releases, and the parser depends on that spacing.
