<?php
ini_set('display_errors', 1);
require(__DIR__.'/../init.php');

$lines = 0;
$f = fopen($argv[1], 'r');
while (!feof($f))
{
	$data = fgetcsv($f);
	if (!empty($data) == 0) continue;

	$cad = new CADCall(['incident' => $data[0]]);
	if ($cad->isInitialized()) continue;

	if (!$cad->add([
		'incident' => $data[0],
		'call_time' => strftime('%F %T', strtotime($data[1])),
		'location' => trim($data[2].' '.$data[3]),
		'call_type' => $data[4],
	]))
	{
		echo "UNABLE TO ADD: \n";
		var_dump($cad);
	}
	$lines++;
}
echo "Scanned $lines lines\n";
