<?php
/**
 * GET: detailed waveform for part of a meeting recording, for the voice clip popup.
 * The stored .peaks.json has one peak per second, too coarse for picking a clip.
 *
 * Params: MEETINGID, start (seconds), len (seconds, at most 300)
 * Returns: {"start": N, "per_second": 50, "peaks": [0..1, ...]}
 */
require(__DIR__.'/../init.php');

header('Content-Type: application/json');

if (!hasEditAuth()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

const PEAKS_PER_SECOND = 50;
const PEAK_SAMPLE_RATE = 2000;

$meeting = new Meeting(['MEETINGID' => (int)($_GET['MEETINGID'] ?? 0)]);
if (!$meeting['MEETINGID']) {
    http_response_code(404);
    echo json_encode(['error' => 'Meeting not found']);
    exit;
}

$base = __DIR__.'/files/'.$meeting['type'].'/'.explode(' ', $meeting['date'])[0];
$recording = null;
foreach (['mp3', 'm4a'] as $ext) {
    if (file_exists($base.'.'.$ext)) {
        $recording = $base.'.'.$ext;
        break;
    }
}
if (!$recording) {
    http_response_code(404);
    echo json_encode(['error' => 'No local recording for this meeting']);
    exit;
}

$start = max(0.0, (float)($_GET['start'] ?? 0));
$len   = min(300.0, max(1.0, (float)($_GET['len'] ?? 60)));

$raw = shell_exec(sprintf(
    'ffmpeg -loglevel error -ss %F -t %F -i %s -ac 1 -ar %d -f s16le pipe:1',
    $start, $len, escapeshellarg($recording), PEAK_SAMPLE_RATE
));

$peaks = [];
foreach (str_split((string)$raw, 2 * (int)(PEAK_SAMPLE_RATE / PEAKS_PER_SECOND)) as $chunk) {
    if (strlen($chunk) < 2) {
        continue;
    }
    $samples = unpack('s*', substr($chunk, 0, strlen($chunk) & ~1));
    $peaks[] = round(max(max($samples), -min($samples)) / 32768, 3);
}

echo json_encode(['start' => $start, 'per_second' => PEAKS_PER_SECOND, 'peaks' => $peaks]);
