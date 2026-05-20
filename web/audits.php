<?php
require(__DIR__.'/../init.php');

displayPage('audits.html', [
	'audits'               => MiscFile::getByType('audits'),
	'financial_statements' => MiscFile::getByType('financial_statements'),
]);
