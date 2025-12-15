<?php
require(__DIR__.'/../init.php');

$spn = $argv[1];

var_dump(ArchiveOrg::isComplete($spn, $timestamp, true));
//var_dump(ArchiveOrg::archiveURL('https://www.piscatawaynj.org/government/meeting_schedules/index.php'));

echo "done\n";
