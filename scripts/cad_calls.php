<?php
require(__DIR__.'/../init.php');
Header('Content-Type: text/csv');
Header('Content-Disposition: attachment; filename="piscataway_cad_calls.csv"');
Header('Access-Control-Allow-Origin: *');

$f = fopen('php://output', 'w');
fputcsv($f, [
	'Incident No',
	'Date',
	'Address',
	'Incident Type',
	'Latitude',
	'Longitude',
	'popup',
]);

foreach (CADCall::getAll() as $call)
{
	fputcsv($f, [
		$call['incident'],
		$call['call_time'],
		$call['location'],
		$call['call_type'],
		$call['lat'],
		$call['lng'],
		json_encode([
			// https://github.com/simonw/datasette-cluster-map?tab=readme-ov-file#custom-marker-popups
			'image' => '/icons/911-hangup.svg',
			'title' => $call['location'],
			'description' => $call['call_type'].' at '.$call['call_time'],
		]),
	]);
}
