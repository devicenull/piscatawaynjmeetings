<?php
require(__DIR__.'/../init.php');

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
			continue;
		}

		if (!$yt->add($params))
		{
			echo "Unable to add video: ".$yt->error()."\n";
		}
	}
}
