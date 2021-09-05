<?php
require(__DIR__.'/init.php');

use Spatie\TwitterStreamingApi\PublicStream;

define('PISCATAWAY_UID', '1596769944');

$db->debug = true;
PublicStream::create(
    TWITTER_BEARER,
    TWITTER_API_KEY,
    TWITTER_API_SECRET
)
//->whenHears('test', function (array $tweet) {
->whenTweets(PISCATAWAY_UID, function(array $tweet) {
	/**
	array(2) {
  ["data"]=>
  array(2) {
    ["id"]=>
    string(19) "1433203501878947848"
    ["text"]=>
    string(69) "RT @slvppy: real mfs choose C if they don't know the answer on a test"
  }
  ["matching_rules"]=>
*/
	var_dump($tweet);
	$url = 'https://twitter.com/PWAYNJ/status/'.$tweet['data']['id'];
	$archive_url = 'http://web.archive.org/save/'.$archive_url;
	system('/usr/bin/wget '.escapeshellarg($url).' -O /dev/null > /dev/null 2>&1');

	$c = curl_init('https://publish.twitter.com/oembed?url='.$url);
	curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	$data = json_decode(curl_exec($c), true);

	// Reconnect to avoid mysql has gone away errors
	$db = null;
	$db = newAdoConnection('mysqli');
	$db->connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
	$tw = new Tweet();
	$tw->add([
		'TWEETID'     => $tweet['data']['id'],
		'date'        => strftime('%F %T'),
		'content'     => $tweet['data']['text'],
		'archive_url' => 'https://web.archive.org/web/*/'.$url,
		'embed_html'  => $data['html'],
	]);
})->startListening();
