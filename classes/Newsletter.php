<?php
class Newsletter extends BaseDBObject
{
	var $fields = [
		'NEWSLETTERID',
		'filename',
		'season',
		'year',
		'notes',
	];

	const DB_KEY = 'NEWSLETTERID';
	const DB_TABLE = 'newsletter';

	public function __construct(array $params)
	{
		if (isset($params['filename']))
		{
			$this->construct_by_column('filename', $params['filename']);
			return;
		}
		parent::__construct($params);
	}

	public function add($params): bool
	{
		$data = explode(' ', str_replace('.pdf', '', $params['filename']));
		if (!in_array($data[0], ['Spring', 'Summer', 'Winter', 'Fall']))
		{
			$this->error[] = 'Invalid Season';
			return false;
		}
		$params['season'] = $data[0];

		if ($data[1] > (date('Y')+1) || $data[1] < 2000)
		{
			$this->error = 'Year is invalid';
			return false;
		}
		$params['year'] = $data[1];
		return parent::add($params);
	}

	public function getLink(): string
	{
		return "/files/newsletter/".$this['filename'];
	}

	public function getExifTitle(): string
	{
		return 'Piscataway, New Jersey '.$this['season'].' '.$this['year'].' Newsletter';
	}

	public static function getAll()
	{
		global $db;
		$res = $db->Execute('
			select *, case season
				when "Spring" then 2
				when "Summer" then 3
				when "Fall" then 4
				when "Winter" then 1
				end as sortorder
			from newsletter
			order by year desc, sortorder desc
		');
		$return = [];
		foreach ($res as $cur)
		{
			$return[] = new Newsletter(['record' => $cur]);
		}

		return $return;
	}
}
