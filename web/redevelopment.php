<?php
require(__DIR__.'/../init.php');
$vars = [
	'studies' => RedevelopmentStudy::getAll(),
];
displayPage('redevelopment.html', $vars);
