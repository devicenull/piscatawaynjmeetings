<?php
class Tweet extends BaseDBObject
{
	var $fields = [
		'TWEETID',
		'TWITTERUID',
		'date',
		'content',
		'archive_job_id',
		'archive_url',
		'embed_html',
	];

	const DB_KEY = 'TWEETID';
	const DB_TABLE = 'tweet';

	public function add($params): bool
	{
		$twitteruser = new TwitterUser(['TWITTERUID' => $params['TWITTERUID']]);

		$url = 'https://twitter.com/'.$twitteruser['username'].'/status/'.$params['TWEETID'];
		$job_id = ArchiveOrg::archiveURL($url);
		$params['archive_job_id'] = $job_id;
		// We'll mangle this later when the archive job is done
		$params['archive_url'] = $url;

		$c = curl_init('https://publish.twitter.com/oembed?url='.$url);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
		$data = json_decode(curl_exec($c), true);
		$params['embed_html'] = $data['html'];

		return parent::add($params);
	}

	/**
	*	Retrieve a list of tweets we're waiting for archive.org to process
	*/
	public static function getPendingArchive()
	{
		global $db;
		$res = $db->Execute('
			select *
			from tweet
			where archive_job_id != ""
			order by date DESC
		');
		$tweets = [];
		foreach ($res as $cur)
		{
			$tweets[] = new Tweet(['record' => $cur]);
		}

		return $tweets;
	}

	public static function getAll($include_hidden_users=true)
	{
		global $db;
		$extra_sql = '';
		if (!$include_hidden_users)
		{
			$extra_sql = 'where hidden="no"';
		}
		$res = $db->Execute('
			select *
			from tweet
			left join twitter_user using (TWITTERUID)
			'.$extra_sql.'
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
