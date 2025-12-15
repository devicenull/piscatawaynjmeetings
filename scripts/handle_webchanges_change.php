<?php
require(__DIR__.'/../init.php');
error_reporting(E_ALL);
echo file_get_contents('php://stdin');

$url = getenv('URLWATCH_JOB_LOCATION');

$jobid = ArchiveOrg::archiveURL($url);

function l($message)
{
	$f = fopen('/home/piscataway/urlwatch.log', 'a');
	fwrite($f, strftime('%F %T')."\t".$message."\n");
	fclose($f);
}
l($url."\tarchive.org jobid: ".$jobid);

$user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Safari/537.36';

//$job_id = ArchiveOrg::archiveURL($url);
/**
*	I used to rely on archive.org to do this, but ran into too many issues where it wouldn't successfully parse a page.
*	So, I use nech-dump to grab a list of links, and parse that
*	lynx had an issue where it wasn't outputting relative links properly.
*/
$output = '';
exec('/usr/bin/mech-dump --links --agent='.escapeshellarg($user_agent).' '.escapeshellarg($url), $output);
/**
References

	1. https://www.piscatawaynj.org/government/meeting_schedules/index.php#main
	2. https://www.facebook.com/piscataway.township
	3. https://twitter.com/PWAYNJ
	4. https://www.piscatawaynj.org/government/meeting_schedules/index.php
	5. https://www.piscatawaynj.org/government/meeting_schedules/index.php
*/
foreach ($output as $url)
{
	$url = trim($url);
	if (empty($url)) continue;
	//l($url);

	$urlinfo = parse_url($url);
	$destname = __DIR__.'/../downloaded/'.basename(urldecode($urlinfo['path']));
	if ($urlinfo['path'] === null)
	{
		error_log('invalid urlinfo-path for '.$url);
	}
	else if (preg_match('/\.(doc|docx|pdf)$/i', $urlinfo['path']) && !file_exists($destname) && !str_contains($url, 'http://') && !str_contains($url, 'https://'))
	{
		// urls are a mess here - they're in the HTML as relative URLS (ex 'Document_Center/Government/Zoning Board/Zoning Minutes/Zoning Board minutes 10.10.24.pdf')
		// but they're actually relative to piscatawaynj.org/, not the directory they're in
		// gotta keep playing games with urlencode - rawurlencode was working until their 2025 update, now it's back to regular urlencode
		$dlurl = 'https://piscatawaynj.org/'.str_replace(' ', '%20', $urlinfo['path']);
		//l("Downloading {$dlurl}");
		$c = curl_init($dlurl);
		curl_setopt_array($c, [
			CURLOPT_FILE           => fopen($destname, 'w'),
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_USERAGENT      => $user_agent,
		]);
		curl_exec($c);
		if (curl_getinfo($c, CURLINFO_HTTP_CODE) != 200)
		{
			l("Failed to download: {$url}");
			l(print_r(curl_getinfo($c), true));
			unlink($destname);
			//echo "Failed to download\n";
			//var_dump(curl_getinfo($c));
		}
		sleep(1);
	}
}
