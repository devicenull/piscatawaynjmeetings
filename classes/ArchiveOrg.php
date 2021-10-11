<?php
class ArchiveOrg
{
	/**
	*	Returns an archive.org job status ID
	*/
	public static function archiveURL(string $url): ?string
	{
		for ($tries=0; $tries<5;$tries++)
		{
			$c = self::initCurl();
			curl_setopt_array($c, [
				CURLOPT_POSTFIELDS => [
					'url'                => $url,
				],
			]);

			$result = curl_exec($c);
			if (curl_getinfo($c, CURLINFO_HTTP_CODE) == 200)
			{
				$data = json_decode($result, true);
				return $data['job_id'];
			}
			else
			{
				echo "Curl request failed\n";
				var_dump(curl_getinfo($c));
				sleep(10);
				continue;
			}
		}
		return null;
	}

	/**
	*	Returns true if archive.org has successfully processed this job
	*/
	public static function isComplete(string $job_id, string &$timestamp): bool
	{
		$c = self::initCurl();
		curl_setopt_array($c, [
			CURLOPT_URL => 'https://web.archive.org/save/status/'.$job_id,
		]);

		$result = curl_exec($c);
		if (curl_getinfo($c, CURLINFO_HTTP_CODE) == 200)
		{
			$data = json_decode($result, true);

			if ($data['status'] == 'success')
			{
				$timestamp = $data['timestamp'];
				return true;
			}
			return false;
		}
		else
		{
			echo "Curl request failed\n";
			var_dump(curl_getinfo($c));
			return false;
		}
	}

	private static function initCurl()
	{
		$c = curl_init('https://web.archive.org/save?email_result=0&skip_first_archive=1');
		curl_setopt_array($c, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER      => [
				'authorization: LOW '.ARCHIVE_ACCESS.':'.ARCHIVE_SECRET,
				'Accept: application/json',
			],
		]);
		return $c;
	}
}
