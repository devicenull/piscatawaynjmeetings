#!/usr/bin/env php
<?php
/**
 * Extract block/lot/address data from a redevelopment study PDF using Claude,
 * per prompts/redevelopment_study_extraction.md, and optionally import it.
 *
 * Usage: php scripts/extract_redevelopment.php <path/to/file.pdf> [--import] [--force]
 *
 * --import requires the file to already be sitting in web/files/redevelopment/
 * (place it there first, named YYYY-MM-DD-description.pdf).
 */

require(__DIR__.'/../init.php');

$claude_bin = '/root/.local/bin/claude';

function usage(): never
{
	global $argv;
	fwrite(STDERR, "Usage: {$argv[0]} <path/to/file.pdf> [--import] [--force]\n");
	exit(1);
}

$pdf_path = null;
$do_import = false;
$force = false;
foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--import') $do_import = true;
	else if ($arg === '--force') $force = true;
	else if ($pdf_path === null) $pdf_path = $arg;
	else usage();
}
if (!$pdf_path) usage();
if (!file_exists($pdf_path)) {
	fwrite(STDERR, "File not found: $pdf_path\n");
	exit(1);
}
$pdf_path = realpath($pdf_path);

$prompt_template = file_get_contents(__DIR__.'/../prompts/redevelopment_study_extraction.md');
$prompt = $prompt_template."\n\nThe PDF is at: $pdf_path\nRead it, then output ONLY the JSON object described above.";

$schema = json_encode([
	'type'       => 'object',
	'properties' => [
		'date'  => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
		'type'  => ['type' => 'string', 'enum' => ['study', 'addendum']],
		'lots'  => [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'block'          => ['type' => 'string'],
					'lot'            => ['type' => 'string'],
					'street_address' => ['type' => ['string', 'null']],
				],
				'required'   => ['block', 'lot', 'street_address'],
			],
		],
		'notes' => ['type' => 'string'],
	],
	'required'   => ['date', 'type', 'lots', 'notes'],
]);

$cmd = implode(' ', [
	escapeshellarg($claude_bin),
	'--print',
	'--output-format', 'json',
	'--model', 'claude-sonnet-5',
	'--json-schema', escapeshellarg($schema),
	'--allowedTools', 'Read',
	'--permission-mode', 'bypassPermissions',
	'--no-session-persistence',
]);

echo "Reading $pdf_path with Claude...\n";
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
	fwrite(STDERR, "Unexpected response from Claude.\nRaw output:\n$raw\n");
	exit(1);
}
if (!empty($envelope['is_error'])) {
	fwrite(STDERR, "API error: ".($envelope['result'] ?? $raw)."\n");
	exit(1);
}
if (!isset($envelope['structured_output'])) {
	fwrite(STDERR, "Missing structured_output in response.\nRaw output:\n$raw\n");
	exit(1);
}

$data = $envelope['structured_output'];

echo "\nDate:  {$data['date']}\n";
echo "Type:  {$data['type']}\n";
echo "Lots:\n";
$needs_review = false;
foreach ($data['lots'] as $lot) {
	$addr = $lot['street_address'] ?? null;
	if (!$addr) { $addr = '(missing)'; $needs_review = true; }
	printf("  Block %s, Lot %s -- %s\n", $lot['block'], $lot['lot'], $addr);
}
if ($data['notes']) {
	echo "\nNotes: {$data['notes']}\n";
	$needs_review = true;
}

if (!$do_import) {
	echo "\n(dry run -- pass --import to write this to the database)\n";
	exit(0);
}

if ($needs_review && !$force) {
	fwrite(STDERR, "\nFlagged for manual review (see above) -- not importing. Fix the data or re-run with --force.\n");
	exit(1);
}

$redev_dir = realpath(__DIR__.'/../web/files/redevelopment');
if (!$redev_dir || dirname($pdf_path) !== $redev_dir) {
	fwrite(STDERR, "\nFile must be placed in web/files/redevelopment/ before importing (found it in ".dirname($pdf_path).").\n");
	exit(1);
}

$filename = basename($pdf_path);
$study = new RedevelopmentStudy(['filename' => $filename]);
if (!$study->isInitialized()) {
	if (!$study->add(['date' => $data['date'], 'filename' => $filename])) {
		fwrite(STDERR, "Failed to add study: {$study->error}\n");
		exit(1);
	}
	$study = new RedevelopmentStudy(['filename' => $filename]);
}

$existing_lots = $study['lots'];
if ($existing_lots && !$force) {
	fwrite(STDERR, "\nStudy already has ".count($existing_lots)." lot(s) recorded -- not touching them. Use --force to replace.\n");
	exit(1);
}
if ($existing_lots && $force) {
	foreach ($existing_lots as $lot) $lot->delete();
}

foreach ($data['lots'] as $lot) {
	$row = new RedevelopmentStudyLot([]);
	$row->add([
		'STUDYID'        => $study['STUDYID'],
		'block'          => $lot['block'],
		'lot'            => $lot['lot'],
		'street_address' => $lot['street_address'],
	]);
}

echo "\nImported STUDYID {$study['STUDYID']} (".count($data['lots'])." lot(s)).\n";
