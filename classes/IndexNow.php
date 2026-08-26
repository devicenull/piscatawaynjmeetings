<?php
class IndexNow
{
	const KEY = '9779292fbce84e328dc3c6b38afe7d50';
	const HOST = 'piscatawaynjmeetings.com';

	public static function submitURL(string $url): bool
	{
		return self::submitURLs([$url]);
	}

	public static function submitURLs(array $urls): bool
	{
		$urls = array_values(array_filter($urls));
		if (empty($urls))
		{
			return true;
		}

		$c = curl_init('https://api.indexnow.org/indexnow');
		curl_setopt_array($c, [
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
			CURLOPT_POSTFIELDS     => json_encode([
				'host'        => self::HOST,
				'key'         => self::KEY,
				'keyLocation' => 'https://'.self::HOST.'/'.self::KEY.'.txt',
				'urlList'     => $urls,
			]),
		]);

		$result = curl_exec($c);
		$code = curl_getinfo($c, CURLINFO_HTTP_CODE);
		if ($code != 200 && $code != 202)
		{
			error_log('IndexNow submission failed ('.$code.'): '.$result);
			return false;
		}
		return true;
	}
}
