<?php
require(__DIR__.'/../init.php');
$vars = [
	'meetings' => Meeting::getAll(),
];
displayPage('all_meetings.html', $vars);
