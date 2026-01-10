<?php
class CADCall extends BaseDBObject
{
	var $fields = [
		'incident',
		'call_time',
		'location',
		'call_type',
		'ADDRESSID',
		'name',
	];

	var $virtual_fields = [
		'lat',
		'lng',
	];

	const DB_KEY = 'incident';
	const DB_TABLE = 'cad_call';

	var $insert_ignore = true;

	public function add(array $params): bool
	{
		if (!isset($params['lat']) || !isset($params['lng']))
		{
			$address = new Address(['address' => $params['location']]);
			if ($address->isInitialized())
			{
				$params['lat'] = $address['lat'];
				$params['lng'] = $address['lng'];
			}
		}

		return parent::add($params);
	}

	public static function getAll()
	{
		global $db;
		// only have full cad logs going back to oct 2025, don't taint the data with older records from just two addresses
		$res = $db->Execute('
			select *
			from cad_call
			left join address using(ADDRESSID)
			where call_time > "2025-10-01"
			order by incident
		');
		$calls = [];
		foreach ($res as $cur)
		{
			$calls[] = new CADCall(['record' => $cur]);
		}

		return $calls;
	}

	public function get($offset)
	{
		//fixme: dynamically fetch these if needed
		if ($offset == 'lat' || $offset == 'lng')
		{
			return $this->record[$offset];
		}
		return parent::get($offset);;
	}
}
