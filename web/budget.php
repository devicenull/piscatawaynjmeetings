<?php
require(__DIR__.'/../init.php');

global $db;

// Build year → file lookups for budget PDFs and debt statements
$budgetFiles = [];
foreach (MiscFile::getByType('budget') as $f)
	$budgetFiles[(int)date('Y', strtotime($f['date']))] = $f;

$debtFiles = [];
foreach (MiscFile::getByType('debt_statements') as $df)
	$debtFiles[(int)date('Y', strtotime($df['date']))] = $df;

$statsRows = $db->Execute('SELECT * FROM budget_stats ORDER BY year');
$stats = [];
foreach ($statsRows as $row) {
	$tv  = $row['taxable_valuation']      !== null ? (int)$row['taxable_valuation']      : null;
	$td  = $row['total_debt']             !== null ? (int)$row['total_debt']             : null;
	$art = $row['amount_raised_by_taxes'] !== null ? (int)$row['amount_raised_by_taxes'] : null;
	$mun = $row['municipal_purpose_tax']  !== null ? (int)$row['municipal_purpose_tax']  : null;
	$sch = $row['local_school_district']  !== null ? (int)$row['local_school_district']  : null;
	$cnt = $row['county_purposes']        !== null ? (int)$row['county_purposes']        : null;
	$oth = $row['other_taxes']            !== null ? (int)$row['other_taxes']            : null;

	$fmt = fn(?int $v, float $div, int $dec, string $sfx) =>
		$v !== null ? '$' . number_format($v / $div, $dec) . $sfx : null;
	$exact = fn(?int $v) => $v !== null ? '$' . number_format($v) : null;

	$stats[(int)$row['year']] = [
		'taxable_valuation'      => $tv,
		'total_debt'             => $td,
		'amount_raised_by_taxes' => $art,
		'municipal_purpose_tax'  => $mun,
		'local_school_district'  => $sch,
		'county_purposes'        => $cnt,
		'other_taxes'            => $oth,
		'taxable_fmt'    => $fmt($tv,  1e9, 2, 'B'),
		'debt_fmt'       => $fmt($td,  1e6, 1, 'M'),
		'raised_fmt'     => $fmt($art, 1e6, 1, 'M'),
		'municipal_fmt'  => $fmt($mun, 1e6, 1, 'M'),
		'school_fmt'     => $fmt($sch, 1e6, 1, 'M'),
		'county_fmt'     => $fmt($cnt, 1e6, 1, 'M'),
		'other_fmt'      => $fmt($oth, 1e6, 1, 'M'),
		'taxable_exact'  => $exact($tv),
		'debt_exact'     => $exact($td),
		'raised_exact'   => $exact($art),
		'municipal_exact'=> $exact($mun),
		'school_exact'   => $exact($sch),
		'county_exact'   => $exact($cnt),
		'other_exact'    => $exact($oth),
	];
}

// Build chart series (only years with any data)
$chartYears = $chartTaxable = $chartDebt = $chartMunicipal = $chartSchool = $chartCounty = $chartOther = [];
foreach ($stats as $year => $row) {
	if (!$row['taxable_valuation'] && !$row['total_debt'] && !$row['amount_raised_by_taxes']) continue;
	$chartYears[]    = $year;
	$chartTaxable[]  = $row['taxable_valuation']     !== null ? round($row['taxable_valuation'] / 1e9, 3)     : null;
	$chartDebt[]     = $row['total_debt']            !== null ? round($row['total_debt'] / 1e6, 1)            : null;
	$chartMunicipal[]= $row['municipal_purpose_tax'] !== null ? round($row['municipal_purpose_tax'] / 1e6, 1) : null;
	$chartSchool[]   = $row['local_school_district'] !== null ? round($row['local_school_district'] / 1e6, 1) : null;
	$chartCounty[]   = $row['county_purposes']       !== null ? round($row['county_purposes'] / 1e6, 1)       : null;
	$chartOther[]    = $row['other_taxes']           !== null ? round($row['other_taxes'] / 1e6, 1)           : null;
}

// Most-recent complete year for headline cards
$latest = null;
foreach (array_reverse($stats, true) as $year => $row) {
	if ($row['taxable_valuation'] && $row['total_debt'] && $row['amount_raised_by_taxes']) {
		$latest = ['year' => $year] + $row;
		break;
	}
}

// Unified year list: all years that have a budget file, debt statement, or stats row
$allYears = array_unique(array_merge(
	array_keys($budgetFiles),
	array_keys($debtFiles),
	array_keys($stats)
));
rsort($allYears);

displayPage('budget.html', [
	'years'           => $allYears,
	'budget_files'    => $budgetFiles,
	'debt_files'      => $debtFiles,
	'stats'           => $stats,
	'latest'          => $latest,
	'chart_years'     => json_encode($chartYears),
	'chart_taxable'   => json_encode($chartTaxable),
	'chart_debt'      => json_encode($chartDebt),
	'chart_municipal' => json_encode($chartMunicipal),
	'chart_school'    => json_encode($chartSchool),
	'chart_county'    => json_encode($chartCounty),
	'chart_other'     => json_encode($chartOther),
]);
