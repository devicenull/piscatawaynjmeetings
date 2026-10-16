<?php
// Re-download any YouTube video that left .part files behind, then register it if it isn't in the DB yet
require(__DIR__.'/../init.php');

// share monitor_youtube.php's lock so the two never download the same file at once
$lock = fopen(__DIR__.'/monitor_youtube.php', 'r');
if (!flock($lock, LOCK_EX | LOCK_NB))
{
	echo "monitor_youtube.php is running, try again later\n";
	exit(1);
}

$todo = [];
foreach (glob(__DIR__.'/../web/files/youtube/*/*.part') as $part)
{
	// video ids are 11 chars of [A-Za-z0-9_-], so the first '.' ends it
	$ytid = explode('.', basename($part))[0];
	$todo[$ytid] = dirname($part).'/';
}

foreach ($todo as $ytid => $basepath)
{
	echo "Downloading ".$ytid."\n";
	$out = [];
	exec('OPENSSL_CONF=/etc/ssl/ '.__DIR__.'/yt-dlp --no-simulate --print after_move:filepath --print "after_move:%(timestamp)s" --print after_move:title -o "'.$basepath.'%(id)s" '.escapeshellarg('https://www.youtube.com/watch?v='.$ytid), $out, $ret);

	if ($ret != 0 || count($out) < 3)
	{
		echo "Download of ".$ytid." failed (exit ".$ret."), leaving .part files for next time\n";
		continue;
	}

	// yt-dlp has finished the file; anything still named .part is stale leftovers from older attempts
	foreach (glob($basepath.$ytid.'*.part') as $stale)
	{
		unlink($stale);
	}

	$yt = new YouTube(['video_id' => $ytid]);
	if ($yt->isInitialized())
	{
		continue;
	}

	// getFileLocation() derives the directory from the published date, so keep it matching the dir we downloaded into
	$videodate = basename($basepath);
	$published = date('Y-m-d H:i:s', (int)$out[1]);
	if (explode(' ', $published)[0] != $videodate)
	{
		$published = $videodate.' 00:00:00';
	}

	if (!$yt->add([
		'video_id'  => $ytid,
		'title'     => $out[2],
		'published' => $published,
		'filename'  => basename($out[0]),
	]))
	{
		echo "Unable to add video: ".$yt->error()."\n";
	}
}
