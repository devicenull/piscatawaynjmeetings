<?php
// A tax parcel, imported by scripts/import_tax_data.php
class Property extends BaseDBObject
{
	var $fields = [
		'pamspin',
		'block',
		'lot',
		'qual',
		'property_location',
		'property_class_code',
		'building_description',
		'year_built',
		'building_sqft',
		'land_description',
		'sale_date',
		'sale_price',
		'sale_assessment',
		'exempt_statute_number',
		'facility',
	];
	var $virtual_fields = ['class_name'];

	const DB_KEY = 'pamspin';
	const DB_TABLE = 'property';

	// NJ property class codes
	const CLASS_NAMES = [
		'1'   => 'Vacant Land',
		'2'   => 'Residential',
		'3A'  => 'Farm (Regular)',
		'3B'  => 'Farm (Qualified)',
		'4A'  => 'Commercial',
		'4B'  => 'Industrial',
		'4C'  => 'Apartment',
		'5A'  => 'Railroad (Class I)',
		'5B'  => 'Railroad (Class II)',
		'15A' => 'Public School',
		'15B' => 'Other School',
		'15C' => 'Public Property',
		'15D' => 'Church & Charitable',
		'15E' => 'Cemetery',
		'15F' => 'Other Exempt',
	];

	public function get($offset)
	{
		if ($offset == 'class_name')
			return self::CLASS_NAMES[$this->record['property_class_code']] ?? $this->record['property_class_code'];
		return parent::get($offset);
	}

	// year => [taxes, land_value, improvement_value], any of which may be null
	public function getHistory(): array
	{
		global $db;
		$history = [];
		foreach ($db->GetAll('SELECT year, taxes FROM property_tax WHERE pamspin=?', [$this['pamspin']]) as $r)
			$history[(int)$r['year']]['taxes'] = (float)$r['taxes'];
		foreach ($db->GetAll('SELECT year, land_value, improvement_value FROM property_assessment WHERE pamspin=?', [$this['pamspin']]) as $r) {
			$history[(int)$r['year']]['land_value'] = (int)$r['land_value'];
			$history[(int)$r['year']]['improvement_value'] = (int)$r['improvement_value'];
		}
		ksort($history);
		foreach ($history as &$h)
			$h += ['taxes' => null, 'land_value' => null, 'improvement_value' => null];
		return $history;
	}
}
