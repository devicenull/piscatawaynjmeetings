<?php
require(__DIR__.'/../init.php');
$vars = [
	'newsletters' => Newsletter::getAll(),
];
displayPage('newsletter.html', $vars);
