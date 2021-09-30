<?php
require(__DIR__.'/../init.php');

foreach (Tweet::getPendingArchive() as $tweet)
{
	if (ArchiveOrg::isComplete($tweet['archive_job_id'], $timestamp))
	{
		$tweet->set([
			'archive_job_id' => '',
			'archive_url'    => 'https://web.archive.org/web/'.$timestamp.'/'.$tweet['archive_url'],
		]);
	}
}
