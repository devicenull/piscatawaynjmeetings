<?php
/**
 * Transcribe meetings locally using WhisperX (replaces Rev.ai workflow).
 *
 * For each meeting that has a local recording but no transcript yet, runs:
 *   venv/bin/python scripts/transcribe_whisperx.py {board} {date}
 *
 * On success, sets transcript_available = 'yes' and generates a waveform if
 * one is not already present (same waveform block as transcribe_meetings.php).
 *
 * Usage: php scripts/transcribe_local.php
 */
require(__DIR__.'/../init.php');

$venv_python = __DIR__.'/../venv/bin/python3';
$transcribe  = __DIR__.'/transcribe_whisperx.py';

foreach (Meeting::getUntranscribed() as $meeting)
{
	$link = $meeting->getLink('recording');
	if ($link == '' || !file_exists(__DIR__.'/../web/'.$link))
	{
		continue;
	}

	$board = $meeting['type'];
	$date  = explode(' ', $meeting['date'])[0];

	echo "\nTranscribing $board $date...\n";
	passthru(
		escapeshellarg($venv_python)
		.' '.escapeshellarg($transcribe)
		.' '.escapeshellarg($board)
		.' '.escapeshellarg($date),
		$exit_code
	);

	if ($exit_code === 0)
	{
		$meeting->set(['transcript_available' => 'yes']);
		echo "  transcript_available set to yes\n";
	}
	else
	{
		echo "  WARNING: transcription failed (exit $exit_code) for $board $date\n";
	}
}

// Generate waveform peaks for any meeting with a local recording but no peaks yet
foreach (Meeting::getWaveformNeeded() as $meeting)
{
	$recording = __DIR__.'/../web/'.$meeting->getLink('recording');
	if (!file_exists($recording))
	{
		continue;
	}

	$peaks_path = __DIR__.'/../web'.$meeting->getWaveformPath();
	echo "Generating waveform: {$meeting['type']} {$meeting['date']}\n";

	$out = [];
	exec(
		'python3 '.escapeshellarg(__DIR__.'/generate_waveform.py')
		.' '.escapeshellarg($recording)
		.' '.escapeshellarg($peaks_path)
		.' 2>&1',
		$out,
		$ret
	);
	echo implode("\n", $out)."\n";

	if ($ret === 0)
	{
		$meeting->set(['waveform_available' => 'yes']);
	}
	else
	{
		$meeting->set(['waveform_available' => 'error']);
		echo "  ERROR: waveform generation failed for {$meeting['type']} {$meeting['date']}\n";
	}
}
