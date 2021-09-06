<?php
require(__DIR__.'/init.php');

use Spatie\TwitterStreamingApi\PublicStream;

/**
*	Start by backfilling any tweets we missed since last run (up to 100, didn't bother with paging)...
* 100 is around a weeks worth of tweets anyway
*/
$res = $db->Execute('select max(TWEETID) from tweet');
$max_known_id = $res->fields['max(TWEETID)'];

$c = curl_init('https://api.twitter.com/2/users/'.PISCATAWAY_UID.'/tweets?tweet.fields=created_at&since_id='.$max_known_id.'&max_results=100');
curl_setopt_array($c, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_HTTPHEADER     => [
		'Authorization: Bearer '.TWITTER_BEARER,
	],
]);

$data = json_decode(curl_exec($c), true);
if (!isset($data['data']))
{
	echo "No old tweets found\n";
}
else
{
	foreach ($data['data'] as $tweet)
	{
		echo $tweet['id']."\n";
		$tw = new Tweet();
		$tw->add([
			'TWEETID'     => $tweet['id'],
			'date'        => strftime('%F %T', strtotime($tweet['created_at'])),
			'content'     => $tweet['text'],
		]);
	}
}

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

	// Reconnect to avoid mysql has gone away errors
	$GLOBALS['db'] = newAdoConnection('mysqli');
	$GLOBALS['db']->connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
	$tw = new Tweet();
	$tw->add([
		'TWEETID'     => $tweet['data']['id'],
		'date'        => strftime('%F %T'),
		'content'     => $tweet['data']['text'],
	]);
})->startListening();
