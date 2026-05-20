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

$start = findPos($text, 'each of the following Resolutions, Motions or Proclamations');
$end   = $start !== false ? findPos($text, 'NOW, THEREFORE, BE IT RESOLVED', $start) : false;
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

// Patterns
$letterAlone    = '/^([a-z]{1,2})[.]\s*$/';                           // "a."  "aa."
$resoLine       = '/^(RESOLUTION|MOTION)\s*[-—–]+\s*(.+)/iu';        // "RESOLUTION — Title"
$letterAndReso  = '/^([a-z]{1,2})[.]?\s*(RESOLUTION|MOTION)\s*[-—–]+\s*(.+)/iu'; // "b. RESOLUTION — Title"
$bulletLine     = '/^[•e¢*]\s/u';                                     // bullet sub-items (OCR renders bullets as e or ¢)
$newItemStart   = '/^([a-z]{1,2})[.]?\s*(RESOLUTION|MOTION)/iu';

// Collect a title starting at $idx, appending continuation lines
function collectTitle(array $lines, int $idx, string $initial): array {
	global $letterAlone, $resoLine, $newItemStart, $bulletLine;
	$title = $initial;
	$j = $idx + 1;
	while ($j < count($lines)) {
		$next = $lines[$j];
		if (preg_match($letterAlone, $next))   break;
		if (preg_match($newItemStart, $next))  break;
		if (preg_match($bulletLine, $next))    break;
		// Bare RESOLUTION line without a letter starts a new (unlettered) item
		if (preg_match('/^(RESOLUTION|MOTION)\s*[-—–]+/iu', $next)) break;
		$title .= ' ' . $next;
		$j++;
	}
	return [trim($title), $j];
}

$results = [];
$i = 0;

while ($i < count($lines)) {
	$line = $lines[$i];

	// Case 1: letter and RESOLUTION on the same line — "b. RESOLUTION — Title" or "aa.RESOLUTION — Title"
	if (preg_match($letterAndReso, $line, $m)) {
		$letter = strtolower($m[1]);
		$type   = strtoupper($m[2]);
		[$title, $next] = collectTitle($lines, $i, trim($m[3]));
		$results[] = compact('letter', 'type', 'title');
		$i = $next;
		continue;
	}

	// Case 2: standalone letter followed by RESOLUTION on the next line — "a." then "RESOLUTION — Title"
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

	// Case 3: RESOLUTION line followed by standalone letter on the next line — "RESOLUTION — Title" then "i."
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
