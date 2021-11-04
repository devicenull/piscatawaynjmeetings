<?php
class TwitterUser extends BaseDBObject
{
	var $fields = [
		'TWITTERUID',
		'username',
		'hidden', // yes|no
	];

	const DB_KEY = 'TWITTERUID';
	const DB_TABLE = 'twitter_user';

	public static function getAll($include_hidden=true)
	{
		global $db;
		$extra_sql = '';
		if (!$include_hidden)
		{
			$extra_sql = 'where hidden="no"';
		}
		$res = $db->Execute('
			select *
			from twitter_user
			'.$extra_sql.'
			order by username DESC
		');
		$tweets = [];
		foreach ($res as $cur)
		{
			$tweets[] = new TwitterUser(['record' => $cur]);
		}

		return $tweets;
	}
}
