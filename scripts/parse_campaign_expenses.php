<?php
require_once(__DIR__.'/../init.php');

$filename = 'Rouse 2021';
$lines = explode("\n", file_get_contents(__DIR__.'/../web/files/campaign/'.$filename.'.txt'));

$cleaned_lines = [];
foreach ($lines as $cur)
{
	// remove this annoying footer..
	if (stripos($cur, 'Election Law Enforcement') === false && !empty($cur))
	{
		$cleaned_lines[] = $cur;
	}
}
$lines = $cleaned_lines;

/**
*	Example chunk:
Check No. Payee Name And Address Date Balance Amount Date Disbursed Amount Disbursed
1006 SECOND IMPRESSIONS, LLC
149 STELTON ROAD,
PISCATAWAY NJ 08854

$0.00 11/01/2021 $1,455.43

Purpose: DIRECT MAIL (PRINTING AND POSTAGE)
*/

$expenses = [];
$rownum = 0;
while ($rownum < count($lines))
{
	if (stripos($lines[$rownum], 'Check No.') !== false)
	{
		$checkinfo = explode(' ', $lines[$rownum+1], 2);
		$newpayee = [
			'check_number'  => $checkinfo[0],
			'payee_name'    => $checkinfo[1],
			'payee_address' => $lines[$rownum+2],
		];
		$newpayee['payee_address'] .= $lines[$rownum+3];
		$amountinfo =explode(' ', $lines[$rownum+4], 3);
		$newpayee['balance_amount'] = str_replace('$', '', $amountinfo[0]);
		$newpayee['date'] = $amountinfo[1];
		$newpayee['amount_disbursed'] = str_replace('$', '', $amountinfo[2]);

		$expenses[] = $newpayee;
		$rownum += 4;
	}
	else
	{
		$rownum += 1;
	}
}

$f = fopen(__DIR__.'/../web/files/campaign/'.$filename.'.csv', 'w');
fputcsv($f, array_keys($expenses[0]));
foreach ($expenses as $row)
{
	fputcsv($f, $row);
}

echo "Done\n";