<?php
require(__DIR__.'/../init.php');

// Imports a county tax-record export (TaxData_*.xlsx) into property + property_tax (sheet 1)
// and property_assessment (sheet 3).
// Usage: php import_tax_data.php <file.xlsx> <export year>
// CurrentYearTaxes is stored for <export year>, LastYearTaxes for <export year>-1.
// Owner name/address columns are deliberately never read into the database.

if ($argc != 3 || !ctype_digit($argv[2]))
	die("Usage: php {$argv[0]} <file.xlsx> <export year>\n");
[, $file, $year] = $argv;
$year = (int)$year;

$db->Execute("CREATE TABLE IF NOT EXISTS property (
	pamspin varchar(32) NOT NULL,
	block varchar(20) NOT NULL,
	lot varchar(20) NOT NULL,
	qual varchar(20) DEFAULT NULL,
	property_location varchar(255) NOT NULL,
	property_class_code varchar(8) NOT NULL,
	building_description varchar(64) DEFAULT NULL,
	year_built smallint DEFAULT NULL,
	building_sqft int DEFAULT NULL,
	land_description varchar(64) DEFAULT NULL,
	sale_date date DEFAULT NULL,
	sale_price bigint DEFAULT NULL,
	sale_assessment bigint DEFAULT NULL,
	exempt_statute_number varchar(32) DEFAULT NULL,
	facility varchar(64) DEFAULT NULL,
	PRIMARY KEY (pamspin),
	KEY block_lot (block, lot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$db->Execute("CREATE TABLE IF NOT EXISTS property_tax (
	pamspin varchar(32) NOT NULL,
	year int NOT NULL,
	taxes decimal(12,2) NOT NULL COMMENT 'Total annual tax billed, dollars',
	PRIMARY KEY (pamspin, year),
	KEY year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$db->Execute("CREATE TABLE IF NOT EXISTS property_assessment (
	pamspin varchar(32) NOT NULL,
	year int NOT NULL,
	land_value bigint NOT NULL,
	improvement_value bigint NOT NULL,
	PRIMARY KEY (pamspin, year),
	KEY year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// The export uses 0 / 01/01/0001 / 000000000000 as "no value"
function nullIfBlank(string $s): ?string
{
	$s = trim($s);
	return ($s === '' || trim($s, '0') === '') ? null : $s;
}

function parseDate(string $s): ?string
{
	$d = DateTime::createFromFormat('!m/d/Y', trim($s));
	return ($d && $d->format('Y') > 1800) ? $d->format('Y-m-d') : null;
}

$csv = popen('xlsx2csv -s 1 '.escapeshellarg($file), 'r');
$header = fgetcsv($csv);
if (!$header || $header[0] !== 'PamsPin') die("Unexpected header in $file\n");

$db->StartTrans();
$count = 0;
while (($row = fgetcsv($csv)) !== false) {
	$r = array_combine($header, $row);
	$db->Execute('REPLACE INTO property VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
		$r['PamsPin'], $r['Block'], $r['Lot'], nullIfBlank($r['Qual']),
		trim($r['PropertyLocation']), $r['PropertyClassCode'], nullIfBlank($r['BuildingDescription']),
		nullIfBlank($r['YearBuilt']), nullIfBlank($r['BuildingSqFt']), nullIfBlank($r['LandDescription']),
		parseDate($r['SaleDate']), nullIfBlank($r['SalePrice']), nullIfBlank($r['SaleAssessment']),
		nullIfBlank($r['ExemptStatuteNumber']), nullIfBlank($r['Facility']),
	]);
	// Export amounts are in cents
	foreach ([$year => 'CurrentYearTaxes', $year - 1 => 'LastYearTaxes'] as $y => $col) {
		$db->Execute('INSERT INTO property_tax (pamspin, year, taxes) VALUES (?,?,?)
			ON DUPLICATE KEY UPDATE taxes = VALUES(taxes)', [$r['PamsPin'], $y, (int)$r[$col] / 100]);
	}
	$count++;
}
pclose($csv);

// Sheet 3: assessed value history, one row per parcel per year
$csv = popen('xlsx2csv -s 3 '.escapeshellarg($file), 'r');
$header = fgetcsv($csv);
if (!$header || $header[4] !== 'Year') die("Unexpected assessment header in $file\n");
$assessments = 0;
while (($row = fgetcsv($csv)) !== false) {
	$r = array_combine($header, $row);
	$db->Execute('REPLACE INTO property_assessment VALUES (?,?,?,?)', [
		$r['PamsPin'], $r['Year'], $r['LandValue'], $r['ImprovementValue'],
	]);
	$assessments++;
}
pclose($csv);

if (!$db->CompleteTrans()) die("Import failed: ".$db->ErrorMsg()."\n");
echo "Imported $count properties, $assessments assessments\n";
