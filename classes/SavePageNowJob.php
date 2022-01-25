<?php
class SavePageNowJob extends BaseDBObject
{
	var $fields = [
		'SPNID',			// uuid (36 characters)
		'date_created',
		'status',
		'url',
		'archive_type',     // page|outlink|tweet
	];

	const DB_KEY = 'SPNID';
	const DB_TABLE = 'save_page_now';

	public function add(array $params): bool
	{
		$params['date_created'] = strftime('%F %T');
		$params['status'] = 'new';
		return parent::add($params);
	}

	/**
	*	Retrieve a list of tweets we're waiting for archive.org to process
	*/
	public static function getPending()
	{
		global $db;
		$res = $db->Execute('
			select *
			from save_page_now
			where status="new" and archive_type="page"
			order by date_created ASC
		');
		$tweets = [];
		foreach ($res as $cur)
		{
			$tweets[] = new SavePageNowJob(['record' => $cur]);
		}

		return $tweets;
	}

	public static function jobExists(string $url): bool
	{
		global $db;
		$res = $db->Execute('select count(*) from save_page_now where url=?', [$url]);
		return $res->fields['count(*)'] > 0;
	}
}
