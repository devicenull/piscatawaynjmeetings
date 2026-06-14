<?php
// post on bluesky 24h before the meetings
// Usage: php post_to_bluesky.php [--force-meeting-id=<id>]
require_once(__DIR__.'/../init.php');

ini_set('date.timezone', 'America/New_York');
ini_set('display_errors', 1);

$opts = getopt('', ['force-meeting-id:']);
$force_meeting_id = isset($opts['force-meeting-id']) ? (int)$opts['force-meeting-id'] : null;

if ($force_meeting_id !== null)
{
	$res = $db->Execute('select * from meeting where MEETINGID = ?', [$force_meeting_id]);
}
else
{
	$startdate = strftime('%F %T');
	$enddate = strftime('%F %T', time() + ONE_DAY);
	$res = $db->Execute('
		select *
		from meeting
		where date between ? and ?
	', [
		$startdate,
		$enddate,
	]);
}

$db->debug = true;
$bs = new BlueSky();
foreach ($res as $row)
{
	$meeting = new Meeting(['record' => $row]);
	$seconds_until_meeting = strtotime($meeting['date']) - time();

	if ($force_meeting_id !== null)
	{
		$next_bluesky_posts = $meeting['bluesky_posts'] + 1;
	}
	// first post is 24h before
	else if ($meeting['bluesky_posts'] == 0)
	{
		$next_bluesky_posts = 1;
	}
	// second post is 1-2h before
	else if ($meeting['bluesky_posts'] == 1 && $seconds_until_meeting < 2 * ONE_HOUR)
	{
		$next_bluesky_posts = 2;
	}
	// otherwise don't post
	else
	{
		continue;
	}

	$date = new DateTime($meeting['date']);
	$message = 'Piscataway, New Jersey '.ucfirst($meeting['type']).' meeting is scheduled for '.$date->format('F j g:i a').".\n";

	$facets = [];

	$joinmsg = $joinurl = '';
	if ($meeting['zoom_id'] != '' && $meeting['zoom_password'] != '')
	{
		$message .= "Join via Zoom:\nMeeting ID: ".number_format($meeting['zoom_id'], 0, '', ' ')."\nPassword: ".$meeting['zoom_password']."\nDial in number is 1-646-876-9923, or join via computer.\n";

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

	if ($bs->post($message, $facets))
	{
		$meeting->set(['bluesky_posts' => $next_bluesky_posts]);
	}
}
