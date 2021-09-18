<?php
class Newsletter extends BaseDBObject
{
	var $fields = [
		'NEWSLETTERID',
		'date',
	];

	var $db_key = 'NEWSLETTER';
	var $db_table = 'newsletter';

	public function __construct(array $params)
	{
		if (isset($params['date']))
		{
			$this->db_key = 'date';
			parent::__construct($params);
			$this->db_key = 'BIDID';
			return;
		}
		parent::__construct($params);
	}

	public static function getAll()
	{
		global $db;
		$res = $db->Execute('
			select *
			from newsletter
			order by date DESC
		');
		$meetings = [];
		foreach ($res as $cur)
		{
			$meetings[] = new Newsletter(['record' => $cur]);
		}

		return $meetings;
	}
}
