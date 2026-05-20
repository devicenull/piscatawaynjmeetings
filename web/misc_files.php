<?php
require(__DIR__.'/../init.php');
$vars = [
	'misc_files' => MiscFile::getByTypes(['debt_statements', 'other']),
];
displayPage('misc_files.html', $vars);
