<?php
class Bid extends BaseDBObject
{
	var $fields = [
		'BIDID',
		'date_created',
		'filename',
	];

	var $db_key = 'BIDID';
	var $db_table = 'bid';


	public function __construct(array $params)
	{
		if (isset($params['filename']))
		{
			$this->db_key = 'filename';
			parent::__construct($params);
			$this->db_key = 'BIDID';
			return;
		}
		parent::__construct($params);
	}

	public function add(array $params): bool
	{
		$params['date_created'] = strftime('%F %T');
		return parent::add($params);
	}

	public function getLink($link_type): string
	{
		return '/files/bids/'.basename($this['filename']);
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
