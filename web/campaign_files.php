<?php
require(__DIR__.'/../init.php');
$campaigns = CampaignFile::getCampaigns();

if (isset($_GET['year'], $_GET['campaign']))
{
	$campaign = $campaigns[$_GET['year']][$_GET['campaign']] ?? null;
	if (!$campaign)
	{
		displayError('Campaign not found', '/campaign_files.php');
	}
	foreach ($campaign['data'] as $kind => $file)
	{
		$campaign[$kind] = CampaignFile::readCSV($file);
	}
	displayPage('campaign_detail.html', ['campaign' => $campaign]);
}
else
{
	displayPage('campaign_files.html', ['campaigns' => $campaigns]);
}
