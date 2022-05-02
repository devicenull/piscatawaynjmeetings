<?php
require(__DIR__.'/../init.php');

// WEBCHANGES_REPORT_CHANGED_JOBS ->
// $changes = [["url"=> "https://www.piscatawaynj.org/government/meeting_schedules/index.php", "name" => "piscataway jobs"]];

$changes = json_decode(getenv('WEBCHANGES_REPORT_CHANGED_JOBS'), true);

if (!empty($changes))
{
	foreach ($changes as $job)
	{
		$job_id = ArchiveOrg::archiveURL($job['url']);
		echo "archive.org job started, id ".$job_id."\n";


		/**
		*	I used to rely on archive.org to do this, but ran into too many issues where it wouldn't successfully parse a page.
		*	So, I use lynx to grab a list of links, and parse that
		*/
		$output = '';
		exec('/usr/bin/lynx -dump -listonly -hiddenlinks=listonly '.escapeshellarg($job['url']), $output);
		/**
		References

			1. https://www.piscatawaynj.org/government/meeting_schedules/index.php#main
			2. https://www.facebook.com/piscataway.township
			3. https://twitter.com/PWAYNJ
			4. https://www.piscatawaynj.org/government/meeting_schedules/index.php
			5. https://www.piscatawaynj.org/government/meeting_schedules/index.php
		*/
		foreach ($output as $line)
		{
			$data = explode('.', $line, 2);
			if (count($data) != 2) continue;
			$url = trim($data[1]);

			$urlinfo = parse_url($url);

			$destname = __DIR__.'/../downloaded/'.basename($urlinfo['path']);

			if (preg_match('/\.(doc|docx|pdf)$/i', $urlinfo['path']) && in_array($urlinfo['scheme'], ['http','https']) && !file_exists($destname))
			{
				echo "Downloading {$url}\n";
				$c = curl_init($url);
				curl_setopt_array($c, [
					CURLOPT_FILE           => fopen($destname, 'w'),
					CURLOPT_TIMEOUT        => 60,
					CURLOPT_FOLLOWLOCATION => true,
				]);
				curl_exec($c);
				if (curl_getinfo($c, CURLINFO_HTTP_CODE) != 200)
				{
					echo "Failed to download\n";
					var_dump(curl_getinfo($c));
				}
			}
		}
	}
}
echo "done\n";
