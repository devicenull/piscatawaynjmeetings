<?php
require(__DIR__.'/../init.php');
$vars = [
	'tweets' => Tweet::getAll(),
];
displayPage('tweets.html', $vars);
