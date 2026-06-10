<?php
/**
 * POST: assign a name to a speaker number in a meeting transcript.
 *
 * Params (POST):
 *   MEETINGID    - meeting ID (used to resolve board/date)
 *   speaker_num  - integer speaker number from the Rev.ai transcript
 *   speaker_id   - slug for profiles.json (e.g. "carmichael")
 *   display_name - human-readable label (e.g. "Carmichael (Councilwoman)")
 *   register_clip - 1 to also add the speaker's longest turn as a voice-profile clip
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
$register_clip = !empty($_POST['register_clip']);

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

// 1. Update output/speakers/{board}/{date}.speakers.json
$speakers_dir  = __DIR__.'/../output/speakers/'.$board;
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

if (file_put_contents($speakers_path, json_encode($speakers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n") === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to write speakers file']);
    exit;
}

// 2. Optionally register a clip in data/speakers/profiles.json
$clip_registered = false;
if ($register_clip) {
    $revai_path = __DIR__.'/../web/files/'.$board.'/'.$date.'.revai.json';
    if (file_exists($revai_path)) {
        $revai      = json_decode(file_get_contents($revai_path), true);
        $best_start = null;
        $best_end   = null;
        $best_dur   = 0.0;

        foreach ($revai['monologues'] ?? [] as $mono) {
            if ((int)$mono['speaker'] !== $speaker_num) continue;
            $elems = array_values(array_filter(
                $mono['elements'],
                fn($e) => $e['type'] === 'text' && isset($e['ts'])
            ));
            if (!$elems) continue;
            $start = (float)$elems[0]['ts'];
            $end   = (float)end($elems)['end_ts'];
            $dur   = $end - $start;
            if ($dur > $best_dur) {
                $best_dur   = $dur;
                $best_start = $start;
                $best_end   = $end;
            }
        }

        if ($best_dur >= 5.0 && $best_start !== null) {
            $profiles_path = __DIR__.'/../data/speakers/profiles.json';
            $profiles = ['speakers' => []];
            if (file_exists($profiles_path)) {
                $profiles = json_decode(file_get_contents($profiles_path), true) ?? $profiles;
            }

            if (!isset($profiles['speakers'][$speaker_id])) {
                $profiles['speakers'][$speaker_id] = [
                    'name'      => $display_name,
                    'boards'    => [$board],
                    'clips'     => [],
                    'embedding' => null,
                ];
            } else {
                $profiles['speakers'][$speaker_id]['embedding'] = null;
                if (!in_array($board, $profiles['speakers'][$speaker_id]['boards'] ?? [])) {
                    $profiles['speakers'][$speaker_id]['boards'][] = $board;
                }
            }

            $clip_key = sprintf('%s/%s:%.1f-%.1f', $board, $date, $best_start, $best_end);
            if (!in_array($clip_key, $profiles['speakers'][$speaker_id]['clips'])) {
                $profiles['speakers'][$speaker_id]['clips'][] = $clip_key;
                $clip_registered = true;
            }

            file_put_contents(
                $profiles_path,
                json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n"
            );
        }
    }
}

echo json_encode([
    'success'          => true,
    'name'             => $display_name,
    'clip_registered'  => $clip_registered,
]);
