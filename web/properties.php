<?php
require(__DIR__.'/../init.php');

global $db;

$year = (int)$db->GetOne('SELECT MAX(year) FROM property_assessment');

$rows = [];
foreach ($db->Execute(
	'SELECT p.pamspin, p.block, p.lot, p.qual, p.property_location, p.property_class_code,
		p.building_description, p.year_built, p.building_sqft, t.taxes, a.land_value + a.improvement_value AS assessed
	 FROM property p
	 LEFT JOIN property_tax t ON t.pamspin = p.pamspin AND t.year = ?
	 LEFT JOIN property_assessment a ON a.pamspin = p.pamspin AND a.year = ?', [$year, $year]) as $r)
{
	$rows[] = [
		$r['pamspin'], $r['block'], $r['lot'], $r['qual'], $r['property_location'],
		Property::CLASS_NAMES[$r['property_class_code']] ?? $r['property_class_code'],
		$r['building_description'], $r['year_built'], $r['building_sqft'],
		$r['assessed'] !== null ? (int)$r['assessed'] : null,
		$r['taxes'] !== null ? (float)$r['taxes'] : null,
	];
}

displayPage('properties.html', [
	'year' => $year,
	'rows' => json_encode($rows),
]);
