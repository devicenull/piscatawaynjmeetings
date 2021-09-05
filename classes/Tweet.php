<?php
class Tweet extends BaseDBObject
{
	var $fields = [
		'TWEETID',
		'date',
		'content',
		'archive_url',
		'embed_html',
	];

	var $db_key = 'TWEETID';
	var $db_table = 'tweet';

	public static function getAll()
	{
		global $db;
		$res = $db->Execute('
			select *
			from tweet
			order by date DESC
		');
		$tweets = [];
		foreach ($res as $cur)
		{
			$tweets[] = new Tweet(['record' => $cur]);
		}

		return $tweets;
	}
}
