<?php
require(__DIR__.'/../init.php');
$vars = [
	'meeting' => new Meeting(['MEETINGID' => $_GET['MEETINGID']]),
];
displayPage('transcript.html', $vars);
