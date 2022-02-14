<?php
require(__DIR__.'/../init.php');

$spn = $argv[1];

var_dump(ArchiveOrg::isComplete($spn, $timestamp, true));

echo "done\n";
