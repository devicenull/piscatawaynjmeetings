<?php
ini_set('display_errors', 1);
require(__DIR__.'/../init.php');

$no_geocode = in_array('--no-geocode', $argv);
$args = array_filter($argv, fn($a) => $a[0] !== '-');
$file = end($args);

if (pathinfo($file, PATHINFO_EXTENSION) === 'xlsx')
{
	$f = popen('xlsx2csv '.escapeshellarg($file), 'r');
	$is_pipe = true;
}
else
{
	$f = fopen($file, 'r');
	$is_pipe = false;
}

$lines = 0;
$row = 0;
while (!feof($f))
{
	$data = fgetcsv($f);
	if ($data === false) continue;

	$row++;

	// Skip title row ("Dispatch Incident Search") and column header row
	if ($row <= 2) continue;

	// Skip blank/redacted rows — incident number must be numeric
	if (empty($data[0]) || !is_numeric($data[0])) continue;

	$cad = new CADCall(['incident' => $data[0]]);
	if ($cad->isInitialized()) continue;

	if (!$cad->add([
		'incident'  => $data[0],
		'call_time' => date('Y-m-d H:i:s', strtotime($data[1])),
		'location'  => trim($data[2].' '.$data[3]),
		'call_type' => $data[4],
		'no_geocode' => $no_geocode,
	]))
	{
		echo "UNABLE TO ADD: \n";
		var_dump($cad);
	}
	$lines++;
}

if ($is_pipe)
{
	pclose($f);
}
else
{
	fclose($f);
}

echo "Scanned $lines lines\n";
