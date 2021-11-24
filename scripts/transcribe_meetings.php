<?php
require(__DIR__.'/../init.php');

foreach (Meeting::getUntranscribed() as $meeting)
{
	$c = curl_init('https://piscatawaynjmeetings.com'.$meeting->getLink('recording'));
	curl_setopt($c, CURLOPT_NOBODY, true);
	$data = curl_exec($c);

	/**
	*	It's very possible this meeting exists locally, but not on the production site yet.
	*	Don't attempt to parse meetings that haven't been uploaded
	*/
	if (curl_getinfo($c, CURLINFO_HTTP_CODE) != 200)
	{
		echo $meeting->getLink('recording')." has not been uploaded to prod yet\n";
		continue;
	}

	$params = [
		'media_url' => 'https://piscatawaynjmeetings.com/'.$meeting->getLink('recording'),
		'metadata'  => $meeting['type'].' '.$meeting['date'],
	];

	$c = curl_init('https://api.rev.ai/speechtotext/v1/jobs');
	curl_setopt_array($c, [
		CURLOPT_HTTPHEADER     => [
			'Authorization: Bearer '.REVAI_TOKEN,
			'Content-Type: application/json',
		],
		CURLOPT_POSTFIELDS     => json_encode($params, true),
		CURLOPT_RETURNTRANSFER => true,
	]);

	$data = curl_exec($c);
	$json = json_decode($data, true);
	// {"id":"yczs6qcbZBgz","created_on":"2021-11-24T01:43:57.567Z","name":"2021-06-24.mp3","metadata":"2021-06-24.mp3","media_url":"https://piscatawaynjmeetings.com/files/zoning/2021-06-24.mp3","status":"in_progress","type":"async","language":"en"}

	if (isset($json['id']))
	{
		$meeting->set(['revai_jobid' => $json['id']]);
	}
	die();
}
