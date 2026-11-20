<?php
require(__DIR__.'/../init.php');
$vars = [
	'recent_meetings'     => Meeting::getRecent(),
	'meetings'            => Meeting::getUpcomingAndOlder(),
	'recent_transcripts'  => Meeting::getRecentWithTranscripts(5),
];
cacheFor(Meeting::cacheTtl());
displayPage('index.html', $vars);
