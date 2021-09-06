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

	public function add($params): bool
	{
		$url = 'https://twitter.com/PWAYNJ/status/'.$params['TWEETID'];
		$archive_url = 'http://web.archive.org/save/'.$url;

		// why wget?  it's easier then screwing around with curl_multi, and we don't actually
		// care what archive.org has to say!
		system('/usr/bin/wget '.escapeshellarg($archive_url).' -O /dev/null > /dev/null 2>&1 &');
		$params['archive_url'] = 'https://web.archive.org/web/*/'.$url;

		$c = curl_init('https://publish.twitter.com/oembed?url='.$url);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
		$data = json_decode(curl_exec($c), true);
		$params['embed_html'] = $data['html'];

		return parent::add($params);
	}

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
