<?php
// post on bluesky 24h before the meetings
require_once(__DIR__.'/../init.php');

ini_set('date.timezone', 'America/New_York');
ini_set('display_errors', 1);

$startdate = strftime('%F %T');
$enddate = strftime('%F %T', time() + (24 * 60 * 60));
$db->debug = true;
$res = $db->Execute('
	select *
	from meeting
	where date between ? and ?
', [
	$startdate,
	$enddate,
]);
$bs = new BlueSky();
foreach ($res as $meeting)
{
	$date = new DateTime($meeting['date']);
	$message = 'Piscataway, New Jersey '.ucfirst($meeting['type']).' meeting is scheduled for '.$date->format('F j g:i a').'. ';

	$facets = [];

	$joinmsg = $joinurl = '';
	if ($meeting['zoom_id'] != '' && $meeting['zoom_password'] != '')
	{
		$message .= 'Join via Zoom.  Meeting ID: '.number_format($meeting['zoom_id'], 0, '', ' ').' Password: '.$meeting['zoom_password'].'.  Dial in number is 1-646-876-9923, or join via computer. ';

	}
	else
	{
		$message .= 'Join via Zoom, information is on the ';

		$joinmsg = 'town website. ';
		$joinurl = 'https://www.piscatawaynj.org/government/meeting_schedules/index.php';
	}

	// meh, this sucks.. refactor into something more flexible later
	if ($joinurl != '')
	{
		$facets[] = [
			'index' => [
				'byteStart' => strlen($message),
				'byteEnd' => strlen($message) + strlen($joinmsg),
			],
			'features' => [[
				'$type' => 'app.bsky.richtext.facet#link',
				'uri' => $joinurl,
			]],
		];
		$message .= $joinmsg;
	}

	$message .= 'Agenda is available ';
	$agendamsg = 'here';
	$facets[] = [
		'index' => [
			'byteStart' => strlen($message),
			'byteEnd' => strlen($message) + strlen($agendamsg),
		],
		'features' => [[
			'$type' => 'app.bsky.richtext.facet#link',
			'uri' => 'https://www.piscatawaynj.org/government/meeting_schedules/index.php',
		]],
	];
	$message .= $agendamsg;

	$bs->post($message, $facets);
}
