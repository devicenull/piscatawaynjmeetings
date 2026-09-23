<?php
class RedevelopmentStudyLot extends BaseDBObject
{
	var $fields = [
		'LOTID',
		'STUDYID',
		'block',
		'lot',
		'street_address',
	];

	const DB_KEY = 'LOTID';
	const DB_TABLE = 'redevelopment_study_lot';

	public static function getByStudy(int $studyid): array
	{
		global $db;
		$res = $db->Execute('select * from redevelopment_study_lot where STUDYID=? order by block, lot', [$studyid]);
		$lots = [];
		foreach ($res as $cur)
		{
			$lots[] = new RedevelopmentStudyLot(['record' => $cur]);
		}
		return $lots;
	}
}
