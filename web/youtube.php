<?php
require(__DIR__.'/../init.php');
$vars = [
	'videos' => YouTube::getAll(),
];
displayPage('youtube.html', $vars);
