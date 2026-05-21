<?php
/**
 * Fetch Rev.ai JSON transcripts for all meetings that have a revai_jobid but
 * no local .revai.json file.  Safe to re-run; skips files that already exist.
 *
 * Usage: php fetch_revai_json.php [--force]
 *   --force  Re-download even if .revai.json already exists
 */
require(__DIR__.'/../init.php');

$force = in_array('--force', $argv ?? []);

$res = $db->Execute("select * from meeting where revai_jobid != '' order by date desc");

$fetched = 0;
$skipped = 0;
$missing = 0;
$failed  = 0;

$c = curl_init();
curl_setopt_array($c, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_HTTPHEADER     => [
		'Authorization: Bearer '.REVAI_TOKEN,
		'Accept: application/vnd.rev.transcript.v1.0+json',
	],
]);

foreach ($res as $cur)
{
	$meeting  = new Meeting(['record' => $cur]);
	$date     = explode(' ', $meeting['date']);
	$json_path = __DIR__.'/../web/files/'.$meeting['type'].'/'.$date[0].'.revai.json';

	if (!$force && file_exists($json_path))
	{
		$skipped++;
		continue;
	}

	$url = 'https://api.rev.ai/speechtotext/v1/jobs/'.$cur['revai_jobid'].'/transcript';
	curl_setopt($c, CURLOPT_URL, $url);
	$body = curl_exec($c);
	$http  = curl_getinfo($c, CURLINFO_HTTP_CODE);

	if ($http === 200)
	{
		file_put_contents($json_path, $body);
		echo "OK  {$meeting['type']} {$date[0]}\n";
		$fetched++;
	}
	else if ($http === 404)
	{
		echo "GONE {$meeting['type']} {$date[0]} (job {$cur['revai_jobid']} expired or not found)\n";
		$missing++;
	}
	else
	{
		$decoded = json_decode($body, true);
		$detail  = $decoded['title'] ?? $decoded['detail'] ?? $body;
		echo "ERR  {$meeting['type']} {$date[0]} HTTP {$http}: {$detail}\n";
		$failed++;
	}
}

curl_close($c);

echo "\nDone. fetched={$fetched} skipped={$skipped} expired={$missing} errors={$failed}\n";
