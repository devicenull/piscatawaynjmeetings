<?php
class Address extends BaseDBObject
{
	var $fields = [
		'ADDRESSID',
		'address',
		'lat',
		'lng',
	];

	const DB_KEY = 'ADDRESSID';
	const DB_TABLE = 'address';

	public function __construct($params=[])
	{
		global $db;
		if (isset($params['address']))
		{
			$params['address'] = trim(strtolower($params['address']));
			$res = $this->construct_by_column('address', $params['address']);
			if ($res) return;
		}

		parent::__construct($params);

		if (!$this->isInitialized() && isset($params['address']))
		{
			// lookup coordinates via geoapify
			$c = curl_init('https://api.geoapify.com/v1/geocode/search?text='.urlencode($params['address'].', piscataway, new jersey').'&apiKey='.GEOAPIFY_KEY);
			curl_setopt_array($c, [
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT => 5,
					CURLOPT_HTTPHEADER => ['Accept: application/json'],
				],
			);
			$data = curl_exec($c);
			$json = json_decode($data, true);
			if (isset($json['features'][0]['geometry']['coordinates']))
			{
				if (round($json['features'][0]['geometry']['coordinates'][1], 6) == -74.466041 && round($json['features'][0]['geometry']['coordinates'][0], 6) == 40.546255)
				{
					// this is apparently the center of town
					echo "Got invalid coordinates for {$params['address']}\n";
					continue;
				}
				$this->add([
					'address' => $params['address'],
					'lat' => $json['features'][0]['geometry']['coordinates'][1],
					'lng' => $json['features'][0]['geometry']['coordinates'][0],
				]);
			}

			// limited to 5req/s on free plan
			usleep(1000000/5);
		}
	}
}
