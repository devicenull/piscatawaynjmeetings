<?php
require(__DIR__.'/../init.php');
$vars = [
	'recent_meetings' => Meeting::getRecent(),	
	'meetings'        => Meeting::getUpcomingAndOlder(),
];
displayPage('index.html', $vars);
