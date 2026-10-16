<?php
require(__DIR__.'/../init.php');

// a long download can outlast the cron interval; don't let a second run fight over the same .part file
$lock = fopen(__FILE__, 'r');
if (!flock($lock, LOCK_EX | LOCK_NB))
{
	exit(0);
}

$c = curl_init('https://www.youtube.com/feeds/videos.xml?channel_id=UClvOfAfDVKKd8T-becTCVow');
curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
$data = curl_exec($c);

if (curl_getinfo($c, CURLINFO_HTTP_CODE) != 200)
{
	echo "Unable to download feed\n";
	echo $data;
	exit(-1);
}

$xml = new SimpleXMLElement($data);
foreach ($xml->entry as $item)
{
	$ytid = explode(':', ((string)$item->id[0]))[2];
	$yt = new YouTube(['video_id' => $ytid]);
	if (!$yt->isInitialized())
	{
		$params = [
			'video_id'  => $ytid,
			'title'     => (string)$item->title[0],
			'published' => strftime('%F %T', strtotime($item->published)),
		];

		echo "Downloading ".$ytid."\n";
		$videodate = explode(' ', $params['published'])[0];

		$basepath = __DIR__.'/../web/files/youtube/'.$videodate.'/';
		passthru('OPENSSL_CONF=/etc/ssl/ '.__DIR__.'/yt-dlp -o "'.$basepath.'%(id)s" '.escapeshellarg('https://www.youtube.com/watch?v='.$ytid), $ret);

		// Leave failed/partial downloads out of the DB so the next run retries them (yt-dlp resumes the .part)
		if ($ret != 0 || glob($basepath.$ytid.'*.part'))
		{
			echo "Download of ".$ytid." incomplete (exit ".$ret."), will retry next run\n";
			continue;
		}

		if (file_exists($basepath.$ytid.'.mp4'))
		{
			$params['filename'] = $ytid.'.mp4';
		}
		elseif (file_exists($basepath.$ytid.'.mkv'))
		{
			$params['filename'] = $ytid.'.mkv';
		}
		elseif (file_exists($basepath.$ytid.'.webm'))
		{
			$params['filename'] = $ytid.'.webm';
		}
		else
		{
			echo "Unable to determine filename for video!\n";
			continue;
		}

		if (!$yt->add($params))
		{
			echo "Unable to add video: ".$yt->error()."\n";
		}
	}
}
