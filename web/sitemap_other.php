<?php
require(__DIR__.'/../init.php');

Header('Content-Type: text/plain');

define('BASEURL', 'https://piscatawaynjmeetings.com');

// Meeting types with their own dedicated sitemap
const BOARD_SITEMAP_TYPES = ['council', 'planning', 'zoning'];

$sitemap = new SiteMapGenerator();
$sitemap->addEntry(BASEURL.'/');

foreach (glob(__DIR__.'/../web/*.php') as $file)
{
	$filename = basename($file);
	if (in_array($filename, [
		'meeting_edit.php',
		'meeting.php',
		'transcript.php',
		'assign_speaker.php',
		'ical.php',
		'index.php',
		'copylogger.php',
		'sitemap_council.php',
		'sitemap_planning.php',
		'sitemap_zoning.php',
		'sitemap_other.php',
	]))
	{
		continue;
	}
	$sitemap->addEntry(BASEURL.'/'.$filename);
}

foreach (Meeting::getAll() as $meeting)
{
	if (!in_array($meeting['type'], BOARD_SITEMAP_TYPES))
	{
		$meeting->addSitemapEntries($sitemap, BASEURL);
	}
}

foreach (Bid::getAll() as $bid)
{
	$sitemap->addEntry(BASEURL.$bid->getLink(), $bid['date_created']);
}

foreach (MiscFile::getAll() as $file)
{
	$sitemap->addEntry(BASEURL.$file->getLink(), $file['date']);
}

foreach (Newsletter::getAll() as $newsletter)
{
	$sitemap->addEntry(BASEURL.$newsletter->getLink());
}

foreach (CampaignFile::getAll() as $file)
{
	$sitemap->addEntry(BASEURL.$file->getLink());
}
$sitemap->finish();
