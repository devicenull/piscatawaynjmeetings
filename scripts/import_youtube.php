<?php
require(__DIR__.'/../init.php');


$ytid = $argv[1];
$yt = new YouTube(['video_id' => $ytid]);
if (!$yt->isInitialized())
{
	$params = [
		'video_id'  => $ytid,
		'title'     => $argv[2],
		'published' => strftime('%F %T', strtotime($argv[3])),
	];

	echo "Downloading ".$ytid."\n";
	$videodate = explode(' ', $params['published'])[0];

	$basepath = __DIR__.'/web/files/youtube/'.$videodate.'/';
	passthru(__DIR__.'/youtube-dl -o "'.$basepath.'%(id)s" '.escapeshellarg('https://www.youtube.com/watch?v='.$ytid));

	if (file_exists($basepath.$ytid.'.mp4'))
	{
		$params['filename'] = $ytid.'.mp4';
	}
	elseif (file_exists($basepath.$ytid.'.mkv'))
	{
		$params['filename'] = $ytid.'.mkv';
	}
	else
	{
		echo "Unable to determine filename for video!\n";
	}

	if (!$yt->add($params))
	{
		echo "Unable to add video: ".$yt->error()."\n";
	}
}
