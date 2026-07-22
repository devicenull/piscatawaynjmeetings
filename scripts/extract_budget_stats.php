<?php
require(__DIR__.'/../init.php');

// Extracts key financial figures from budget PDFs and stores in budget_stats.
// Handles two formats:
//   UFB (User Friendly Budget, 2017+): all-purposes totals, clearly labeled
//   Legacy NJ format (pre-2017, 2022, 2026): municipal-only totals, different labels

function extractText(string $pdfPath): string
{
	$escaped = escapeshellarg($pdfPath);
	return shell_exec("gs -dBATCH -dNOPAUSE -sDEVICE=txtwrite -sOutputFile=- $escaped 2>/dev/null") ?? '';
}

function parseDollar(string $s): ?int
{
	$s = preg_replace('/[^0-9.]/', '', $s);
	if ($s === '') return null;
	return (int)round((float)$s);
}

// Match a labeled line: "Label ... ESTIMATED|ACTUAL ... $X,XXX.XX"
function ufbLine(string $text, string $label): ?int
{
	$pat = '/' . preg_quote($label, '/') . '.*?(?:ESTIMATED|ACTUAL)\s+\$([\d,]+\.\d{2})/i';
	return preg_match($pat, $text, $m) ? parseDollar($m[1]) : null;
}

function extractUFB(string $text): array
{
	$taxable = $raised = $debt = null;

	if (preg_match('/Total Taxable Valuation as of\s+\w+ \d+,?\s+\d{4}\s+\$([\d,]+\.?\d*)/i', $text, $m))
		$taxable = parseDollar($m[1]);

	if (preg_match('/Total Amount to be Raised by Taxes\s+\$([\d,]+\.\d{2})/i', $text, $m))
		$raised = parseDollar($m[1]);

	// "Total (Current Year)   $X   $Y   $Z" — first dollar figure is gross debt
	if (preg_match('/Total \(Current Year\)\s+\$([\d,]+\.\d{2})/i', $text, $m))
		$debt = parseDollar($m[1]);

	$municipal = ufbLine($text, 'Municipal Purpose Tax');
	$school    = ufbLine($text, 'Local School District');
	$county    = ufbLine($text, 'County Purposes');

	$library   = ufbLine($text, 'Municipal Library');
	$fire      = ufbLine($text, 'Fire Districts (total levies)');
	$coSpace   = ufbLine($text, 'County Open Space');
	$other = ($library ?? 0) + ($fire ?? 0) + ($coSpace ?? 0);

	return [$taxable, $debt, $raised, $municipal, $school, $county, $other ?: null];
}

// Extract total gross debt from a debt statement PDF.
// Formats: "Total $131,130,710.00", "2 Total $131,130,710.00",
//          "Total $   114,       173,057.00" (2016 embedded spaces)
function extractDebtStatement(string $text): ?int
{
	if (preg_match('/^\s*(?:\d+\s+)?Total\s+\$([\d,\s]+\.\d{2})/m', $text, $m))
		return parseDollar($m[1]);
	return null;
}

// Extract Gross Debt totals from an audit's "Summary of Statutory Debt Condition" note.
// Each audit covers two fiscal years (e.g. "YEARS ENDED DECEMBER 31, 2021 AND 2020") and
// contains one table (current year only) or two (current + prior-year comparison). The
// undecorated "General Debt" row (as opposed to the "General Debt:" heading elsewhere in
// the document) is immediately followed by a totals line whose first dollar figure is the
// combined Gross Debt. Some older audits render commas as colons ("127:107,043.52") —
// parseDollar() strips both, so this is harmless as long as the capture group spans them.
// Returns [budgetYear => grossDebt], mapping Dec 31 of $auditYear to budget year+1 and,
// if present, Dec 31 of the prior year to budget year $auditYear itself.
function extractAuditDebt(string $text, int $auditYear): array
{
	$lines = preg_split('/\r?\n/', $text);
	$totals = [];
	foreach ($lines as $i => $line) {
		if (!preg_match('/^\s*General Debt\s{2,}/', $line)) continue;
		for ($j = $i + 1; $j < min($i + 3, count($lines)); $j++) {
			if (preg_match('/\$\s*([\d,:]+\.\d{2})/', $lines[$j], $m)) {
				$totals[] = parseDollar($m[1]);
				break;
			}
		}
	}

	$result = [];
	if (isset($totals[0])) $result[$auditYear + 1] = $totals[0];
	if (isset($totals[1])) $result[$auditYear] = $totals[1];
	return $result;
}

function extractLegacy(string $text): array
{
	$taxable = $raised = $municipal = null;

	// "NET VALUATION TAXABLE   8,610,117,672"
	if (preg_match('/NET VALUATION TAXABLE\s+([\d,]+)/i', $text, $m))
		$taxable = parseDollar($m[1]);

	// Note: legacy "raised" and "debt" are not reliably extractable — leave null.
	// Debt is backfilled from debt statement PDFs instead.

	// Older NJ budget format (2011-2014): OCR produces either spaced words or concatenated words.
	// The prior-year figure always appears first, current-year last — take the last match.
	// Spaced form: "Amount  to  be  Raised  by  Taxation  for  Municipal  Purposes  42,299.xx"
	if (preg_match_all('/Amount\s+to\s+be\s+Raised\s+by\s+Taxation\s+for\s+Municipal\s+Purposes\s+([\d,]+\.\d{2})/i', $text, $m))
		$municipal = parseDollar(end($m[1]));
	// Concatenated form: "AmounttobeRaisedbyTaxationforMunicipalPurposes  35,460.xx" (2012)
	if (!$municipal && preg_match('/AmounttobeRaisedbyTaxationforMunicipalPurposes\s+([\d,]+\.\d{2})/i', $text, $m))
		$municipal = parseDollar($m[1]);
	// All-caps sheet reference form: "AMOUNTTOBERAISEDBYTAXATIONFORMUNICIPALPURPOSES ... 07-190 $ 35,460.xx" (2012)
	// Use [^\n$]* to stay on the same line and avoid grabbing $ amounts from later pages
	if (!$municipal && preg_match('/AMOUNTTOBERAISEDBYTAXATIONFORMUNICIPALPURPOSES[^\n$]*\$\s*([\d,]+\.\d{2})/i', $text, $m))
		$municipal = parseDollar($m[1]);

	return [$taxable, null, $raised, $municipal, null, null, null];
}

global $db;

$budgetDir = BASE_FILE_PATH . 'budget';
$files = glob("$budgetDir/*.pdf");
sort($files);

foreach ($files as $path) {
	$basename = basename($path, '.pdf');
	$year = (int)substr($basename, 0, 4);
	if ($year < 2000) continue;

	echo "Processing $year ($basename)... ";
	$text = extractText($path);
	if (strlen($text) < 100) {
		echo "no text extracted\n";
		continue;
	}

	$isUFB = stripos($text, 'Total Taxable Valuation as of') !== false;
	[$taxable, $debt, $raised, $municipal, $school, $county, $other] = $isUFB ? extractUFB($text) : extractLegacy($text);

	printf("taxable=%s debt=%s raised=%s municipal=%s school=%s county=%s other=%s\n",
		$taxable   ? '$'.number_format($taxable)   : 'null',
		$debt      ? '$'.number_format($debt)      : 'null',
		$raised    ? '$'.number_format($raised)    : 'null',
		$municipal ? '$'.number_format($municipal) : 'null',
		$school    ? '$'.number_format($school)    : 'null',
		$county    ? '$'.number_format($county)    : 'null',
		$other     ? '$'.number_format($other)     : 'null'
	);

	$db->Execute(
		'INSERT INTO budget_stats
		   (year, taxable_valuation, total_debt, amount_raised_by_taxes,
		    municipal_purpose_tax, local_school_district, county_purposes, other_taxes)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
		 ON DUPLICATE KEY UPDATE
		   taxable_valuation     = VALUES(taxable_valuation),
		   total_debt            = VALUES(total_debt),
		   amount_raised_by_taxes = VALUES(amount_raised_by_taxes),
		   municipal_purpose_tax = VALUES(municipal_purpose_tax),
		   local_school_district = VALUES(local_school_district),
		   county_purposes       = VALUES(county_purposes),
		   other_taxes           = VALUES(other_taxes)',
		[$year, $taxable, $debt, $raised, $municipal, $school, $county, $other]
	);
}

// Backfill total_debt from debt statement PDFs for years where it is missing.
// Uses COALESCE so existing budget-PDF debt values are never overwritten.
$debtDir = BASE_FILE_PATH . 'debt_statements';
$debtFiles = glob("$debtDir/*.pdf");
sort($debtFiles);

foreach ($debtFiles as $path) {
	$year = (int)substr(basename($path, '.pdf'), 0, 4);
	if ($year < 2000) continue;

	echo "Debt statement $year... ";
	$text = extractText($path);
	if (strlen($text) < 200) {
		echo "no text extracted\n";
		continue;
	}

	$debt = extractDebtStatement($text);
	if ($debt === null) {
		echo "no total found\n";
		continue;
	}

	printf("debt=%s\n", '$' . number_format($debt));

	$db->Execute(
		'INSERT INTO budget_stats (year, total_debt)
		 VALUES (?, ?)
		 ON DUPLICATE KEY UPDATE
		   total_debt = COALESCE(total_debt, VALUES(total_debt))',
		[$year, $debt]
	);
}

// Backfill total_debt from audit reports' "Summary of Statutory Debt Condition" note.
// Lower priority than the dedicated debt statements above — only fills years those
// couldn't (e.g. when the debt statement PDF itself is a scanned image).
// Uses COALESCE so existing debt statement / budget-PDF values are never overwritten.
$auditDir = BASE_FILE_PATH . 'audits';
$auditFiles = glob("$auditDir/*.pdf");
sort($auditFiles);
$seenAuditYears = [];

foreach ($auditFiles as $path) {
	$auditYear = (int)substr(basename($path, '.pdf'), 0, 4);
	if ($auditYear < 2000) continue;
	if (isset($seenAuditYears[$auditYear])) continue;
	$seenAuditYears[$auditYear] = true;

	echo "Audit $auditYear... ";
	$text = extractText($path);
	$len = strlen($text);
	// Scanned PDFs rendered as bitmaps produce 20M+ chars of whitespace/garbage
	if ($len < 200 || $len > 5_000_000) {
		echo "likely scanned, skipping\n";
		continue;
	}

	$debts = extractAuditDebt($text, $auditYear);
	if (!$debts) {
		echo "no debt table found\n";
		continue;
	}

	foreach ($debts as $budgetYear => $debt) {
		if ($budgetYear < 2008) continue;
		printf("budget %d debt=%s ", $budgetYear, '$' . number_format($debt));
		$db->Execute(
			'INSERT INTO budget_stats (year, total_debt)
			 VALUES (?, ?)
			 ON DUPLICATE KEY UPDATE
			   total_debt = COALESCE(total_debt, VALUES(total_debt))',
			[$budgetYear, $debt]
		);
	}
	echo "\n";
}

// Backfill taxable_valuation from financial statement PDFs.
// NJ financial statements are certified as of Oct 1 of year N and feed the
// year N+1 budget — so we store extracted values into budget year (file year + 1).
// Uses COALESCE so existing budget-PDF values are never overwritten.
$fsDir = BASE_FILE_PATH . 'financial_statements';
$fsFiles = glob("$fsDir/*.pdf");
sort($fsFiles);
$seenFsYears = [];

foreach ($fsFiles as $path) {
	$fsYear = (int)substr(basename($path, '.pdf'), 0, 4);
	if ($fsYear < 2000) continue;
	// Use only the first file per year (e.g. 2010-01-01 before 2010-01-02)
	if (isset($seenFsYears[$fsYear])) continue;
	$seenFsYears[$fsYear] = true;

	$budgetYear = $fsYear + 1;
	if ($budgetYear < 2008) continue;  // outside range of budget_stats
	echo "Financial statement $fsYear → budget $budgetYear... ";
	$text = extractText($path);
	$len = strlen($text);
	// Scanned PDFs rendered as bitmaps produce 20M+ chars of whitespace/garbage
	if ($len < 10000 || $len > 5000000) {
		echo "likely scanned, skipping\n";
		continue;
	}

	// "NET VALUATION TAXABLE YYYY   X,XXX,XXX,XXX"
	// Handles OCR artifacts with extra spaces between words
	if (!preg_match('/NET\s+VALUATION\s+TAXABLE\s+\d{4}\s+([\d,]+)/i', $text, $m)) {
		echo "no valuation found\n";
		continue;
	}
	$taxable = parseDollar($m[1]);
	// Piscataway taxable valuation is always in the billions; reject garbage OCR matches
	if (!$taxable || $taxable < 1_000_000_000) {
		echo "value below sanity threshold, skipping\n";
		continue;
	}

	printf("taxable=%s\n", '$' . number_format($taxable));

	$db->Execute(
		'INSERT INTO budget_stats (year, taxable_valuation)
		 VALUES (?, ?)
		 ON DUPLICATE KEY UPDATE
		   taxable_valuation = COALESCE(taxable_valuation, VALUES(taxable_valuation))',
		[$budgetYear, $taxable]
	);
}

echo "Done.\n";
