<?php
ini_set('display_errors', 1);
require(__DIR__.'/../init.php');

$lines = 0;
$f = fopen($argv[1], 'r');
while (!feof($f))
{
	$data = fgetcsv($f);
	if (!empty($data) == 0) continue;

	$cad = new CADCall();
	if (!$cad->add([
		'incident' => $data[0],
		'call_time' => strftime('%F %T', strtotime($data[1])),
		'location' => $data[2],
		'call_type' => $data[3],
	]))
	{
		echo "UNABLE TO ADD: \n";
		var_dump($cad);
	}
	$lines++;
}
echo "Scanned $lines lines\n";