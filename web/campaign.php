<?php
/**
*	Graph of who contributed to which Piscataway campaigns in a year, from the ELEC data loaded by
*	scripts/fetch_campaign_reports.php + scripts/import_files.php
*/
require_once(__DIR__.'/../init.php');

$year = (int)($_GET['year'] ?? $db->GetOne('select max(f.year) from campaign_contributions c join campaign_files f using (CAMPAIGNFILEID)'));
$rows = $db->GetAll('
	select c.*
	from campaign_contributions c
	join campaign_files f using (CAMPAIGNFILEID)
	where f.year=?
', [$year]);
$known_town_contractors = [
	'chadwick',
	'remington',
	'cme associates',
	'najarian',
	'menlo',
	'venezia',
	'rainone',
	'naik',
	'netta',
	'harding',
	'delaware-raritan',
	'clarkin',
	'grotto',
	'alaimo',
	'Kawczynski',
	'LOMBARDI',
	'KINNEALLY',
	'callahan & blair', // employes kinneally
	'Womack',
	'lanza',
	't & m associates', // sterling village rennovations
	'mott mcdonald', // sewer study
	// James F. Clarkin, III, argued the cause for respondent in A-3648-0583 and for appellant in A-4094-05T3 ( Clarkin Vignuolo, P.C., and Waters, McPherson, McNeill, P.C., attorneys; Mr. Clarkin and Peter Vignuolo, of counsel and on the brief).
	'mcperson', // Township of Piscataway v. South Washington Avenue, LLC
	'WISNIEWSKI',
	'ACRISURE',
	'foley inc',
];

$known_town_developers = [
	'kelly', // 611/616 william street via Michael Murray
	'murray',
	// next one is via http://njparcels.com/property/notice/1222_286_1 , not 100% sure but two
	// real estate firms at the same address is sus
	'tyler', // aka 	TYLER PROPERTIES LLC aka HARRIS REALTY COMPANY
];

$contributions = $employers = $campaigns = $people = $campaigns_by_employer = $contributions_by_employer_campaign = $campaign_totals = [];
foreach ($rows as $row)
{
	$cur = [
		'name'     => $row['NonIndName'] ?: implode(' ', array_filter([$row['FirstName'], $row['MI'], $row['LastName'], $row['Suffix']])),
		'amount'   => $row['ContributionAmount'],
		'employer' => str_replace('  ', ' ', trim($row['EmpName'])),
		'campaign' => $row['EntityName'],
	];
	$contributions[] = $cur;

	$people[$cur['name']] = 1;
	$campaigns[$cur['campaign']] = 1;
	$campaign_totals[$cur['campaign']] = ($campaign_totals[$cur['campaign']] ?? 0) + $cur['amount'];
	if (!empty($cur['employer']))
	{
		$employers[$cur['employer']] = 1;
		$campaigns_by_employer[$cur['employer']][] = $cur['campaign'];
		$key = $cur['employer'].$cur['campaign'];
		$contributions_by_employer_campaign[$key] = ($contributions_by_employer_campaign[$key] ?? 0) + $cur['amount'];
	}
}

$graphviz = "graph G {
graph [ranksep=1,rankdir=\"RL\"
label = \"Piscataway ".$year." Campaign Contributions\"
labelloc = t
fontsize = 30
];
";
foreach (array_keys($employers) as $name)
{
	$color = '';
	foreach ($known_town_contractors as $substring)
	{
		if (stripos($name, $substring) !== false)
		{
			$color = 'color=red,style=filled,';
		}
	}
	foreach ($known_town_developers as $substring)
	{
		if (stripos($name, $substring) !== false)
		{
			$color = 'color=yellow,style=filled,';
		}
	}
	$graphviz .= "E".crc32($name)." [shape=box,".$color."label=\"".$name."\"];\n";
}
foreach (array_keys($campaigns) as $name)
{
	$graphviz .= "C".crc32($name)." [shape=box,color=green,label=\"".$name."\n$".$campaign_totals[$name]."\"];\n";
}
foreach (array_keys($people) as $name)
{
	$color = '';
	foreach ($known_town_contractors as $substring)
	{
		if (stripos($name, $substring) !== false)
		{
			$color = 'color=red,style=filled,';
		}
	}
	$graphviz .= "P".crc32($name)." [shape=ellipse,".$color."label=\"".$name."\"];\n";
}
foreach ($campaigns_by_employer as $employer => $campaign)
{
	foreach (array_unique($campaign) as $cname)
	{
		$graphviz .= "E".crc32($employer)." -- C".crc32($cname)." [label=\"$".$contributions_by_employer_campaign[$employer.$cname]."\"];\n";
	}
}
$already_seen = [];
foreach ($contributions as $cur)
{
	if (isset($already_seen[$cur['name']])) continue;
	$already_seen[$cur['name']] = 1;
	if (!empty($cur['employer']))
	{
		$graphviz .= "P".crc32($cur['name'])." -- E".crc32($cur['employer'])." [label=\"$".$cur['amount']."\"];\n";
	}
	else
	{
		$graphviz .= "P".crc32($cur['name'])." -- C".crc32($cur['campaign'])." [label=\"$".$cur['amount']."\"];\n";
	}
}
$graphviz .= "}\n";

$graphviz .= "graph legend {\ngraph [ranksep=1,rankdir=\"RL\"];
		red [shape=box,color=red,style=filled,label=\"RED are companies/people that have been awarded contracts by the town\"];\n
		yellow [shape=box,color=yellow,style=filled,label=\"YELLOW are developers seeking or granted zoning variances\"];\n
		label = \"Generated ".date('Y-m-d')."\"
}\n";

//Header('Content-Type: text/plain'); echo $graphviz; die();

//Header('Content-Type: image/svg+xml');
$proc = proc_open('/usr/bin/dot -Tsvg -Kdot', [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	],
	$pipes
);
fwrite($pipes[0], $graphviz);
fclose($pipes[0]);

while (!feof($pipes[1]))
{
	echo fgets($pipes[1]);
}
while (!feof($pipes[2]))
{
	echo fgets($pipes[2]);
}
