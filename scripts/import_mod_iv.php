<?php
require(__DIR__.'/../init.php');

// Backfills property_assessment and property_tax from state MOD-IV CSV exports
// (one row per parcel per mod_iv_year). Run after import_tax_data.php: existing rows
// from the county export win, and parcels not in `property` (since retired) are skipped.
// Only last_year_total_tax is trustworthy for taxes; the taxes_*_half* installment fields
// are stale copies carried forward unchanged since ~2020. Owner columns are never read.
// Usage: php import_mod_iv.php <file.csv> [<file.csv> ...]  (pass every file in one run)

if ($argc < 2) die("Usage: php {$argv[0]} <file.csv> [<file.csv> ...]\n");
ini_set('memory_limit', '2G');

$key = fn(string $b, string $l, string $q) => trim($b).'|'.trim($l).'|'.trim($q);

// year => block|lot|qual => [gis_pin, old key, location, land, improvement, last year tax]
$years = [];
foreach (array_slice($argv, 1) as $file) {
	$fh = fopen($file, 'r');
	$header = fgetcsv($fh);
	if (!$header || !in_array('gis_pin', $header)) die("Unexpected header in $file\n");
	while (($row = fgetcsv($fh)) !== false) {
		$r = array_combine($header, $row);
		$years[(int)$r['mod_iv_year']][$key($r['property_id_blk'], $r['property_id_lot'], $r['property_id_qualifier'])] = [
			$r['gis_pin'],
			trim($r['old_block']) !== '' ? $key($r['old_block'], $r['old_lot'], $r['old_qualifier']) : null,
			preg_replace('/\s+/', ' ', strtoupper(trim($r['property_location']))),
			$r['land_value'], $r['improvement_value'], $r['last_year_total_tax'],
		];
	}
	fclose($fh);
}
krsort($years);

// Parcels get renumbered (wholesale in 2012, piecemeal in other years), and old numbers get
// reused. Walk back one year at a time: a parcel's previous-year row is its own key if that
// still exists, else its recorded old key; if both exist, the one at the same address wins.
// Previous-year rows claimed by several current parcels (lot splits) are dropped.
$pins = array_flip($db->GetCol('SELECT pamspin FROM property'));
$current = [];
$prev = null;
foreach ($years as $year => $rows) {
	if ($prev === null) {
		foreach ($rows as $k => $r)
			if (isset($pins[$r[0]])) $current[$year][$k] = $r[0];
		$prev = $year;
		continue;
	}
	$link = $claimed = [];
	foreach ($current[$prev] as $k => $pin) {
		[, $old, $loc] = $years[$prev][$k];
		$cands = array_filter(array_unique([$k, $old]), fn($c) => $c !== null && isset($rows[$c]));
		if (count($cands) == 2)
			$cands = array_filter($cands, fn($c) => $rows[$c][2] === $loc);
		if (count($cands) != 1) continue;
		$c = reset($cands);
		if (isset($link[$c]) && $link[$c] !== $pin) $claimed[$c] = true;
		$link[$c] = $pin;
	}
	$current[$year] = array_diff_key($link, $claimed);
	$prev = $year;
}

$db->StartTrans();
$assessments = $taxes = $skipped = 0;
foreach ($years as $year => $rows) {
	foreach ($rows as $k => [, , , $land, $improvement, $tax]) {
		$pin = $current[$year][$k] ?? null;
		if ($pin === null) {
			$skipped++;
			continue;
		}
		$db->Execute('INSERT IGNORE INTO property_assessment VALUES (?,?,?,?)', [$pin, $year, $land, $improvement]);
		$assessments += $db->Affected_Rows();
		if ($tax !== '') {
			$db->Execute('INSERT IGNORE INTO property_tax (pamspin, year, taxes) VALUES (?,?,?)', [$pin, $year - 1, $tax]);
			$taxes += $db->Affected_Rows();
		}
	}
}
if (!$db->CompleteTrans()) die("Import failed: ".$db->ErrorMsg()."\n");
echo "Added $assessments assessments, $taxes tax years; skipped $skipped rows for retired or unmappable parcels\n";
