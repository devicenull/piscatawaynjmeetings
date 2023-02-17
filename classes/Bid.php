<?php
class Bid extends BaseDBObject
{
	var $fields = [
		'BIDID',
		'date_created',
		'filename',
	];

	const DB_KEY = 'BIDID';
	const DB_TABLE = 'bid';


	public function __construct(array $params)
	{
		global $db;

		if (isset($params['filename']))
		{
			$this->construct_by_column('filename', $params['filename']);
			return;
		}
		parent::__construct($params);
	}

	public function add(array $params): bool
	{
		$params['date_created'] = strftime('%F %T');
		return parent::add($params);
	}

	public function getLink(): string
	{
		return '/files/bids/'.basename($this['filename']);
	}

	public function getExifTitle(): string
	{
		return 'Piscataway, New Jersey bid request '.str_replace('.pdf', '', $this['file']);
	}

	public static function getAll()
	{
		global $db;
		$res = $db->Execute('
			select *
			from bid
			order by date_created DESC
		');
		$bids = [];
		foreach ($res as $cur)
		{
			$bids[] = new Bid(['record' => $cur]);
		}

		return $bids;
	}
}
