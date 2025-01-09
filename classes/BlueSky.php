<?php
class BlueSky {
	private $curl = null;
	private $api_key = '';
	private $did = '';

	public function __construct()
	{
		$this->curl = curl_init('https://bsky.social/xrpc/com.atproto.server.createSession');
		curl_setopt_array($this->curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
			CURLOPT_POSTFIELDS => json_encode([
				'identifier' => BLUESKY_USERNAME,
				'password'   => BLUESKY_PASSWORD,
			]),
		]);
		$result = json_decode(curl_exec($this->curl), true);
		if (!isset($result['accessJwt']))
		{
			error_log('Unable to trade creds for a JWT: '.json_encode($result));
		}
		else
		{
			$this->api_key = $result['accessJwt'];
			$this->did = $result['did'];
		}
	}

	public function post($message, $facets=[])
	{
		$date = new \DateTime();
		curl_setopt_array($this->curl, [
			CURLOPT_URL => 'https://bsky.social/xrpc/com.atproto.repo.createRecord',
			CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer '.$this->api_key],
			CURLOPT_POSTFIELDS => json_encode([
				'collection' => 'app.bsky.feed.post',
				'repo' => $this->did,
				'record' => [
					'text'      => $message,
					'facets'    => $facets,
					'type'      => 'app.bsky.feed.post',
					'createdAt' => $date->format(\DateTime::RFC3339),
				],
			]),
		]);
		$result = json_decode(curl_exec($this->curl), true);
		var_dump($result);
	}
}

