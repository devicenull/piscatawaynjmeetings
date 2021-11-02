<?php
require(__DIR__.'/../init.php');
$vars = [
	'tweets' => Tweet::getAll(false),
];
displayPage('tweets.html', $vars);
