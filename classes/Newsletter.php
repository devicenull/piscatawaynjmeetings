<?php
class Newsletter extends BaseDBObject
{
	var $fields = [
		'NEWSLETTERID',
		'filename',
	];

	var $db_key = 'NEWSLETTER';
	var $db_table = 'newsletter';

	public function __construct(array $params)
	{
		if (isset($params['filename']))
		{
			$this->db_key = 'filename';
			parent::__construct($params);
			$this->db_key = 'NEWSLETTERID';
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
			order by filename DESC
		');
		$meetings = [];
		foreach ($res as $cur)
		{
			$meetings[] = new Newsletter(['record' => $cur]);
		}

		return $meetings;
	}
}
