<?php
require(__DIR__.'/../init.php');
$vars = [
	'recent_meetings'     => Meeting::getRecent(),
	'meetings'            => Meeting::getUpcomingAndOlder(),
	'recent_transcripts'  => Meeting::getRecentWithTranscripts(5),
];
displayPage('index.html', $vars);
