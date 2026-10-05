<?php
/**
*	Download Piscataway candidate/committee finance reports from the NJ ELEC eFile search
*	(https://www.njelecefilesearch.com/searchcandidatereports) into web/files/campaign/YEAR/
*	Run scripts/import_files.php afterwards to register them.
*
*	Usage: php fetch_campaign_reports.php [first_year]   (default 2023, through the current year)
*/
const ELEC_BASE = 'https://www.njelecefilesearch.com';
const PISCATAWAY_LOCATION = 1217;

$first_year = (int)($argv[1] ?? 2023);
$cookies = tempnam(sys_get_temp_dir(), 'elec');

function elec(string $path, array $params, bool $post = false)
{
	global $cookies;
	$url = ELEC_BASE.$path;
	$ch = curl_init();
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_COOKIEJAR      => $cookies,
		CURLOPT_COOKIEFILE     => $cookies,
		CURLOPT_USERAGENT      => 'Mozilla/5.0 (piscatawaynjmeetings.com)',
		CURLOPT_TIMEOUT        => 120,
	]);
	if ($post)
	{
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
	}
	elseif ($params)
	{
		$url .= '?'.http_build_query($params);
	}
	curl_setopt($ch, CURLOPT_URL, $url);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($body === false || $code != 200)
	{
		throw new RuntimeException("ELEC request failed ($code): $url");
	}
	return $body;
}

// establishes the session cookie the API expects
elec('/searchcandidatereports', []);

// the search endpoint is a DataTables backend and rejects requests without column definitions
$columns = [];
foreach (['ENTITYNAME', 'LOCATION', 'OFFICE', 'PARTY', 'ELECTIONTYPE', 'ELECTIONYEAR'] as $col)
{
	$columns[] = ['data' => $col, 'name' => $col, 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']];
}

for ($year = $first_year; $year <= (int)date('Y'); $year++)
{
	$entities = json_decode(elec('/api/VWEntity/Entities20', [
		'draw'          => 1,
		'start'         => 0,
		'length'        => 1000,
		'columns'       => $columns,
		'order'         => [['column' => 0, 'dir' => 'asc']],
		'search'        => ['value' => '', 'regex' => 'false'],
		'NONPACOnly'    => 'true',
		'LocationCodes' => PISCATAWAY_LOCATION,
		'ElectionYears' => $year,
	], true), true)['data'];

	$dir = __DIR__.'/../web/files/campaign/'.$year;
	if (!is_dir($dir) && !mkdir($dir))
	{
		throw new RuntimeException("Unable to create $dir");
	}

	foreach ($entities as $entity)
	{
		$filings = json_decode(elec('/api/VWEntity/GetEntityFilingData', ['ENTITY_S' => $entity['ENTITY_S'], 'EntityOnly' => 'true']), true);
		foreach ($filings as $filing)
		{
			if (empty($filing['DOCID']) || empty($filing['PUBLIC_ACCESS']))
			{
				continue;
			}

			// ex: "WAHLER CAHILL SHAH & ROUSE PRIMARY 2024-06-25 R-1 Amend 1 (3821670).pdf"
			// DOCID keeps same-day filings of the same form (ex: multiple 72-24 HR notices) distinct
			$name = preg_replace('/[^A-Z0-9 &.-]/i', '', str_replace(',', '', $entity['ENTITYNAME']))
				.' '.$entity['ELECTIONTYPE']
				.' '.substr($filing['DATE_RECEIVED'], 0, 10)
				.' '.$filing['FormName']
				.($filing['AMEND_NO'] ? ' Amend '.$filing['AMEND_NO'] : '')
				.' ('.$filing['DOCID'].')';
			$path = $dir.'/'.preg_replace('/\s+/', ' ', str_replace('/', '-', $name)).'.pdf';
			if (file_exists($path))
			{
				continue;
			}

			$signed = json_decode(elec('/SearchCandidateReports/', ['handler' => 'DownloadReport', 'DocId' => $filing['DOCID']]), true)['FileNameWithSAS'];
			$pdf = file_get_contents($signed);
			if ($pdf === false || substr($pdf, 0, 4) !== '%PDF')
			{
				echo "FAILED: $name (doc ".$filing['DOCID'].")\n";
				continue;
			}
			if (file_put_contents($path, $pdf) === false)
			{
				throw new RuntimeException("Unable to write $path");
			}
			echo "$year: ".basename($path)."\n";
		}
	}
}

unlink($cookies);
