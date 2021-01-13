<?php
require(__DIR__.'/../init.php');
$vars = [
	'recent_meetings' => Meeting::getRecent(),	
	'meetings'        => Meeting::getAll(),
	'has_edit_auth'   => hasEditAuth(),
];
displayPage('index.html', $vars);
