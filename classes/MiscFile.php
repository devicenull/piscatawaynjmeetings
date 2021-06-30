<?php
class MiscFile extends BaseDBObject
{
	var $fields = [
		'FILEID',
		'type',
		'date',
		'extension',
		'notes',
	];

	var $db_key = 'FILEID';
	var $db_table = 'misc_files';
	var $virtual_fields = ['type_description'];

	const TYPE_DESCRIPTION = [
		'budget'               => 'Budget',
		'audits'               => 'Audit',
		'debt_statements'      => 'Debt Statement',
		'financial_statements' => 'Financial Statement',
	];

	public function __construct($params=[])
	{
		global $db;
		if (isset($params['type']) && isset($params['date']))
		{
			$res = $db->Execute('select * from misc_files where type=? and date=?', [$params['type'], $params['date']]);
			if ($res->RecordCount() == 1)
			{
				$this->record = $res->fields;
				return;
			}
		}
		parent::__construct($params);
	}

	public function get($offset)
	{
		if ($offset == 'type_description')
		{
			return self::TYPE_DESCRIPTION[$this['type']];
		}
		return parent::get($offset);
	}


	public static function getAll()
	{
		global $db;
		$res = $db->Execute('
			select *
			from misc_files
			order by date DESC, type
		');
		$meetings = [];
		foreach ($res as $cur)
		{
			$meetings[] = new MiscFile(['record' => $cur]);
		}

		return $meetings;
	}
}
