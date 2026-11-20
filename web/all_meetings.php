<?php
require(__DIR__.'/../init.php');
$vars = [
	'meetings' => Meeting::getAll(),
];
cacheFor(Meeting::cacheTtl());
displayPage('all_meetings.html', $vars);
