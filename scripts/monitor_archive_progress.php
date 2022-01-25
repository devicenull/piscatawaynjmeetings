<?php
require(__DIR__.'/../init.php');

foreach (Tweet::getPendingArchive() as $tweet)
{
	$timestamp = '';
	if (ArchiveOrg::isComplete($tweet['archive_job_id'], $timestamp))
	{
		$tweet->set([
			'archive_job_id' => '',
			'archive_url'    => 'https://web.archive.org/web/'.$timestamp.'/'.$tweet['archive_url'],
		]);
	}
}

foreach (SavePageNowJob::getPending() as $spn)
{
	if (!ArchiveOrg::isComplete($spn['SPNID']))
	{
		continue;
	}

	foreach (ArchiveOrg::getOutlinks($spn['SPNID']) as $outlink)
	{
		if (preg_match('/\\.(doc|pdf|docx)$/i', $outlink))
		{
			if (!SavePageNowJob::jobExists($outlink))
			{
				echo "Archiving {$outlink}\n";
				ArchiveOrg::archiveURL($outlink, 'outlink');
			}
			else
			{
				echo "Already archived - {$outlink}\n";
			}
		}
		else
		{
			echo "Ignoring {$outlink}\n";
		}
	}

	$spn->set(['status' => 'success']);
}

echo "done\n";
