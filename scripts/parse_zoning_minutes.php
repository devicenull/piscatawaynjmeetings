<?php
// Extracts case numbers, applicant names, and addresses from zoning or planning board
// minutes PDFs and saves them to the meeting.metadata column.
// Usage: php parse_zoning_minutes.php <path_to_pdf>

require(__DIR__.'/../init.php');

$pdf = $argv[1] ?? null;
if (!$pdf || !file_exists($pdf)) {
	fwrite(STDERR, "Usage: php parse_zoning_minutes.php <path_to_pdf>\n");
	exit(1);
}

// Auto-detect board type from directory name
$boardType = basename(dirname($pdf));
if (!in_array($boardType, ['zoning', 'planning'])) {
	fwrite(STDERR, "Could not determine board type from path (expected zoning or planning): $pdf\n");
	exit(1);
}

$text = shell_exec('pdf2txt ' . escapeshellarg($pdf));
if (!$text) {
	fwrite(STDERR, "Failed to extract text from: $pdf\n");
	exit(1);
}

// For zoning: hearings come before "ADOPTION OF RESOLUTIONS", so truncate there.
// For planning: "ADOPTION OF RESOLUTIONS" appears before hearings, so truncate at "ADJOURNMENT".
if ($boardType === 'zoning') {
	$cutoff = stripos($text, 'ADOPTION OF RESOLUTIONS');
} else {
	$cutoff = stripos($text, 'ADJOURNMENT');
}
if ($cutoff !== false) {
	$text = substr($text, 0, $cutoff);
}

// Collapse to non-blank lines only
$lines = array_values(array_filter(
	array_map('trim', explode("\n", $text)),
	fn($l) => $l !== ''
));

// Zoning:   25-ZB-80V, 25-ZB-31/32V  (ends with letter(s))
// Planning: 25-PB-02                  (ends with digits)
// Some older PDFs OCR as "19-PB- 43" with a space after the last dash; allow and normalize.
$casePattern = '/^\d{2}-(Z\.?B|PB)-\s*[\d\/]+[A-Z]?$/';

$streetSuffixes = 'Avenue|Street|Drive|Road|Terrace|Court|Lane|Boulevard|Way|Place|Circle|Ave|St|Dr|Rd|Ct|Ln|Blvd';

$results = [];

for ($i = 0; $i < count($lines); $i++) {
	// Hearing items have the case number alone on its own line.
	// Cases in agenda changes or adoption sections are always embedded in longer lines.
	if (!preg_match($casePattern, $lines[$i])) {
		continue;
	}

	$case    = preg_replace('/\s+/', '', $lines[$i]);
	$name    = $lines[$i + 1] ?? '';
	$address = '';

	// Address is within ~6 lines after the case number; it starts with a house number
	for ($j = $i + 1; $j < min($i + 7, count($lines)); $j++) {
		if (preg_match('/^\d+\s+\S.*\b(' . $streetSuffixes . ')\b/i', $lines[$j])) {
			$address = $lines[$j];
			break;
		}
	}

	$results[] = compact('case', 'name', 'address');
}

if (empty($results)) {
	fwrite(STDERR, "No cases found in: $pdf\n");
	exit(1);
}

// Derive the meeting date from the filename (e.g. 2026-01-29.pdf)
$date = pathinfo(basename($pdf), PATHINFO_FILENAME);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
	fwrite(STDERR, "Could not derive date from filename: $pdf\n");
	exit(1);
}

$meeting = new Meeting(['type' => $boardType, 'date' => $date]);
if (!$meeting['MEETINGID']) {
	fwrite(STDERR, "No $boardType meeting found for date: $date\n");
	exit(1);
}

$meeting->set(['metadata' => json_encode($results)]);

foreach ($results as $r) {
	printf("%-20s %-35s %s\n", $r['case'], $r['name'], $r['address']);
}
printf("Saved %d case(s) to meeting %d (%s)\n", count($results), $meeting['MEETINGID'], $boardType);
