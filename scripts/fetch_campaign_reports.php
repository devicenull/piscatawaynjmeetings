<?php
/**
*	Download Piscataway campaign finance data from the NJ ELEC eFile search into web/files/campaign/YEAR/
*	(YEAR is the election year ELEC assigns the candidate/committee)
*	 - Candidates/joint committees: https://www.njelecefilesearch.com/searchcandidatereports
*	 - Party organizations/PACs:    https://www.njelecefilesearch.com/SearchPACReports
*
*	For every entity this saves the filed report PDFs (ELEC only has these for 2021+) and the
*	"Summary Data" contribution/expenditure CSVs (which go back much further).
*	PDFs that already exist are skipped, CSVs are re-downloaded since they grow as reports are filed.
*	Run scripts/import_files.php afterwards to register everything.
*/
const ELEC_BASE = 'https://www.njelecefilesearch.com';
const PISCATAWAY_LOCATION = 1217;

// party reports keep the "PDO Report 2021-04-14 R-3.pdf" naming used by the files mirrored from the old ELEC site
const PARTY_PREFIX = [
	'MUNICIPAL DEM PARTY' => 'PDO',
	'MUNICIPAL REP PARTY' => 'PTRO',
];

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
	// ELEC throws occasional transient 500s
	for ($attempt = 1; $attempt <= 5; $attempt++)
	{
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($body !== false && $code == 200)
		{
			break;
		}
		sleep(5 * $attempt);
	}
	curl_close($ch);
	if ($body === false || $code != 200)
	{
		throw new RuntimeException("ELEC request failed ($code): $url");
	}
	return $body;
}

function getEntities(string $only_flag): array
{
	// the search endpoint is a DataTables backend and rejects requests without column definitions
	$columns = [];
	foreach (['ENTITYNAME', 'LOCATION', 'OFFICE', 'PARTY', 'ELECTIONTYPE', 'ELECTIONYEAR'] as $col)
	{
		$columns[] = ['data' => $col, 'name' => $col, 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']];
	}
	return json_decode(elec('/api/VWEntity/Entities20', [
		'draw'          => 1,
		'start'         => 0,
		'length'        => 10000,
		'columns'       => $columns,
		'order'         => [['column' => 0, 'dir' => 'asc']],
		'search'        => ['value' => '', 'regex' => 'false'],
		$only_flag      => 'true',
		'LocationCodes' => PISCATAWAY_LOCATION,
	], true), true)['data'];
}

function save(string $path, string $data)
{
	if (!is_dir(dirname($path)) && !mkdir(dirname($path)))
	{
		throw new RuntimeException('Unable to create '.dirname($path));
	}
	if (file_put_contents($path, $data) === false)
	{
		throw new RuntimeException("Unable to write $path");
	}
}

function cleanName(string $name): string
{
	return preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9 &.()-]/i', '', str_replace([',', '/'], ['', '-'], $name)));
}

// establishes the session cookie the API expects
elec('/searchcandidatereports', []);

$entities = array_merge(getEntities('NONPACOnly'), getEntities('PACOnly'));
foreach ($entities as $entity)
{
	$dir = __DIR__.'/../web/files/campaign/'.$entity['ELECTIONYEAR'].'/';
	$entity_name = cleanName($entity['ENTITYNAME'].' '.$entity['ELECTIONTYPE']);
	$prefix = PARTY_PREFIX[$entity['OFFICE']] ?? null;

	$filings = json_decode(elec('/api/VWEntity/GetEntityFilingData', ['ENTITY_S' => $entity['ENTITY_S'], 'EntityOnly' => 'true']), true);
	foreach ($filings as $filing)
	{
		// no DOCID = pre-2021 report that ELEC doesn't publish
		if (empty($filing['DOCID']) || empty($filing['PUBLIC_ACCESS']))
		{
			continue;
		}

		// ex: "WAHLER CAHILL SHAH & ROUSE PRIMARY 2024-06-25 R-1 Amend 1 (3821670).pdf"
		// DOCID keeps same-day filings of the same form distinct (ex: 72-24 HR notices, catch-up filings for several periods)
		$report = ($prefix ? "$prefix Report" : $entity_name)
			.' '.substr($filing['DATE_RECEIVED'], 0, 10)
			.' '.$filing['FormName']
			.($filing['AMEND_NO'] ? ' Amend '.$filing['AMEND_NO'] : '');
		$name = $report.' ('.$filing['DOCID'].')';
		$path = $dir.cleanName($name).'.pdf';
		// party reports mirrored from the old ELEC site have no DOCID in the name
		if (file_exists($path) || file_exists($dir.cleanName($report).'.pdf'))
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
		save($path, $pdf);
		echo $entity['ELECTIONYEAR'].': '.basename($path)."\n";
	}

	foreach (['contributions' => 'VWContributionDetail', 'expenditures' => 'VWExpenseDetail'] as $kind => $api)
	{
		$csv = file_get_contents(json_decode(elec("/api/$api/DownlodDataCSV", ['ENTITY_S' => $entity['ENTITY_S']], true), true));
		if ($csv === false)
		{
			echo "FAILED: $entity_name $kind\n";
			continue;
		}
		// header only = nothing reported
		if (substr_count(trim($csv), "\n") == 0)
		{
			continue;
		}
		$path = $dir.($prefix ? $prefix.' '.$entity['ELECTIONYEAR'] : $entity_name).' '.$kind.' ('.$entity['ENTITY_S'].').csv';
		save($path, $csv);
		echo $entity['ELECTIONYEAR'].': '.basename($path)."\n";
	}
}

unlink($cookies);
