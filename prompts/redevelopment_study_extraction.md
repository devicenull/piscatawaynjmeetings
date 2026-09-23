# Redevelopment Study Extraction Prompt

Use with: a "Area In Need of Redevelopment" study or addendum PDF attached.

Feeds the `redevelopment_study` / `redevelopment_study_lot` tables (see `classes/RedevelopmentStudy.php`,
`classes/RedevelopmentStudyLot.php`).

---

You are extracting structured data from a Piscataway, NJ "Area In Need of Redevelopment" study or
addendum PDF, for import into a database. Read the whole document, then output ONLY a JSON object
in this exact shape:

```json
{
  "date": "YYYY-MM-DD",
  "type": "study | addendum",
  "lots": [
    {"block": "1901", "lot": "64.01", "street_address": "44 Stelton Road"}
  ],
  "notes": ""
}
```

Rules:

- **date**: the report date on the cover page (e.g. "March 26, 2026" -> "2026-03-26"). If the cover
  has no date, use the date of the resolution that authorized the study, and say so in `notes`.
- **type**: "addendum" if the document amends/extends a prior study (title contains "Addendum",
  or the text references adding a lot to an existing designated area). Otherwise "study".
- **lots**: one entry per block/lot the document is establishing or amending as part of the study
  area — every block/lot the resolution's "Study Area" covers, not just the lot(s) named in the
  title. Addenda are the tricky case: an addendum titled after an existing area (e.g. "Addendum to
  Block 5701 Lots 11 & 12") may actually be investigating a *different*, newly-added lot (e.g. Block
  7903 Lot 67.01) while re-stating the original lots for context — read the body, not just the
  title, and include ALL lots that make up the resulting study area, each with its own block, lot,
  and street address exactly as the document states them.
- **street_address**: as written in the document (street name + number, no city/state/zip — those
  are assumed to be Piscataway, NJ elsewhere in the system). If a lot's address isn't stated
  anywhere in the document, set it to `null` and flag it in `notes` rather than guessing.
- Do not invent block/lot numbers or addresses not present in the text. If the document is
  ambiguous or you're not confident about a value, say so in `notes` instead of guessing.
- `notes` is a free-text field for anything a human should double check before this is imported
  (ambiguous dates, missing addresses, multiple candidate study areas, OCR garbage, etc). Leave it
  `""` if nothing needs review.

Also suggest a filename in the convention `YYYY-MM-DD-<short description>.pdf` (matching `date`
above), for when the file is placed in `web/files/redevelopment/`.
