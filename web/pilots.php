<?php
require(__DIR__ . '/../init.php');

global $db;

// Per-project, per-year billing data
$rows = $db->GetAll(
    'SELECT data_year, project_name, project_type,
            agreement_start_date, agreement_end_date,
            pilot_billing, assessed_value, taxes_if_billed_full
     FROM pilot_payments
     ORDER BY data_year, project_name'
);

// Build project map: name → metadata + payments by year
$projects = [];
$dataYears = [];
foreach ($rows as $r) {
    $name = $r['project_name'];
    $year = (int)$r['data_year'];
    $dataYears[$year] = true;

    if (!isset($projects[$name])) {
        $projects[$name] = [
            'name'       => $name,
            'type'       => $r['project_type'],
            'start_date' => $r['agreement_start_date'],
            'end_date'   => $r['agreement_end_date'],
            'payments'   => [],   // year => billing
            'assessed'   => [],   // year => assessed_value
            'taxes_full' => [],   // year => taxes_if_billed_full
        ];
    }
    if ($r['pilot_billing'] !== null)
        $projects[$name]['payments'][$year] = (float)$r['pilot_billing'];
    if ($r['assessed_value'] !== null)
        $projects[$name]['assessed'][$year] = (int)$r['assessed_value'];
    if ($r['taxes_if_billed_full'] !== null)
        $projects[$name]['taxes_full'][$year] = (float)$r['taxes_if_billed_full'];
}
ksort($dataYears);
$dataYears = array_keys($dataYears);

// Sort projects: currently-active ones first (have data in latest year), then alphabetical
$latestYear = end($dataYears);
usort($projects, function ($a, $b) use ($latestYear) {
    $aActive = isset($a['payments'][$latestYear]) ? 1 : 0;
    $bActive = isset($b['payments'][$latestYear]) ? 1 : 0;
    if ($aActive !== $bActive) return $bActive - $aActive;
    return strcmp($a['name'], $b['name']);
});

// Annual revenue trend from budget_stats
$revenueRows = $db->GetAll(
    'SELECT year, pilot_revenue, pilot_revenue_anticipated
     FROM budget_stats
     WHERE pilot_revenue IS NOT NULL OR pilot_revenue_anticipated IS NOT NULL
     ORDER BY year'
);
$revenue = [];
foreach ($revenueRows as $r) {
    $revenue[(int)$r['year']] = [
        'realized'    => $r['pilot_revenue']             !== null ? (int)$r['pilot_revenue']             : null,
        'anticipated' => $r['pilot_revenue_anticipated'] !== null ? (int)$r['pilot_revenue_anticipated'] : null,
    ];
}

// Summary cards: latest year with full data
$latestRevenue = null;
$latestBilling = null;
$latestAssessed = null;
$latestTaxesFull = null;
foreach ($projects as $p) {
    if (isset($p['payments'][$latestYear]))
        $latestBilling = ($latestBilling ?? 0) + $p['payments'][$latestYear];
    if (isset($p['assessed'][$latestYear]))
        $latestAssessed = ($latestAssessed ?? 0) + $p['assessed'][$latestYear];
    if (isset($p['taxes_full'][$latestYear]))
        $latestTaxesFull = ($latestTaxesFull ?? 0) + $p['taxes_full'][$latestYear];
}
$activeProjectCount = count(array_filter($projects, fn($p) => isset($p['payments'][$latestYear])));

// Chart: stacked column per project per year (top 10 by total billing)
$topProjects = $projects;
usort($topProjects, function ($a, $b) {
    return array_sum($b['payments']) <=> array_sum($a['payments']);
});
$topProjects = array_slice($topProjects, 0, 12);

// Per-year totals for missed tax line (taxes_if_billed_full - pilot_billing)
$yearTaxesFull = [];
$yearBilling   = [];
foreach ($projects as $p) {
    foreach ($p['taxes_full'] as $yr => $v) $yearTaxesFull[$yr] = ($yearTaxesFull[$yr] ?? 0) + $v;
    foreach ($p['payments']   as $yr => $v) $yearBilling[$yr]   = ($yearBilling[$yr]   ?? 0) + $v;
}

// Chart: revenue trend (realized + anticipated + missed)
$chartRevYears = $chartRealized = $chartAnticipated = $chartMissed = [];
foreach ($revenue as $year => $r) {
    $chartRevYears[]    = $year;
    $realized = $r['realized'] ?? $yearBilling[$year] ?? null;
    $chartRealized[]    = $realized !== null ? round($realized / 1e6, 3) : null;
    $chartAnticipated[] = $r['anticipated'] !== null ? round($r['anticipated'] / 1e6, 3) : null;
    $missed = (isset($yearTaxesFull[$year]) && isset($yearBilling[$year]))
        ? round(-($yearTaxesFull[$year] - $yearBilling[$year]) / 1e6, 3)
        : null;
    $chartMissed[] = $missed;
}

// Chart: stacked billing by project
$stackedSeries = [];
$chartColors = [
    '#111111','#F5C000','#e6a817','#888888','#cccccc',
    '#4e79a7','#f28e2b','#76b7b2','#59a14f','#edc948','#b07aa1','#ff9da7',
];
foreach ($topProjects as $i => $p) {
    $seriesData = [];
    foreach ($dataYears as $y) {
        $seriesData[] = isset($p['payments'][$y]) ? round($p['payments'][$y] / 1e6, 4) : null;
    }
    $stackedSeries[] = [
        'name'  => $p['name'],
        'data'  => $seriesData,
        'color' => $chartColors[$i % count($chartColors)],
    ];
}

displayPage('pilots.html', [
    'projects'           => $projects,
    'data_years'         => $dataYears,
    'latest_year'        => $latestYear,
    'active_count'       => $activeProjectCount,
    'latest_billing'     => $latestBilling,
    'latest_assessed'    => $latestAssessed,
    'latest_taxes_full'  => $latestTaxesFull,
    'chart_rev_years'    => json_encode($chartRevYears),
    'chart_realized'     => json_encode($chartRealized),
    'chart_anticipated'  => json_encode($chartAnticipated),
    'chart_missed'       => json_encode($chartMissed),
    'chart_stacked_years'=> json_encode($dataYears),
    'chart_stacked'      => json_encode(array_values($stackedSeries)),
]);
