<?php
// Extracts PILOT (Payment in Lieu of Taxes) data from budget PDFs.
// Populates pilot_payments table and adds pilot_revenue to budget_stats.
//
// UFB budgets (2017+) contain per-project Long Term Tax Exemption tables.
// Legacy budgets (2022, 2026) only provide aggregate revenue line 08-210.
// Each budget reports PRIOR year data: 2024 budget → 2023 PILOT payments.

require(__DIR__ . '/../init.php');

function extractText(string $pdfPath): string
{
    $escaped = escapeshellarg($pdfPath);
    return shell_exec("gs -dBATCH -dNOPAUSE -sDEVICE=txtwrite -sOutputFile=- $escaped 2>/dev/null") ?? '';
}

function parseDollar(string $s): ?float
{
    $s = preg_replace('/[^0-9.]/', '', $s);
    return $s === '' ? null : (float)$s;
}

function parseDate(string $s): ?string
{
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) return null;
    [$_, $mo, $day, $yr] = $m;
    if ($yr < 2000 || $yr > 2100 || $mo < 1 || $mo > 12 || $day < 1 || $day > 31) return null;
    return sprintf('%04d-%02d-%02d', $yr, $mo, $day);
}

// Map raw PDF names to canonical project names for grouping across years.
function canonicalName(string $raw): string
{
    static $map = [
        // IPT
        'IPT Piscatawat Urban Renewal #1'    => 'IPT Piscataway Project',
        'IPT Piscatawat Urban Renewal #2'    => 'IPT Piscataway Project',
        'IPT Piscatawat Urban Renewal #3'    => 'IPT Piscataway Project',
        'IPT Piscataway Urban Renewal LLC'   => 'IPT Piscataway Project',
        'IPT PISCATAWAY DC URBAN'            => 'IPT Piscataway Project',
        // 100 Ridge Road
        'RAR2-100 Ridge Rd. Urban Renewal LLC' => '100 Ridge Road Project',
        '100 Ridge Road Projec'              => '100 Ridge Road Project',
        // 200 Ridge Road
        '200 Ridge Road Projec'              => '200 Ridge Road Project',
        // 300 Ridge Road
        'RAR2-300 Ridge Rd. Urban Renewal LLC' => '300 Ridge Road Project',
        '300 Ridge Road Projec'              => '300 Ridge Road Project',
        // 400 Ridge Road (known under two earlier names)
        'SHI Piscataway Urban Renewal LLC'         => '400 Ridge Road Project',
        'Piscataway Urban Renewal LLC (400 Ridg'   => '400 Ridge Road Project',
        '400 Ridge Road Projec'                    => '400 Ridge Road Project',
        // 600 Ridge Road
        '600 Ridge Road'                     => '600 Ridge Road Project',
        '600 Ridge Road Projec'              => '600 Ridge Road Project',
        // 800 Centennial
        '800 CENTENNIAL URBAN RE'            => '800 Centennial Project',
        '800 CentennialProject'              => '800 Centennial Project',
        // 2 Turner Drive
        '2 Turner Pl Urban Renewal'          => '2 Turner Drive Project',
        '2 Turner Place Urban Renewal LLC'   => '2 Turner Drive Project',
        // 10 Sterling Drive (earlier name was FC-GEN Real Estate)
        'FC-GEN Real Estate LLC'             => '10 Sterling Drive Project',
        'FC-GEN REAL ESTATE % A ROSSKAMP'    => '10 Sterling Drive Project',
        'FC-GEN REAL ESTATE % A ROSSKAM'     => '10 Sterling Drive Project',  // OCR truncation variant
        // 150 Old New Brunswick Ave
        '150 Old New Brunswick Avenue'       => '150 Old New Brunswick Ave',
        // 330 South Randolphville Ave (various spellings)
        '330 South Randolpsville Ave'        => '330 South Randolphville Ave',
        '330 South Randolpville Avenue'      => '330 South Randolphville Ave',
        // Duke Realty properties
        'Duke Realty 141 Circle Dr. N'          => 'Duke Realty 141 Circle Drive North',
        'Duke Realty, 141 Circle Drive North'   => 'Duke Realty 141 Circle Drive North',
        'Duke Realty, 1570 S. Washington Ave'   => 'Duke Realty 1570 S. Washington Ave',
        'Duke Realty, 1570 S. Washing. Ave'     => 'Duke Realty 1570 S. Washington Ave',
    ];
    return $map[$raw] ?? $raw;
}

// Parse the Long Term Tax Exemption project rows from extracted text.
function extractLongTermProjects(string $text, int $budgetYear): array
{
    $dataYear = $budgetYear - 1;
    $projects = [];

    // Narrow to just the Long Term Exemptions section to avoid false positives
    // from other parts of the budget (property tax breakdown table, etc.)
    $sectionMarker = "Prior Budget Year's Payments in Lieu of Tax (PILOT) - Long Term Tax Exemptions";
    $sectionStart = stripos($text, $sectionMarker);
    if ($sectionStart === false) return [];

    $sectionEnd = stripos($text, 'Total Long Term Exemptions', $sectionStart);
    if ($sectionEnd === false) $sectionEnd = $sectionStart + 5000;
    $section = substr($text, $sectionStart, $sectionEnd - $sectionStart);

    // Skip lines are header/total/meta rows
    $skipPat = '/for data entry|PILOT Billing|Assessed Value|Prior Budget Year|Long Term Tax'
             . '|Sheet UFB|Type of Project|use drop-down|Agreement.*Start|Start Date|End Date/i';

    // Type labels (in order of specificity to avoid false matches)
    // "omm./Indust." handles the OCR artifact where "C" merges into the project name
    $typePats = [
        'Comm\./Indust\.',
        'omm\./Indust\.',   // OCR artifact: "RidgC omm./Indust." → name ends with C
        'Commercial',
        'Other Housing',
        'Other',
    ];

    foreach (explode("\n", $section) as $line) {
        if (preg_match($skipPat, $line)) continue;
        if (!preg_match('/\$[\d,]+/', $line)) continue;  // must have at least one dollar amount
        if (!preg_match('/^\s{5,}/', $line)) continue;   // must have leading indent

        foreach ($typePats as $typePat) {
            if (!preg_match('#(.+?)' . $typePat . '(.*)$#', $line, $m)) continue;

            $rawName = trim($m[1]);
            $remainder = trim($m[2]);

            // Strip OCR artifact: trailing "C" when type was "omm./Indust."
            if ($typePat === 'omm\./Indust\.') {
                $rawName = rtrim($rawName, "C \t");
                $rawName = trim($rawName);
            }

            if (strlen($rawName) < 3) continue;  // too short, try next type pattern
            if (preg_match('/\d{2}-\d{3}/', $rawName)) continue;  // budget line-item code, not a project

            // Extract dates (M/D/YYYY)
            preg_match_all('#\b(\d{1,2}/\d{1,2}/\d{4})\b#', $remainder, $dm);
            $dates = $dm[1];

            // Extract dollar amounts
            preg_match_all('/\$([\d,]+\.\d{2})/', $remainder, $am);
            $amounts = array_map(fn($a) => (float)parseDollar($a), $am[1]);

            if (empty($amounts)) continue;  // no dollar amounts after type, try next type pattern

            $billing = null;
            $assessed = null;
            $taxesFull = null;
            $startDate = null;
            $endDate = null;

            if (count($dates) >= 2) {
                $startDate = parseDate($dates[0]);
                $endDate   = parseDate($dates[1]);
            }

            if (count($amounts) === 3) {
                [$billing, $assessed, $taxesFull] = $amounts;
            } elseif (count($amounts) === 2) {
                // Disambiguate: larger value is assessed, smaller is either billing or taxes
                if ($amounts[0] > $amounts[1]) {
                    // First is larger → (assessed, taxes_full); billing blank
                    $assessed = $amounts[0];
                    $taxesFull = $amounts[1];
                } else {
                    // First is smaller → (billing, assessed); taxes blank
                    $billing  = $amounts[0];
                    $assessed = $amounts[1];
                }
            } elseif (count($amounts) === 1) {
                $assessed = $amounts[0];
            }

            $cleanType = str_replace('omm./Indust.', 'Comm./Indust.', str_replace('\\', '', $typePat));
            $cleanType = str_replace('\.', '.', $cleanType);
            $cleanType = preg_replace('/\\\\/', '', $cleanType);
            // Rebuild clean type name from pattern
            static $typeNames = [
                'Comm\./Indust\.' => 'Comm./Indust.',
                'omm\./Indust\.'  => 'Comm./Indust.',
                'Commercial'      => 'Commercial',
                'Other Housing'   => 'Other Housing',
                'Other'           => 'Other',
            ];
            $cleanType = $typeNames[$typePat] ?? $typePat;

            $canonical = canonicalName($rawName);

            $projects[] = [
                'data_year'    => $dataYear,
                'budget_year'  => $budgetYear,
                'project_name' => $canonical,
                'raw_name'     => ($canonical !== $rawName) ? $rawName : null,
                'type'         => $cleanType,
                'start_date'   => $startDate,
                'end_date'     => $endDate,
                'billing'      => $billing ? (float)$billing : null,
                'assessed'     => $assessed ? (int)$assessed : null,
                'taxes_full'   => $taxesFull ? (float)$taxesFull : null,
            ];
            break; // matched a type pattern, done with this line
        }
    }

    return $projects;
}

// Parse 08-210 PILOT revenue budget line.
// Returns [anticipated_current, anticipated_prior, realized_prior] or nulls.
function extractPilotRevenueLine(string $text, int $budgetYear): array
{
    // Try 08-210 (newer) and 08-118 (2018 only)
    // "Liew" is a typo in the 2022 legacy budget PDF
    if (preg_match('/Payment [Ii]n [Ll]ie[wu] [Oo]f [Tt]axes.*?08-(?:210|118)([\d\s,\.]+)/s', $text, $m)) {
        // The numbers after the item code: current-anticipated  prior-anticipated  prior-actual
        // May contain embedded spaces from OCR (e.g., "2,   000,000.00")
        preg_match_all('/[\d,\s]+\.\d{2}/', $m[1], $nm);
        $vals = [];
        foreach ($nm[0] as $v) {
            $clean = preg_replace('/\s+/', '', $v);
            $f = parseDollar($clean);
            if ($f !== null && $f > 1000) $vals[] = (int)round($f);
        }
        // First 1–3 non-zero values are: anticipated, prior-anticipated, prior-actual
        return array_slice($vals, 0, 3) + [0 => null, 1 => null, 2 => null];
    }
    return [null, null, null];
}

// ─── Main ────────────────────────────────────────────────────────────────────

global $db;

echo "Clearing existing pilot_payments data...\n";
$db->Execute('TRUNCATE TABLE pilot_payments');

$budgetDir = BASE_FILE_PATH . 'budget';
$files = glob("$budgetDir/*.pdf");
sort($files);

// Track anticipated values so we can record prior-year actuals in budget_stats
$pilotRealized    = [];  // data_year => realized amount
$pilotAnticipated = [];  // budget_year => anticipated amount

foreach ($files as $path) {
    $basename = basename($path, '.pdf');
    $budgetYear = (int)substr($basename, 0, 4);
    if ($budgetYear < 2017) continue;  // UFB format started 2017

    echo "Processing {$budgetYear} budget ({$basename})...\n";
    $text = extractText($path);
    if (strlen($text) < 100) {
        echo "  no text extracted\n";
        continue;
    }

    $isUFB = stripos($text, 'Total Taxable Valuation as of') !== false
          || stripos($text, 'USER FRIENDLY BUDGET') !== false;

    if ($isUFB) {
        $projects = extractLongTermProjects($text, $budgetYear);
        echo sprintf("  found %d project entries\n", count($projects));

        foreach ($projects as $p) {
            $db->Execute(
                'INSERT INTO pilot_payments
                   (data_year, source_budget_year, project_name, raw_name, project_type,
                    agreement_start_date, agreement_end_date,
                    pilot_billing, assessed_value, taxes_if_billed_full)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   pilot_billing        = CASE
                       WHEN pilot_billing IS NULL THEN VALUES(pilot_billing)
                       ELSE pilot_billing + COALESCE(VALUES(pilot_billing), 0)
                     END,
                   assessed_value       = CASE
                       WHEN assessed_value IS NULL THEN VALUES(assessed_value)
                       ELSE assessed_value + COALESCE(VALUES(assessed_value), 0)
                     END,
                   taxes_if_billed_full = CASE
                       WHEN taxes_if_billed_full IS NULL THEN VALUES(taxes_if_billed_full)
                       ELSE taxes_if_billed_full + COALESCE(VALUES(taxes_if_billed_full), 0)
                     END,
                   agreement_start_date = COALESCE(VALUES(agreement_start_date), agreement_start_date),
                   agreement_end_date   = COALESCE(VALUES(agreement_end_date), agreement_end_date)',
                [
                    $p['data_year'],
                    $p['budget_year'],
                    $p['project_name'],
                    $p['raw_name'],
                    $p['type'],
                    $p['start_date'],
                    $p['end_date'],
                    $p['billing'],
                    $p['assessed'],
                    $p['taxes_full'],
                ]
            );
        }
    }

    // Parse aggregate PILOT revenue line for budget_stats
    [$anticipated, $priorAnticipated, $priorRealized] = extractPilotRevenueLine($text, $budgetYear);
    if ($anticipated) {
        $pilotAnticipated[$budgetYear] = $anticipated;
        echo sprintf("  anticipated PILOT revenue: $%s\n", number_format($anticipated));
    }
    if ($priorRealized) {
        $dataYear = $budgetYear - 1;
        $pilotRealized[$dataYear] = $priorRealized;
        echo sprintf("  realized PILOT revenue %d: $%s\n", $dataYear, number_format($priorRealized));
    }
}

// Write pilot revenue to budget_stats
echo "\nUpdating budget_stats with PILOT revenue figures...\n";
foreach ($pilotRealized as $year => $realized) {
    $anticipated = $pilotAnticipated[$year] ?? null;
    $db->Execute(
        'INSERT INTO budget_stats (year, pilot_revenue, pilot_revenue_anticipated)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
           pilot_revenue             = VALUES(pilot_revenue),
           pilot_revenue_anticipated = COALESCE(VALUES(pilot_revenue_anticipated), pilot_revenue_anticipated)',
        [$year, $realized, $anticipated]
    );
    printf("  %d: realized=$%s anticipated=%s\n",
        $year,
        number_format($realized),
        $anticipated ? '$' . number_format($anticipated) : 'null'
    );
}

// Anticipated for years where we have current-year data but no next-year to confirm realized
foreach ($pilotAnticipated as $year => $anticipated) {
    if (!isset($pilotRealized[$year - 1])) continue;
    $db->Execute(
        'INSERT INTO budget_stats (year, pilot_revenue_anticipated)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE
           pilot_revenue_anticipated = COALESCE(pilot_revenue_anticipated, VALUES(pilot_revenue_anticipated))',
        [$year, $anticipated]
    );
}

// Backfill agreement dates from years that have them (2024 data from 2025 budget)
// to earlier rows for the same project.
echo "\nBackfilling agreement dates...\n";
$db->Execute(
    'UPDATE pilot_payments pp
     INNER JOIN (
       SELECT project_name, agreement_start_date, agreement_end_date
       FROM pilot_payments
       WHERE agreement_start_date IS NOT NULL
     ) src ON pp.project_name = src.project_name
     SET pp.agreement_start_date = src.agreement_start_date,
         pp.agreement_end_date   = src.agreement_end_date
     WHERE pp.agreement_start_date IS NULL'
);

echo "\nDone.\n";

// Print summary
echo "\n=== PILOT Payments Summary ===\n";
$rows = $db->GetAll('SELECT data_year, COUNT(*) as projects, SUM(pilot_billing) as total_billing
                     FROM pilot_payments GROUP BY data_year ORDER BY data_year');
printf("%-10s %-12s %-20s\n", "Year", "Projects", "Total Billing");
foreach ($rows as $row) {
    printf("%-10d %-12d %s\n",
        $row['data_year'],
        $row['projects'],
        $row['total_billing'] ? '$' . number_format((float)$row['total_billing'], 2) : 'n/a'
    );
}
