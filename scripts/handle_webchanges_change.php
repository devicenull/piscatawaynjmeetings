<?php
require(__DIR__.'/../init.php');

file_put_contents('/tmp/last_jobs', getenv('WEBCHANGES_REPORT_CHANGED_JOBS'));

// WEBCHANGES_REPORT_CHANGED_JOBS ->
// [{"url": "https://www.piscatawaynj.org/departments/administration/human_resources/employment_opportunities.php?1=4", "name": "piscataway jobs"}]

$changes = json_decode(getenv('WEBCHANGES_REPORT_CHANGED_JOBS'), true);

if (!empty($changes))
{
	foreach ($changes as $job)
	{
		$job_id = ArchiveOrg::archiveURL($job['url']);
		echo "archive.org job started, id ".$job_id."\n";
	}
}
