<?php
require(__DIR__.'/../init.php');
if (isset($_GET['MEETINGID'])) {
	$meeting = new Meeting(['MEETINGID' => $_GET['MEETINGID']]);
} elseif (isset($_GET['type']) && isset($_GET['date'])) {
	$meeting = new Meeting(['type' => $_GET['type'], 'date' => $_GET['date']]);
} else {
	http_response_code(404);
	exit;
}
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

$known_speakers = [];
$has_revai_json = false;
if (hasEditAuth()) {
	$profiles_path = __DIR__.'/../shared/speakers/profiles.json';
	if (file_exists($profiles_path)) {
		$profiles = json_decode(file_get_contents($profiles_path), true) ?? [];
		foreach ($profiles['speakers'] ?? [] as $id => $info) {
			$known_speakers[] = ['id' => $id, 'name' => $info['name']];
		}
		usort($known_speakers, fn($a, $b) => strcmp($a['name'], $b['name']));
	}
	$date = explode(' ', $meeting['date'])[0];
	$base = __DIR__.'/../web/files/'.$meeting['type'].'/'.$date;
	$has_revai_json = file_exists($base.'.whisperx.json') || file_exists($base.'.revai.json');
}

$vars = [
	'meeting'        => $meeting,
	'known_speakers' => $known_speakers,
	'has_revai_json' => $has_revai_json,
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
