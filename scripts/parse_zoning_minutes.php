<?php
// Extracts case numbers, applicant names, and addresses from a zoning board minutes PDF
// and saves them to the meeting.metadata column.
// Usage: php parse_zoning_minutes.php <path_to_pdf>

require(__DIR__.'/../init.php');

$pdf = $argv[1] ?? null;
if (!$pdf || !file_exists($pdf)) {
	fwrite(STDERR, "Usage: php parse_zoning_minutes.php <path_to_pdf>\n");
	exit(1);
}

$text = shell_exec('pdf2txt ' . escapeshellarg($pdf));
if (!$text) {
	fwrite(STDERR, "Failed to extract text from: $pdf\n");
	exit(1);
}

// Stop before "ADOPTION OF RESOLUTIONS" — those case references are not hearings
$cutoff = stripos($text, 'ADOPTION OF RESOLUTIONS');
if ($cutoff !== false) {
	$text = substr($text, 0, $cutoff);
}

// Collapse to non-blank lines only
$lines = array_values(array_filter(
	array_map('trim', explode("\n", $text)),
	fn($l) => $l !== ''
));

$streetSuffixes = 'Avenue|Street|Drive|Road|Terrace|Court|Lane|Boulevard|Way|Place|Circle|Ave|St|Dr|Rd|Ct|Ln|Blvd';

$results = [];

for ($i = 0; $i < count($lines); $i++) {
	// A hearing item's case number appears as its own line, e.g. "25-ZB-80V" or "25-ZB-31/32V"
	// Cases in "CHANGES TO AGENDA" or "ADOPTION OF RESOLUTIONS" are always embedded in longer lines
	if (!preg_match('/^\d{2}-ZB-[\d\/]+[A-Z]$/', $lines[$i])) {
		continue;
	}

	$case    = $lines[$i];
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

$meeting = new Meeting(['type' => 'zoning', 'date' => $date]);
if (!$meeting['MEETINGID']) {
	fwrite(STDERR, "No zoning meeting found for date: $date\n");
	exit(1);
}

$meeting->set(['metadata' => json_encode($results)]);

foreach ($results as $r) {
	printf("%-20s %-35s %s\n", $r['case'], $r['name'], $r['address']);
}
printf("Saved %d case(s) to meeting %d\n", count($results), $meeting['MEETINGID']);
