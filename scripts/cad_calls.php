<?php
ini_set('display_errors', 1);
require(__DIR__.'/../init.php');
Header('Content-Type: text/csv');
Header('Content-Disposition: attachment; filename="piscataway_cad_calls.csv"');
Header('Access-Control-Allow-Origin: *');

$typemap = [
	'SICK PERS' => 'Sick Person',
	'DISORDERLY COND' => 'Disorderly Conduct',
	'911 HANGUP' => '911 Hangup',
	'NOISE COMPLAINT' => 'Noise Complaint',
	'TRF STOP' => 'Traffic Stop',
	'MV ACCIDENT' => 'Motor Vehicle Accident',
	'OTHER/POLICE' => 'Other Police Matter',
	'FOUND PROPERTY' => 'Found Property',
	'THEFT' => 'Theft',
	'TRAFFIC/OTHER' => 'Other Traffic Matter',
	'GAS LEAK' => 'Gas Leak',
	'BURG ALARM BUS' => 'Business Burglar Alarm',
	'PARKING' => 'Parking',
	'MISC EVENT' => 'Misc',
	'ASSIST CITIZEN' => 'Assist Citizen',
	'FIRE - OTHER' => 'Other Fire Matter',
	'WIRES DOWN' => 'Wires Down',
	'SUSP PER' => 'Suspicious Person',
	'CUSTODY DISPUTE' => 'Custody Dispute',
	'WELFARE CHK' => 'Welfare Check',
	'911 TRANSFER' => '911 Transfer (likely to another town)',
	'ANIMAL' => 'Animal',
	'HIT/RUN' => 'Hit & Run',
	'FRAUD' => 'Fraud',
	'LIGHT/SIGN' => 'Light/Sign',
	'FIRE ALARM' => 'Fire Alarm',
	'ACC PROP DAMAGE' => 'Accidental Property Damage',
	'WARRANT ARREST' => 'Arrest Warrant',
	'ESCORT' => 'Escort Citizen',
	'DECEASED' => 'Deceased Citizen',
	'LOST PROP' => 'Lost Property',
	'ASST OTH PD' => 'Assist Other Police Department',
	'SUSP ODOR' => 'Suspicious Odor',
	'BURG ALARM HOME' => 'Home Burglar Alarm',
	'SEC CHECK' => 'Security Check',
	'WELFARE CHK' => 'Welfare Check',
	'ACCIDENTAL INJ' => 'Acidental Injury',
	'RESTRAINING ORD' => 'Restraining Order',
	'FIRE - BRUSH' => 'Brush Fire',
	'HARASSMENT' => 'Harassment',
	'DISABLED MV' => 'Disabled Motor Vehicle',
	'AGG DRIVER' => 'Aggressive Driver',
	'DOMESTIC DISP' => 'Domestic Dispute',
	'SUSP VEH' => 'Suspicious Vehicle',
	'SUSP INC' => 'Suspicious Incident',
	'LOCKOUT' => 'Lockout',
	'VERBAL DISPUTE' => 'Verbal Dispute',
	'CO ALARM' => 'Carbon Monoxide Alarm',
	'BURGLARY' => 'Burglary',
	'REC STOLEN MV' => 'Recover Stolen Motor Vehicle',
	'ILLEGAL DUMPING' => 'Illegal Dumping',
	'MISS PERS' => 'Missing Person',
	'THEFT/MV' => 'Stolen Car',
	'DCPP REF' => 'DCPP REF (Division of Child Protection and Permanency?)',
	'CRIM MISCHIEF' => 'Criminal Mischief',
	'DWI' => 'Driving While Intoxicated',
	'TEST' => 'Test',
	'WEAPONS' => 'Weapons',
	'MUN COURT TRANS' => 'Municipal Court Transport',
	'ROR' => 'Released on Own Recognizance',
	'VIO OF TRO/FRO' => 'Violation of Temporary/Final Restraining Order',
	'ANIMAL BITE' => 'Animal Bite',
	'FIRE INVESTIGA' => 'Fire Investigation',
	'POLE/TREE DOWN' => 'Pole/Tree Down',
];

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
	if ($call['name'] != '')
	{
		$title = $call['name'].' - '.$call['location'];
	}
	else
	{
		$title = $call['location'];
	}
	fputcsv($f, [
		$call['incident'],
		$call['call_time'],
		$call['location'],
		$typemap[$call['call_type']] ?? $call['call_type'],
		$call['lat'],
		$call['lng'],
		json_encode([
			// https://github.com/simonw/datasette-cluster-map?tab=readme-ov-file#custom-marker-popups
	//		'image' => '/icons/911-hangup.svg',
			'title' => $title,
			'description' => $call['call_type'].' at '.$call['call_time'],
		]),
	]);
}
