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

define('BASEURL', 'https://piscatawaynjmeetings.com');
$meeting_date_only = explode(' ', $meeting['date'])[0];
$canonical_url      = BASEURL.'/'.$meeting['type'].'/meeting/'.$meeting_date_only;
$date_modified_iso  = (new DateTime($meeting['last_updated']))->format(DateTime::ATOM);

$event_schema = [
	'@type'               => 'Event',
	'name'                => 'Piscataway, New Jersey '.ucfirst($meeting['type']).' Meeting',
	'startDate'           => $meeting['date'],
	'dateModified'        => $date_modified_iso,
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
];

if ($meeting['recording_available'] == 'yes') {
	$audio_object = [
		'@type'          => 'AudioObject',
		'contentUrl'     => $meeting->getPublicLink('recording'),
		'encodingFormat' => 'audio/mpeg',
		'name'           => 'Piscataway, New Jersey '.ucfirst($meeting['type']).' Meeting — '.$meeting_date_only,
	];
	if ($meeting['transcript_available'] == 'yes') {
		$transcript_text = $meeting->getPlainTranscriptText();
		if ($transcript_text !== '') {
			$audio_object['transcript'] = $transcript_text;
		}
	}
	$event_schema['associatedMedia'] = $audio_object;
}

// Board pages only exist for these three meeting types; everything else falls back to the full listing
$board_urls = [
	'council'  => BASEURL.'/council.php',
	'planning' => BASEURL.'/planning.php',
	'zoning'   => BASEURL.'/zoning.php',
];

$breadcrumb_schema = [
	'@type'           => 'BreadcrumbList',
	'itemListElement' => [
		['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => BASEURL.'/'],
		['@type' => 'ListItem', 'position' => 2, 'name' => $meeting['board_type'], 'item' => $board_urls[$meeting['type']] ?? BASEURL.'/all_meetings.php'],
		['@type' => 'ListItem', 'position' => 3, 'name' => 'Transcript — '.$meeting_date_only, 'item' => $canonical_url],
	],
];

$vars = [
	'meeting'           => $meeting,
	'known_speakers'    => $known_speakers,
	'has_revai_json'    => $has_revai_json,
	'date_modified_iso' => $date_modified_iso,
	'json_ld'           => json_encode([
		'@context' => 'https://schema.org',
		'@graph'   => [$event_schema, $breadcrumb_schema],
	], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
];
displayPage('transcript.html', $vars);
