<?php
class TwitterUser extends BaseDBObject
{
	var $fields = [
		'TWITTERUID',
		'username',
	];

	const DB_KEY = 'TWITTERUID';
	const DB_TABLE = 'twitter_user';

	public static function getAll()
	{
		global $db;
		$res = $db->Execute('
			select *
			from twitter_user
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
