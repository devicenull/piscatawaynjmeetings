<?php
class Newsletter extends BaseDBObject
{
	var $fields = [
		'NEWSLETTERID',
		'date',
	];

	var $db_key = 'MEETINGID';
	var $db_table = 'meeting';

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
