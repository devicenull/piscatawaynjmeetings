<?php
require(__DIR__.'/../init.php');
$meeting = new Meeting(['MEETINGID' => $_GET['MEETINGID']]);
$physical_location = [
	'@type'   => 'Place',
	'name'    => 'Township of Piscataway Municipal Building',
	'address' => [
		'@type'           => 'PostalAddress',
		'streetAddress'   => '455 Hoes Lane',
		'addressLocality' => 'Piscataway',
		'addressRegion'   => 'NJ',
		'postalCode'      => '08854',
		'addressCountry'  => 'US',
	],
];

$vars = [
	'meeting' => $meeting,
	'json_ld' => json_encode([
		'@context'            => 'https://schema.org',
		'@type'               => 'Event',
		'name'                => 'Piscataway, New Jersey '.ucfirst($meeting['type']).' Meeting',
		'startDate'           => $meeting['date'],
		'eventStatus'         => 'https://schema.org/EventScheduled',
		'eventAttendanceMode' => ($meeting['zoom_id'] != '')
			? 'https://schema.org/OnlineEventAttendanceMode'
			: 'https://schema.org/OfflineEventAttendanceMode',
		'location'            => ($meeting['zoom_id'] != '')
			? ['@type' => 'VirtualLocation', 'url' => 'zoommtg://zoom.us/join?confno='.$meeting['zoom_id'].'&pwd='.sprintf("%06s", $meeting['zoom_password'])]
			: $physical_location,
		'organizer'           => [
			'@type' => 'GovernmentOrganization',
			'name'  => 'Township of Piscataway',
			'url'   => 'https://www.piscatawaynj.gov',
		],
	]),
];
displayPage('transcript.html', $vars);
