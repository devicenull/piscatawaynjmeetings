<?php
class CADCall extends BaseDBObject
{
	var $fields = [
		'incident',
		'call_time',
		'location',
		'call_type',
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
}