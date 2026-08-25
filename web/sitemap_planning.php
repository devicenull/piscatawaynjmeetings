<?php
require(__DIR__.'/../init.php');

Header('Content-Type: text/plain');

define('BASEURL', 'https://piscatawaynjmeetings.com');

$sitemap = new SiteMapGenerator();
foreach (Meeting::getAll() as $meeting)
{
	if ($meeting['type'] == 'planning')
	{
		$meeting->addSitemapEntries($sitemap, BASEURL);
	}
}
$sitemap->finish();
