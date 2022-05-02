<?php
ini_set('display_errors', true);
require(__DIR__.'/../init.php');

$c = null;
$baseheaders = [
	'Authorization: Bearer '.REVAI_TOKEN,
];

$res = $db->Execute('select * from meeting where revai_jobid != "" and transcript_available="no"');
foreach ($res as $cur)
{
	$meeting = new Meeting(['record' => $cur]);

	$status_url = 'https://api.rev.ai/speechtotext/v1/jobs/'.$cur['revai_jobid'];
	if ($c === null)
	{
		$c = curl_init($status_url);
		curl_setopt_array($c, [
			CURLOPT_HTTPHEADER     => $baseheaders,
			CURLOPT_RETURNTRANSFER => true,
		]);
	}
	else
	{
		curl_setopt($c, CURLOPT_URL, $status_url);
	}

	$data = curl_exec($c);
	// {"id":"yczs6qcbZBgz","created_on":"2021-11-24T01:43:57.567Z","completed_on":"2021-11-24T01:45:35.879Z","name":"2021-06-24.mp3","metadata":"2021-06-24.mp3","media_url":"https://piscatawaynjmeetings.com/files/zoning/2021-06-24.mp3","status":"transcribed","duration_seconds":4048.45,"type":"async","language":"en"}
	$json = json_decode($data, true);
	if ($json['status'] == 'transcribed')
	{
		curl_setopt($c, CURLOPT_URL, 'https://api.rev.ai/speechtotext/v1/jobs/'.$cur['revai_jobid'].'/transcript');
		curl_setopt($c, CURLOPT_HTTPHEADER, array_merge($baseheaders, ['Accept: text/plain']));
		$transcript = curl_exec($c);
		if (curl_getinfo($c, CURLINFO_HTTP_CODE) == 200)
		{
			file_put_contents(__DIR__.'/../web/'.$meeting->getLink('transcript', true), $transcript);
			$meeting->set(['transcript_available' => 'yes']);
		}
	}
	else if ($json['status'] == 'failed')
	{
		echo "ERROR: Job {$cur['revai_jobid']} failed: {$json['failure_detail']}\n";
		$meeting->set(['revai_jobid' => '']);
	}
}

echo "Done\n";
