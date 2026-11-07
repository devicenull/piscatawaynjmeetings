<?php
/**
 * POST: add a chosen range of a meeting recording to a person's voice profile.
 *
 * Params: MEETINGID, start, end (seconds), speaker_id (slug), display_name
 * build_speaker_profiles.py rebuilds the voiceprint on the next deploy.
 */
require(__DIR__.'/../init.php');

header('Content-Type: application/json');

function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

if (!hasEditAuth()) {
    fail(403, 'Unauthorized');
}

$speaker_id   = trim($_POST['speaker_id'] ?? '');
$display_name = trim($_POST['display_name'] ?? '');
$start        = (float)($_POST['start'] ?? -1);
$end          = (float)($_POST['end'] ?? -1);

if (!preg_match('/^[a-z0-9_]+$/', $speaker_id) || $display_name === '') {
    fail(400, 'Choose a person');
}
// Same minimum as build_speaker_profiles.py, which ignores anything shorter.
if ($start < 0 || $end - $start < 3) {
    fail(400, 'Select at least 3 seconds');
}
if ($end - $start > 300) {
    fail(400, 'Select at most 5 minutes');
}

$meeting = new Meeting(['MEETINGID' => (int)($_POST['MEETINGID'] ?? 0)]);
if (!$meeting['MEETINGID']) {
    fail(404, 'Meeting not found');
}
$board = $meeting['type'];
$date  = explode(' ', $meeting['date'])[0];

$path     = __DIR__.'/../shared/speakers/profiles.json';
$profiles = file_exists($path) ? json_decode(file_get_contents($path), true) : ['speakers' => []];
if (!is_array($profiles)) {
    fail(500, 'profiles.json is unreadable');
}

$speaker = $profiles['speakers'][$speaker_id] ?? ['name' => $display_name, 'boards' => [], 'clips' => []];
$clip    = sprintf('%s/%s:%.1f-%.1f', $board, $date, $start, $end);
if (in_array($clip, $speaker['clips'])) {
    fail(409, 'That clip is already on this profile');
}
$speaker['clips'][] = $clip;
if (!in_array($board, $speaker['boards'] ?? [])) {
    $speaker['boards'][] = $board;
}
$speaker['embedding'] = null;   // tells build_speaker_profiles.py to rebuild
$profiles['speakers'][$speaker_id] = $speaker;

$json = json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
if (file_put_contents($path, $json, LOCK_EX) === false) {
    fail(500, 'Failed to write profiles.json');
}

echo json_encode(['success' => true, 'clip' => $clip]);
