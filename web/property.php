<?php
require(__DIR__.'/../init.php');

$property = new Property(['pamspin' => $_GET['pamspin'] ?? '']);
if (!$property->isInitialized())
	displayError('Property not found', '/properties.php');

$history = $property->getHistory();
$col = fn(string $k) => json_encode(array_column($history, $k));

displayPage('property.html', [
	'property'          => $property,
	'history'           => $history,
	'chart_years'       => json_encode(array_keys($history)),
	'chart_taxes'       => $col('taxes'),
	'chart_land'        => $col('land_value'),
	'chart_improvement' => $col('improvement_value'),
]);
