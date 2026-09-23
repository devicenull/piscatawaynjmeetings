<?php
class RedevelopmentStudy extends BaseDBObject
{
	var $fields = [
		'STUDYID',
		'date',
		'filename',
	];

	var $virtual_fields = ['lots', 'label'];

	const DB_KEY = 'STUDYID';
	const DB_TABLE = 'redevelopment_study';

	public function __construct($params=[])
	{
		if (isset($params['filename']))
		{
			$this->construct_by_column('filename', $params['filename']);
			return;
		}
		parent::__construct($params);
	}

	public function get($offset)
	{
		if ($offset == 'lots')
		{
			return RedevelopmentStudyLot::getByStudy($this['STUDYID']);
		}
		if ($offset == 'label')
		{
			return stripos($this['filename'], 'addendum') !== false ? 'Addendum' : 'Study';
		}
		return parent::get($offset);
	}

	public function getLink(): string
	{
		return '/files/redevelopment/'.basename($this['filename']);
	}

	public function getExifTitle(): string
	{
		return 'Piscataway, New Jersey area in need of redevelopment study';
	}

	public static function getAll(): array
	{
		global $db;
		$res = $db->Execute('
			select *
			from redevelopment_study
			order by date DESC
		');
		$studies = [];
		foreach ($res as $cur)
		{
			$studies[] = new RedevelopmentStudy(['record' => $cur]);
		}

		return $studies;
	}
}
