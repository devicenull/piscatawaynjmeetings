<?php
require(__DIR__.'/../init.php');
$vars = [
	'campaign_files' => CampaignFile::getAll(),
];
displayPage('campaign_files.html', $vars);
