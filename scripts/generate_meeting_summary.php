#!/usr/bin/env php
<?php
/**
 * Generate AI-powered section summaries for a meeting transcript.
 * Saves a .sections.json file alongside the .txt transcript.
 *
 * Usage: php scripts/generate_meeting_summary.php <MEETINGID>
 *        php scripts/generate_meeting_summary.php --file /path/to/transcript.txt
 */

require(__DIR__.'/../init.php');

$claude_bin = '/root/.local/bin/claude';

function usage(): never
{
	global $argv;
	fwrite(STDERR, "Usage: {$argv[0]} <MEETINGID>\n");
	fwrite(STDERR, "       {$argv[0]} --file /path/to/transcript.txt\n");
	exit(1);
}

// Parse arguments
$transcript_path = null;
if ($argc === 3 && $argv[1] === '--file') {
	$transcript_path = $argv[2];
} elseif ($argc === 2 && is_numeric($argv[1])) {
	$meeting = new Meeting(['MEETINGID' => (int)$argv[1]]);
	if (!$meeting['MEETINGID']) {
		fwrite(STDERR, "Meeting ID {$argv[1]} not found.\n");
		exit(1);
	}
	$link = $meeting->getLink('transcript');
	if (!$link) {
		fwrite(STDERR, "No transcript found for meeting {$argv[1]}.\n");
		exit(1);
	}
	$transcript_path = realpath(__DIR__.'/../web').'/'.ltrim($link, '/');
} else {
	usage();
}

if (!file_exists($transcript_path)) {
	fwrite(STDERR, "Transcript file not found: $transcript_path\n");
	exit(1);
}

$output_dir    = __DIR__.'/../output';
// Include the parent directory name (meeting type) to avoid cross-type collisions
$sections_name = basename(dirname($transcript_path)).'-'.basename(preg_replace('/\.txt$/', '.sections.json', $transcript_path));
$sections_path = $output_dir.'/'.$sections_name;
$transcript    = file_get_contents($transcript_path);

echo "Transcript: $transcript_path\n";
echo "Output:     $sections_path\n";
echo "Size:       ".number_format(strlen($transcript))." bytes\n\n";

$prompt = <<<PROMPT
Analyze this transcript from a Piscataway, NJ Zoning Board of Adjustment public meeting.

Transcript format per line:
  Speaker N    HH:MM:SS    spoken text

Identify the distinct sections/phases of the meeting. Typical phases include:
- Meeting opening, roll call, Pledge of Allegiance
- Agenda adjustments and postponements (list postponed cases if mentioned)
- Individual case hearings — for each: include the case ID and applicant name in the title, summarize the application, and state the vote result
- Executive session (if any)
- Adjournment

Return ONLY a JSON object. For each section provide:
  title       — Short descriptive title; for case hearings include the case ID (e.g. "26-ZB-03V") and applicant name
  start       — Timestamp of the first line in this section (HH:MM:SS)
  end         — Timestamp of the last line in this section (HH:MM:SS)
  description — 1–3 sentences: what the application was, notable testimony, and vote result if applicable
  cases       — Array of case IDs mentioned in this section (format: "26-ZB-03V"); empty array if none

Sections must be listed in chronological order and must cover the full meeting from beginning to end with no gaps.

TRANSCRIPT:
$transcript
PROMPT;

$schema = json_encode([
	'type'       => 'object',
	'properties' => [
		'sections' => [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'title'       => ['type' => 'string'],
					'start'       => ['type' => 'string', 'pattern' => '^\d{2}:\d{2}:\d{2}$'],
					'end'         => ['type' => 'string', 'pattern' => '^\d{2}:\d{2}:\d{2}$'],
					'description' => ['type' => 'string'],
					'cases'       => ['type' => 'array', 'items' => ['type' => 'string']],
				],
				'required'   => ['title', 'start', 'end', 'description', 'cases'],
			],
		],
	],
	'required'   => ['sections'],
]);

$cmd = implode(' ', [
	escapeshellarg($claude_bin),
	'--print',
	'--output-format', 'json',
	'--model', 'claude-haiku-4-5-20251001',
	'--json-schema', escapeshellarg($schema),
	'--no-session-persistence',
]);

echo "Running Claude Haiku...\n";
$proc = proc_open($cmd, [
	0 => ['pipe', 'r'],
	1 => ['pipe', 'w'],
	2 => ['pipe', 'w'],
], $pipes);

if (!is_resource($proc)) {
	fwrite(STDERR, "Failed to launch claude CLI.\n");
	exit(1);
}

fwrite($pipes[0], $prompt);
fclose($pipes[0]);
$raw    = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

if ($raw === false || $raw === '') {
	fwrite(STDERR, "Empty response from Claude.\n");
	if ($stderr) fwrite(STDERR, "Stderr: $stderr\n");
	exit(1);
}

$envelope = json_decode(trim($raw), true);

if (!is_array($envelope)) {
	// Non-JSON output — check for rate limit keywords
	if (preg_match('/rate.?limit|overload|429|too many/i', $raw)) {
		fwrite(STDERR, "Rate limited.\n");
		exit(2);
	}
	fwrite(STDERR, "Unexpected response from Claude.\nRaw output:\n$raw\n");
	exit(1);
}

if (!empty($envelope['is_error'])) {
	$status = $envelope['api_error_status'] ?? 0;
	if ($status === 429 || preg_match('/rate.?limit|overload|too many/i', $envelope['result'] ?? '')) {
		fwrite(STDERR, "Rate limited (HTTP $status).\n");
		exit(2);
	}
	fwrite(STDERR, "API error (HTTP $status): ".($envelope['result'] ?? $raw)."\n");
	exit(1);
}

if (!isset($envelope['structured_output']['sections'])) {
	fwrite(STDERR, "Missing structured_output in response.\nRaw output:\n$raw\n");
	exit(1);
}

$sections = $envelope['structured_output']['sections'];
echo "Found ".count($sections)." sections:\n";
foreach ($sections as $i => $s) {
	$cases = $s['cases'] ? ' ['.implode(', ', $s['cases']).']' : '';
	printf("  %2d. %s – %s  %s%s\n", $i + 1, $s['start'], $s['end'], $s['title'], $cases);
}

$bytes = file_put_contents($sections_path, json_encode($sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
if ($bytes === false) {
	fwrite(STDERR, "Failed to write: $sections_path\n");
	exit(1);
}
echo "\nSaved: $sections_path ($bytes bytes)\n";
