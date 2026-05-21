<?php
// Extracts lettered resolutions and motions from council meeting minutes PDFs
// and saves them to the meeting.metadata column.
// Usage: php parse_council_minutes.php <path_to_pdf>

require(__DIR__.'/../init.php');

$pdf = $argv[1] ?? null;
if (!$pdf || !file_exists($pdf)) {
	fwrite(STDERR, "Usage: php parse_council_minutes.php <path_to_pdf>\n");
	exit(1);
}

$text = shell_exec('pdf2txt ' . escapeshellarg($pdf));

// If pdf2txt yields no useful text (scanned/image PDF), fall back to Tesseract OCR.
if (!$text || strlen(trim($text)) < 200) {
	$tmpdir = sys_get_temp_dir() . '/council_ocr_' . getmypid();
	mkdir($tmpdir, 0700, true);
	shell_exec('pdftoppm -r 300 ' . escapeshellarg($pdf) . ' ' . escapeshellarg($tmpdir . '/page') . ' 2>/dev/null');
	$pages = glob($tmpdir . '/page-*.ppm');
	sort($pages);
	$text = '';
	foreach ($pages as $page) {
		$out = $tmpdir . '/ocr';
		shell_exec('tesseract ' . escapeshellarg($page) . ' ' . escapeshellarg($out) . ' 2>/dev/null');
		$text .= file_get_contents($out . '.txt');
		unlink($page);
		unlink($out . '.txt');
	}
	rmdir($tmpdir);
	fwrite(STDERR, "Used OCR fallback for: $pdf\n");
}

if (!$text) {
	fwrite(STDERR, "Failed to extract text from: $pdf\n");
	exit(1);
}

// Isolate the consent agenda listing section.
// Use regex to handle double spaces that OCR inserts between words.
function findPos(string $text, string $phrase, int $offset = 0): int|false {
	$pattern = '/\b' . preg_replace('/\s+/', '\s+', preg_quote($phrase, '/')) . '\b/i';
	if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
		return $m[0][1];
	}
	return false;
}

// Try trigger phrases in order of specificity.
// Some minutes say "Resolutions, Motions or Proclamations"; older/reorganization minutes
// say just "Resolutions". Column-split PDFs scramble the phrase, so fall back to the
// preamble sentence that always appears on one column.
$start = findPos($text, 'each of the following Resolutions, Motions or Proclamations');
if ($start === false) {
	$start = findPos($text, 'each of the following Resolutions');
}
if ($start === false) {
	$start = findPos($text, 'part of the Consent Agenda, upon certain conditions');
}

$end = false;
if ($start !== false) {
	$end = findPos($text, 'NOW, THEREFORE, BE IT RESOLVED', $start);
	// Some PDFs end the listing with "The following are the Resolution(s), typed in full"
	if ($end === false) {
		$end = findPos($text, 'The following are the Resolution', $start);
	}
}
if ($start === false || $end === false) {
	fwrite(STDERR, "Could not find consent agenda section in: $pdf\n");
	exit(1);
}
$section = substr($text, $start, $end - $start);

// Normalize double spaces (OCR artifact from two-column layout) and collect non-blank lines
$lines = array_values(array_filter(
	array_map(fn($l) => trim(preg_replace('/  +/', ' ', $l)), explode("\n", $section)),
	fn($l) => $l !== ''
));

// Patterns — support lowercase letters (a.), uppercase (A.), and numbers (1.)
$letterAlone    = '/^([a-zA-Z]{1,2})[.]\s*$/';
$resoLine       = '/^(RESOLUTION|MOTION)\s*[-—–~]+\s*(.+)/iu';
$letterAndReso  = '/^([a-zA-Z]{1,2})[.]?\s*(RESOLUTION|MOTION)\s*[-—–~]+\s*(.+)/iu';
$numberAndReso  = '/^([0-9]+)[.]?\s*(RESOLUTION|MOTION)\s*[-—–~]+\s*(.+)/iu';  // "1. RESOLUTION — Title"
$letterAndTitle = '/^([a-zA-Z]{1,2})[.]\s+(.{5,})/u';  // "a. Appointment of..." (no RESOLUTION prefix)
$bulletLine     = '/^[•e¢*]\s/u';
$newItemStart   = '/^([a-zA-Z]{1,2})[.]?\s*(RESOLUTION|MOTION)/iu';
$newNumberStart = '/^([0-9]+)[.]?\s*(RESOLUTION|MOTION)/iu';

// Collect a title starting at $idx, appending continuation lines
function collectTitle(array $lines, int $idx, string $initial, bool $stopAtLetterTitle = false): array {
	global $letterAlone, $resoLine, $newItemStart, $newNumberStart, $bulletLine, $letterAndTitle;
	$title = $initial;
	$j = $idx + 1;
	while ($j < count($lines)) {
		$next = $lines[$j];
		if (preg_match($letterAlone, $next))    break;
		if (preg_match($newItemStart, $next))   break;
		if (preg_match($newNumberStart, $next)) break;
		if (preg_match($bulletLine, $next))     break;
		if (preg_match('/^(RESOLUTION|MOTION)\s*[-—–~]+/iu', $next)) break;
		// In fallback mode, stop when the next item (letter. title) starts
		if ($stopAtLetterTitle && preg_match($letterAndTitle, $next)) break;
		$title .= ' ' . $next;
		$j++;
	}
	return [trim($title), $j];
}

$results = [];
$i = 0;

while ($i < count($lines)) {
	$line = $lines[$i];

	// Case 1: letter/uppercase and RESOLUTION on same line — "b. RESOLUTION — Title"
	if (preg_match($letterAndReso, $line, $m)) {
		$letter = strtolower($m[1]);
		$type   = strtoupper($m[2]);
		[$title, $next] = collectTitle($lines, $i, trim($m[3]));
		$results[] = compact('letter', 'type', 'title');
		$i = $next;
		continue;
	}

	// Case 2: number and RESOLUTION on same line — "1. RESOLUTION — Title"
	if (preg_match($numberAndReso, $line, $m)) {
		$letter = $m[1];
		$type   = strtoupper($m[2]);
		[$title, $next] = collectTitle($lines, $i, trim($m[3]));
		$results[] = compact('letter', 'type', 'title');
		$i = $next;
		continue;
	}

	// Case 3: standalone letter followed by RESOLUTION on the next line — "a." then "RESOLUTION — Title"
	if (preg_match($letterAlone, $line, $lm)) {
		$j = $i + 1;
		if ($j < count($lines) && preg_match($resoLine, $lines[$j], $m)) {
			$letter = strtolower($lm[1]);
			$type   = strtoupper($m[1]);
			[$title, $next] = collectTitle($lines, $j, trim($m[2]));
			$results[] = compact('letter', 'type', 'title');
			$i = $next;
			continue;
		}
	}

	// Case 4: RESOLUTION line followed by standalone letter on the next line — "RESOLUTION — Title" then "i."
	if (preg_match($resoLine, $line, $m)) {
		$j = $i + 1;
		if ($j < count($lines) && preg_match($letterAlone, $lines[$j], $lm)) {
			$letter = strtolower($lm[1]);
			$type   = strtoupper($m[1]);
			[$title, $next] = collectTitle($lines, $i, trim($m[2]));
			$results[] = compact('letter', 'type', 'title');
			$i = max($next, $j + 1);
			continue;
		}
	}

	$i++;
}

// Fallback for reorganization-meeting format where items lack "RESOLUTION" keyword —
// e.g. "a.  Appointment of Deputy Municipal Clerk." — treat all as RESOLUTION type.
if (empty($results)) {
	$i = 0;
	while ($i < count($lines)) {
		$line = $lines[$i];
		if (preg_match($letterAndTitle, $line, $m)) {
			$letter = strtolower($m[1]);
			$type   = 'RESOLUTION';
			[$title, $next] = collectTitle($lines, $i, trim($m[2]), true);
			$results[] = compact('letter', 'type', 'title');
			$i = $next;
			continue;
		}
		$i++;
	}
}

if (empty($results)) {
	fwrite(STDERR, "No resolutions found in: $pdf\n");
	exit(1);
}

$date = pathinfo(basename($pdf), PATHINFO_FILENAME);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
	fwrite(STDERR, "Could not derive date from filename: $pdf\n");
	exit(1);
}

$meeting = new Meeting(['type' => 'council', 'date' => $date]);
if (!$meeting['MEETINGID']) {
	fwrite(STDERR, "No council meeting found for date: $date\n");
	exit(1);
}

$meeting->set(['metadata' => json_encode($results)]);

foreach ($results as $r) {
	printf("%3s. %-12s %s\n", $r['letter'], $r['type'], $r['title']);
}
printf("Saved %d resolution(s) to meeting %d (council)\n", count($results), $meeting['MEETINGID']);
