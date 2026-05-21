<?php
/**
 * Backfill waveform peaks for all meetings that have recordings.
 *
 * If the recording exists locally it is used directly. Otherwise the script
 * downloads it from Cloudflare R2 into a temp file, generates the peaks,
 * then deletes the temp file.
 *
 * Usage:
 *   php scripts/generate_waveforms.php            # only missing waveforms
 *   php scripts/generate_waveforms.php --force    # regenerate all
 */
require(__DIR__.'/../init.php');

$force = in_array('--force', $argv);

$meetings = $force ? Meeting::getAllWithRecordings() : Meeting::getWaveformNeeded();

echo 'Processing '.count($meetings).' meeting(s)'.($force ? ' (forced)' : '')."\n";

foreach ($meetings as $meeting)
{
	$recording_link = $meeting->getLink('recording');
	if ($recording_link == '')
	{
		echo "SKIP {$meeting['type']} {$meeting['date']}: no recording link\n";
		continue;
	}

	$local_path   = __DIR__.'/../web/'.$recording_link;
	$peaks_path   = __DIR__.'/../web'.$meeting->getWaveformPath();
	$tmp_download = null;

	if (file_exists($local_path))
	{
		$audio_path = $local_path;
	}
	else
	{
		// Recording is on R2 but not local — download to a temp file
		$public_url = $meeting->getPublicLink('recording');
		echo "Downloading {$public_url}\n";

		$tmp_download = tempnam(sys_get_temp_dir(), 'pnj_waveform_');
		$ext          = pathinfo($recording_link, PATHINFO_EXTENSION);
		rename($tmp_download, $tmp_download .= '.'.$ext);

		$fh  = fopen($tmp_download, 'wb');
		$c   = curl_init($public_url);
		curl_setopt_array($c, [
			CURLOPT_FILE           => $fh,
			CURLOPT_FOLLOWLOCATION => true,
		]);
		curl_exec($c);
		$http_code = curl_getinfo($c, CURLINFO_HTTP_CODE);
		curl_close($c);
		fclose($fh);

		if ($http_code !== 200)
		{
			echo "  ERROR: HTTP {$http_code} for {$public_url}\n";
			unlink($tmp_download);
			continue;
		}

		$audio_path = $tmp_download;
	}

	echo "Generating: {$meeting['type']} {$meeting['date']}\n";
	$out = null;
	exec(
		'python3 '.escapeshellarg(__DIR__.'/generate_waveform.py')
		.' '.escapeshellarg($audio_path)
		.' '.escapeshellarg($peaks_path)
		.' 2>&1',
		$out,
		$ret
	);
	echo implode("\n", $out)."\n";

	if ($tmp_download !== null)
	{
		unlink($tmp_download);
	}

	if ($ret === 0)
	{
		$meeting->set(['waveform_available' => 'yes']);
	}
	else
	{
		echo "  ERROR: generation failed\n";
	}
}

echo "Done\n";
