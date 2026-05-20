<?php
require(__DIR__.'/../init.php');

displayPage('audits.html', [
	'audits' => MiscFile::getByType('audits'),
]);
