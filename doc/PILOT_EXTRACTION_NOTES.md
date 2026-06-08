# PILOT Data Extraction Notes

## What gets extracted

The script `extract_pilot_data.php` pulls two things from budget PDFs:

1. **Per-project Long Term Tax Exemption data** → `pilot_payments` table  
   Each row: project name, data year, billing amount, assessed value, taxes-if-full, agreement dates.

2. **Aggregate PILOT revenue line (08-210)** → `budget_stats.pilot_revenue` and `pilot_revenue_anticipated`  
   Captures how much the town actually collected in PILOT revenue each year.

---

## Budget format by year

### UFB format (User Friendly Budget) — 2017 to 2025 budgets

Sheet UFB-5 has the **5-year exemption summary** (mostly zeros for Piscataway).  
Sheet UFB-6 has the **Long Term Tax Exemption table** — this is where per-project data lives.

The extraction looks for the header:
> "Prior Budget Year's Payments in Lieu of Tax (PILOT) - Long Term Tax Exemptions"

and reads lines until:
> "Total Long Term Exemptions"

**Important**: each budget reports the **prior year's** payments. The 2024 budget (file `2024-01-01.pdf`) contains 2023 PILOT data. So `data_year = budget_year - 1`.

#### Line formats within the section

**2017–2024 budgets** (no agreement dates):
```
[project name]   [Comm./Indust. | Other | Other Housing]   $billing   $assessed   $taxes_if_full
```

**2025 budget onward** (includes agreement dates):
```
[project name]   [Commercial | Other Housing]   [start M/D/YYYY]   [end M/D/YYYY]   $billing   $assessed   $taxes_if_full
```

Agreement start/end dates are only available in 2025+ budgets. The script backfills them to earlier rows for the same canonical project name.

#### OCR artifacts to watch for

- **"C omm./Indust."** — when a line wraps at the column boundary, the `C` from `Comm.` attaches to the end of the project name (e.g., `400 RidgC omm./Indust.`). The script strips trailing `C` from names matched by the `omm./Indust.` pattern.
- **Truncated names** — some names are cut off (e.g., `100 Ridge Road Projec` → `100 Ridge Road Project`). Handled by the canonical name map.
- **Embedded spaces in dollar amounts** — OCR sometimes produces `2,   000,000.00`. The revenue parser strips all whitespace before parsing dollar values.

### Legacy NJ format — 2022 and 2026 budgets

No per-project PILOT detail. Only the aggregate 08-210 revenue line is present. The 2022 budget has a typo: "Payment in Liew of Taxes" — the regex handles this as `[wu]`.

---

## Database tables

### `pilot_payments`
One row per (data_year, canonical project name). Columns:

| Column | Description |
|---|---|
| `data_year` | Year the payment was made (budget file year minus 1) |
| `source_budget_year` | Which budget PDF it came from |
| `project_name` | Canonical name (see mapping below) |
| `raw_name` | Original name from PDF, if different from canonical |
| `project_type` | Commercial / Other Housing / Comm./Indust. / Other |
| `agreement_start_date` | From 2025 budget; backfilled to earlier rows |
| `agreement_end_date` | From 2025 budget; backfilled to earlier rows |
| `pilot_billing` | Amount paid under PILOT |
| `assessed_value` | Full assessed value of the property |
| `taxes_if_billed_full` | What taxes would be at the full combined rate |

When multiple rows for the same canonical project appear in one year (e.g., IPT split into 3 buildings in 2018 data), the script accumulates billing and assessed value via `ON DUPLICATE KEY UPDATE` with addition.

### `budget_stats` additions
Two new columns:

| Column | Description |
|---|---|
| `pilot_revenue` | Actual PILOT revenue realized for this year (from the following year's budget) |
| `pilot_revenue_anticipated` | Anticipated PILOT revenue budgeted for this year |

Known gaps in `pilot_revenue`:
- 2017 realized (~$270,973) — the 2018 budget uses line code 08-118 instead of 08-210 and the two-column layout made disambiguation hard
- 2018 realized — not present in the 2019 budget text
- 2024 realized — the 2025 budget uses a summarized revenue format without per-line detail

---

## Canonical project name map

Projects have had different legal names across years. The mapping in `canonicalName()`:

| Raw name(s) | Canonical name |
|---|---|
| IPT Piscatawat Urban Renewal #1/#2/#3, IPT Piscataway Urban Renewal LLC, IPT PISCATAWAY DC URBAN | **IPT Piscataway Project** |
| RAR2-100 Ridge Rd. Urban Renewal LLC, 100 Ridge Road Projec | **100 Ridge Road Project** |
| RAR2-300 Ridge Rd. Urban Renewal LLC, 300 Ridge Road Projec | **300 Ridge Road Project** |
| SHI Piscataway Urban Renewal LLC, Piscataway Urban Renewal LLC (400 Ridg...), 400 Ridge Road Projec | **400 Ridge Road Project** |
| 600 Ridge Road, 600 Ridge Road Projec | **600 Ridge Road Project** |
| 800 CENTENNIAL URBAN RE, 800 CentennialProject | **800 Centennial Project** |
| 2 Turner Pl Urban Renewal, 2 Turner Place Urban Renewal LLC | **2 Turner Drive Project** |
| FC-GEN Real Estate LLC, FC-GEN REAL ESTATE % A ROSSKAMP/ROSSKAM | **10 Sterling Drive Project** |
| 150 Old New Brunswick Avenue | **150 Old New Brunswick Ave** |
| 330 South Randolpsville Ave, 330 South Randolpville Avenue | **330 South Randolphville Ave** |
| Duke Realty 141 Circle Dr. N, Duke Realty, 141 Circle Drive North | **Duke Realty 141 Circle Drive North** |
| Duke Realty, 1570 S. Washington Ave, Duke Realty, 1570 S. Washing. Ave | **Duke Realty 1570 S. Washington Ave** |

**Note on agreement start dates vs first payment dates:**  
Several projects show agreement start dates in 2022 even though payments appear in 2019–2020 data (e.g., 300 Ridge Road at 4/1/2022, 400 Ridge Road at 1/1/2022). These dates likely reflect when original agreements were formally revised or restructured — the pre-2022 payments were under predecessor agreements or during construction phases.

**Projects without canonical mapping (kept as raw):**
- `Piscataway Bldg I Urban Renewal` — appears in 2018 data only; represents Ridge Road predecessor entities before they separated into distinct agreements. No billing recorded — likely the pre-payment construction phase.
- `Kiss Logistics Urban Renewal LLC` — active 2019–2020, unclear successor. In 2019 data the assessed value ($65.2M) matches 600 Ridge Road's later assessment; but in 2020 data both Kiss Logistics ($21.7M) and 600 Ridge Road ($65.2M) appear as separate entities. Kept separate pending clarification.
- `Genesis Skilled Nursing Facility` — active 2017 data only, type "Other".

---

## Running / re-running the script

```bash
php scripts/extract_pilot_data.php
```

The script `TRUNCATE`s `pilot_payments` at the start so it's safe to re-run. Budget PDFs must be extracted to `/tmp/budget_YYYY.txt` via ghostscript, which the script does automatically.

### When a new budget PDF arrives

1. Drop the PDF as `web/files/budget/YYYY-01-01.pdf`
2. Re-run `php scripts/extract_pilot_data.php`

For UFB years, per-project data is extracted automatically. For legacy NJ years (like 2026), only the aggregate 08-210 line is captured.

If a new project appears with an unfamiliar name, check whether it maps to an existing canonical project (same address, similar PILOT billing history) and add an entry to the `$map` array in `canonicalName()`. If it's genuinely new, it will be stored under its raw name automatically.

### If OCR fails to extract text from a PDF

Some scanned PDFs produce garbage text. The script checks `strlen($text) < 100` and skips. If a PDF has actual project data but OCR is failing, try:
```bash
gs -dBATCH -dNOPAUSE -sDEVICE=txtwrite -sOutputFile=/tmp/test.txt web/files/budget/YYYY-01-01.pdf
wc -c /tmp/test.txt
cat /tmp/test.txt | grep -i "PILOT\|Long Term"
```

---

## Data completeness by year

| Data Year | Per-project data | Total billing | Source budget |
|---|---|---|---|
| 2016 | None | — | 2017 UFB (no projects yet) |
| 2017 | 2 projects | $423,703 | 2018 UFB |
| 2018 | 4 projects | $600,571 | 2019 UFB |
| 2019 | 8 projects | $2,402,804 | 2020 UFB |
| 2020 | 9 projects | $3,382,292 | 2021 UFB |
| 2021 | **None** | — | 2022 legacy format — no per-project data |
| 2022 | 12 projects | $4,153,838 | 2023 UFB |
| 2023 | 14 projects | $5,679,930 | 2024 UFB |
| 2024 | 15 projects | $7,173,879 | 2025 UFB |
