<?php
require(__DIR__.'/../init.php');

use Spatie\TwitterStreamingApi\PublicStream;

/**
*	Start by backfilling any tweets we missed since last run (up to 100, didn't bother with paging)...
* 100 is around a weeks worth of tweets anyway
*/
$max_known_id_by_user = [];
$res = $db->Execute('select max(TWEETID) as TWEETID, TWITTERUID from tweet group by TWITTERUID');
foreach ($res as $cur)
{
	$max_known_id_by_user[$cur['TWITTERUID']] = $cur['TWEETID'];
}

foreach (TwitterUser::getAll() as $user)
{
	if (isset($max_known_id_by_user[$user['TWITTERUID']]))
	{
		$max_id = '&since_id='.$max_known_id_by_user[$user['TWITTERUID']];
	}
	else
	{
		echo "Not backfilling tweets for new user {$user['username']}\n";
		continue;
	}
	echo "Current user: {$user['username']}, max id {$max_id}\n";

	$c = curl_init('https://api.twitter.com/2/users/'.$user['TWITTERUID'].'/tweets?tweet.fields=created_at'.$max_id.'&max_results=100');
	curl_setopt_array($c, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER     => [
			'Authorization: Bearer '.TWITTER_BEARER,
		],
	]);
	$result = curl_exec($c);
	if (curl_getinfo($c, CURLINFO_HTTP_CODE) != 200)
	{
		echo "Unable to get old tweets for {$user['TWITTERUID']}: ".$result."\n";
		continue;
	}
	$data = json_decode($result, true);
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
				'TWITTERUID'  => $user['TWITTERUID'],
				'date'        => strftime('%F %T', strtotime($tweet['created_at'])),
				'content'     => $tweet['text'],
			]);
		}
	}
}

$stream = PublicStream::create(
		TWITTER_BEARER,
		TWITTER_API_KEY,
		TWITTER_API_SECRET
);
foreach (TwitterUser::getAll() as $user)
{
	echo "Listening for tweets from {$user['username']}\n";
	$stream->whenTweets($user['TWITTERUID'], 'handleTweet');
}
//->whenHears('test', function (array $tweet) {
$stream->startListening();

/**
 * tweet is:
	array(2) {
	["data"]=>
	array(2) {
		["id"]=>
		string(19) "1433203501878947848"
		["text"]=>
		string(69) "RT @slvppy: real mfs choose C if they don't know the answer on a test"
	}
	["matching_rules"]=>
	array(1) {
		[0]=>
		array(2) {
			["id"]=>
				string(19) "1449032510499958789"
			["tag"]=>
				string(15) "from:1596769944"
			}
		}
	}
*/
function handleTweet($tweet)
{
	var_dump($tweet);

	$uid = explode(':', $tweet['matching_rules'][0]['tag'])[1];

	// Reconnect to avoid mysql has gone away errors
	$GLOBALS['db'] = newAdoConnection('mysqli');
	$GLOBALS['db']->connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
	$tw = new Tweet();
	$tw->add([
		'TWEETID'     => $tweet['data']['id'],
		'TWITTERUID'  => $uid,
		'date'        => strftime('%F %T'),
		'content'     => $tweet['data']['text'],
	]);
}
