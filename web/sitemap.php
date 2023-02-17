<?php
require(__DIR__.'/../init.php');

Header('Content-Type: text/plain');

define('BASEURL', 'https://piscatawaynjmeetings.com');

$sitemap = new SiteMapGenerator();
$sitemap->addEntry(BASEURL.'/');
foreach (glob(__DIR__.'/../web/*.php') as $file)
{
	$filename = basename($file);
	if (in_array($filename, [
		'meeting_edit.php',
		'transcript.php',
		'sitemap.php',
		'index.php',
	]))
	{
		continue;
	}
	$sitemap->addEntry(BASEURL.'/'.$filename);
}

foreach (Meeting::getAll() as $meeting)
{
	if ($meeting['minutes_available'] == 'yes')
	{
		$sitemap->addEntry(BASEURL.$meeting->getLink('minutes'), $meeting['last_updated']);
	}
	if ($meeting['recording_available'] == 'yes')
	{
		$sitemap->addEntry(BASEURL.$meeting->getLink('recording'), $meeting['last_updated']);
	}
	if ($meeting['transcript_available'] == 'yes')
	{
		$sitemap->addEntry(BASEURL.'/meeting.php?MEETINGID='.$meeting['MEETINGID'], $meeting['last_updated']);
		$sitemap->addEntry(BASEURL.$meeting->getLink('transcript'), $meeting['last_updated']);
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
