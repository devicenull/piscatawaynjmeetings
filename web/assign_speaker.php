<?php
/**
 * POST: assign a name to a speaker number in a meeting transcript.
 *
 * Params (POST):
 *   MEETINGID    - meeting ID (used to resolve board/date)
 *   speaker_num  - integer speaker number from the Rev.ai transcript
 *   speaker_id   - slug for profiles.json (e.g. "carmichael")
 *   display_name - human-readable label (e.g. "Carmichael (Councilwoman)")
 */
require(__DIR__.'/../init.php');

header('Content-Type: application/json');

if (!hasEditAuth()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$meeting_id   = (int)($_POST['MEETINGID']     ?? 0);
$speaker_num  = (int)($_POST['speaker_num']   ?? -1);
$speaker_id   = trim($_POST['speaker_id']     ?? '');
$display_name = trim($_POST['display_name']   ?? '');

if (!$meeting_id || $speaker_num < 0 || !$speaker_id || !$display_name) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

// Validate speaker_id is a safe slug
if (!preg_match('/^[a-z0-9_]+$/', $speaker_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid speaker_id format']);
    exit;
}

$meeting = new Meeting(['MEETINGID' => $meeting_id]);
if (!$meeting['MEETINGID']) {
    http_response_code(404);
    echo json_encode(['error' => 'Meeting not found']);
    exit;
}

$board = $meeting['type'];
$date  = explode(' ', $meeting['date'])[0];

// Update shared/speakers/{board}/{date}.speakers.json
$speakers_dir  = __DIR__.'/../shared/speakers/'.$board;
$speakers_path = $speakers_dir.'/'.$date.'.speakers.json';

if (!is_dir($speakers_dir)) {
    mkdir($speakers_dir, 0755, true);
}

$speakers = [];
if (file_exists($speakers_path)) {
    $speakers = json_decode(file_get_contents($speakers_path), true) ?? [];
}

$speakers[(string)$speaker_num] = [
    'name'       => $display_name,
    'speaker_id' => $speaker_id,
    'confidence' => 1.0,
    'manual'     => true,
];

if (file_put_contents($speakers_path, json_encode($speakers, JSON_FORCE_OBJECT | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n") === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to write speakers file']);
    exit;
}

echo json_encode([
    'success'          => true,
    'name'             => $display_name,
]);
