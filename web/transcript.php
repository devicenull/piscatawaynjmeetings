<?php
require(__DIR__.'/../init.php');
$meeting = new Meeting(['MEETINGID' => $_GET['MEETINGID']]);
$vars = [
	'meeting' => $meeting,
	'json_ld' => json_encode([
		'@context'            => 'https://schema.org',
		'@type'               => 'Event',
		'name'                => 'Piscataway, New Jersey '.ucfirst($meeting['type']).' Meeting',
		'startDate'           => $meeting['date'],
		'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
		'location'            => [
			'@type' => 'VirtualLocation',
			'url'   => ($meeting['zoom_id'] != '') ? 'zoommtg://zoom.us/join?confno='.$meeting['zoom_id'].'&pwd='.sprintf("%06s", $meeting['zoom_password']) : '',
		],
	]),
];
displayPage('transcript.html', $vars);
