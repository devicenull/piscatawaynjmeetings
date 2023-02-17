<?php
/**
*	Get this data from https://www.elec.state.nj.us/ELECReport/searchcontributorsadvanced.aspx
*	Click "Contribution" -> "SEARCH FOR CONTRIBUTION BY CONTRIBUTORS"
*	Enter year/location, click search
*	Click "Download data"
*/
$year = 2020;
$f = fopen(__DIR__.'/files/campaign/'.$year.'.csv', 'r');
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

$header = fgets($f);
//CONTRIBUTOR	ADDRESS	STREET1	CITY	STATE	ZIP	CONT AMT	CONT DATE	OCC EMP	OCC EMP ADDRESS	EMP NAME	OCCUPATION NAME	EMP STREET1	EMP CITY	EMP STATE	EMP ZIP	RECIPIENT NAME	RECIPIENT ELECTION TYPE	RECIPIENT ELECTION YEAR	RECIPIENT OFFICE	RECIPIENT LOCATION	RECIPIENT PARTY	CONTRIBUTOR TYPE	CONTRIBUTION SHORT TYPE

$contributions = $employers = $campaigns = $people = $people_by_employer = $contributions_by_employer_campaign = $campaign_totals = [];
while (!feof($f))
{
	$data = fgetcsv($f);
	if (empty($data)) continue;
	$cur = [
		'name' => $data[0],
		'amount' => $data[6],
		'date' => strftime($data[7]),
		'employer' => str_replace('  ', ' ', trim($data[10])),
		'campaign' => $data[16],
	];
	$contributions[] = $cur;

	$people[$cur['name']] = 1;
	$campaigns[$cur['campaign']] = 1;
	$campaign_totals[$cur['campaign']] += $cur['amount'];
	if (!empty($cur['employer']))
	{
		$employers[$cur['employer']] = 1;
		$campaigns_by_employer[$cur['employer']][] = $cur['campaign'];
		$contributions_by_employer_campaign[$cur['employer'].$cur['campaign']] += $cur['amount'];
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
		label = \"Generated ".strftime('%F')."\"
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
