<?php
require(__DIR__.'/../init.php');
$vars = [
	'misc_files' => MiscFile::getAll(),
];
displayPage('misc_files.html', $vars);
