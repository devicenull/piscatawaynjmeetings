<?php
class CampaignFile extends BaseDBObject
{
	var $fields = [
		'CAMPAIGNFILEID',
		'type',
		'year', // campaign year - not the same year as the file was submitted
		'filename',
	];

	const DB_KEY = 'CAMPAIGNFILEID';
	const DB_TABLE = 'campaign_files';
	var $virtual_fields = [
		'type_description',
		'file_path',
	];

	const TYPE_DESCRIPTION = [
		'finance_statement' => 'Campaign Finance Statements',
	];

	public function __construct($params=[])
	{
		global $db;
		if (isset($params['type']) && isset($params['filename']))
		{
			$res = $db->Execute('select * from campaign_files where type=? and filename=?', [$params['type'], $params['filename']]);
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
		else if ($offset == 'file_path')
		{
			return '/files/campaign/'.$this['year'].'/'.$this['filename'];
		}
		return parent::get($offset);
	}

	public static function getAll()
	{
		global $db;
		$res = $db->Execute('
			select *
			from campaign_files
			order by year DESC, type
		');
		$meetings = [];
		foreach ($res as $cur)
		{
			$meetings[] = new CampaignFile(['record' => $cur]);
		}

		return $meetings;
	}
}
