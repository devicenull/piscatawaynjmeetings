<?php
require(__DIR__.'/../init.php');

$month = $_GET['month'] ?? null;

if ($month !== null && !preg_match('/^\d{4}-\d{2}$/', $month)) {
	$month = null;
}

if ($month !== null) {
	$allMonths   = array_keys(CADCall::getMonthlyTotals());
	$monthIndex  = array_search($month, $allMonths);
	$prevMonth   = ($monthIndex !== false && $monthIndex > 0)                    ? $allMonths[$monthIndex - 1] : null;
	$nextMonth   = ($monthIndex !== false && $monthIndex < count($allMonths) - 1) ? $allMonths[$monthIndex + 1] : null;

	$dt          = new DateTime($month.'-01');
	$daysInMonth = (int)$dt->format('t');
	$monthLabel  = $dt->format('F Y');

	$daily = CADCall::getDailyTotals($month);
	$types = CADCall::getTopTypes($month, 12);
	$addrs = CADCall::getTopAddresses($month, 10);
	$total = CADCall::getMonthTotal($month);
	$calls = CADCall::getCallsForMonth($month);

	// Daily array — fill gaps
	$dailyFull = [];
	for ($d = 1; $d <= $daysInMonth; $d++) {
		$dailyFull[] = $daily[$d] ?? 0;
	}

	// Type slices (initial render) — human-readable labels
	$typeSlices = [];
	$typedTotal = 0;
	foreach ($types as $code => $count) {
		$typeSlices[] = ['name' => CADCall::getTypeName($code), 'y' => $count];
		$typedTotal  += $count;
	}
	if ($total - $typedTotal > 0) $typeSlices[] = ['name' => 'Other', 'y' => $total - $typedTotal, 'color' => '#cccccc'];

	// Address slices (initial render)
	$addrSlices = [];
	$addrTotal  = 0;
	foreach ($addrs as $name => $count) {
		$addrSlices[] = ['name' => $name, 'y' => $count];
		$addrTotal   += $count;
	}
	if ($total - $addrTotal > 0) $addrSlices[] = ['name' => 'Other', 'y' => $total - $addrTotal, 'color' => '#cccccc'];

	// Compact per-call data for JS filtering: [day, typeIdx, addrIdx]
	// typeNames / addrNames cover ALL distinct values (not just top-N)
	$typeIndex   = []; // code  => int idx
	$typeNameArr = []; // [human name, ...]
	$addrIndex   = []; // display_location => int idx
	$addrNameArr = []; // [display_location, ...]
	$callData    = [];

	foreach ($calls as &$call) {
		$code = $call['call_type'];
		if (!array_key_exists($code, $typeIndex)) {
			$typeIndex[$code]  = count($typeNameArr);
			$typeNameArr[]     = CADCall::getTypeName($code);
		}
		$loc = $call['display_location'] ?: 'Unknown Location';
		if (!array_key_exists($loc, $addrIndex)) {
			$addrIndex[$loc]   = count($addrNameArr);
			$addrNameArr[]     = $loc;
		}
		$call['type_name']     = $typeNameArr[$typeIndex[$code]];
		$call['display_location'] = $loc;
		$callData[]            = [
			(int)date('j', strtotime($call['call_time'])),
			$typeIndex[$code],
			$addrIndex[$loc],
		];
	}
	unset($call);

	// Top 3 for meta description
	$topList = implode(', ', array_slice(array_map(
		fn($s) => $s['name'].' ('.$s['y'].')',
		array_filter($typeSlices, fn($s) => $s['name'] !== 'Other')
	), 0, 3));

	$vars = [
		'view'             => 'month',
		'month'            => $month,
		'month_label'      => $monthLabel,
		'prev_month'       => $prevMonth,
		'next_month'       => $nextMonth,
		'total'            => $total,
		'days_in_month'    => $daysInMonth,
		'daily_json'       => json_encode($dailyFull),
		'type_slices_json' => json_encode($typeSlices),
		'addr_slices_json' => json_encode($addrSlices),
		'type_names_json'  => json_encode($typeNameArr),
		'addr_names_json'  => json_encode($addrNameArr),
		'call_data_json'   => json_encode($callData),
		'calls'            => $calls,
		'top_list'         => $topList,
	];
} else {
	$months = CADCall::getMonthlyTotals();
	$max    = $months ? max($months) : 1;

	$vars = [
		'view'   => 'calendar',
		'months' => $months,
		'max'    => $max,
	];
}

displayPage('cad_analysis.html', $vars);
