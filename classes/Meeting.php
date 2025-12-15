<?php
class Meeting extends BaseDBObject
{
	var $fields = [
		'MEETINGID',
		'type',
		'date',
		'minutes_available',
		'minutes_filetype',
		'recording_available',
		'recording_filetype',
		'zoom_id',
		'zoom_password',
		'zoom_joinurl',
		'revai_jobid',
		'transcript_available',
		'last_updated',
		'bluesky_posts',
	];

	var $virtual_fields = [
		'board_type',
	];

	const DB_KEY = 'MEETINGID';
	const DB_TABLE = 'meeting';

	public function __construct($params=[])
	{
		if (isset($params['type']) && isset($params['date']))
		{
			global $db;
			$res = $db->Execute('
				select *
				from meeting
				where type=? and date between ? and ?',
				[
					$params['type'],
					strftime('%F 00:00:00', strtotime($params['date'])),
					strftime('%F 23:59:59', strtotime($params['date'])),
				]
			);
			if ($res->RecordCount() == 1)
			{
				$this->record = $res->fields;
				return;
			}
		}
		parent::__construct($params);
	}

	// link_type minutes|recording|transcript
	// destination true|false - if true, return the path where this file *should* go
	public function getLink($link_type, $destination=false): string
	{
		$extensions = [
			'minutes' => ['doc', 'pdf', 'docx'],
			'recording' => ['mp3', 'm4a'],
			'transcript' => ['txt'],
		];

		if ($destination && count($extensions[$link_type]) > 1)
		{
			return '';
		}

		$date = explode(' ', $this['date']);

		$basepath = '/files/'.$this['type'].'/'.$date[0].'.';

		/**
		*	Transcripts are small enough to store locally, everything else is not
		*/
		if (!$destination && $link_type != 'transcript' && $this[$link_type.'_filetype'] != '')
		{
			return $basepath.$this[$link_type.'_filetype'];
		}
		else
		{
			// this is dumb... why did I do it this way?
			foreach ($extensions[$link_type] as $ext)
			{
				if (file_exists(__DIR__.'/../web/'.$basepath.$ext) || $destination)
				{
					return $basepath.$ext;
				}
			}
		}

		return '';
	}

	public function getPublicLink($link_type) {
		if (hasEditAuth() && false) {
			return $this->getLink($link_type);
		}

		$baselink = str_replace('/files/', '', $this->getLink($link_type));
		return 'https://files.piscatawaynjmeetings.com/' . $baselink;
	}

	public function getExifTitle(string $type): string
	{
		return 'Piscataway, New Jersey '.$this['type'].' meeting '.$type.' for '.explode(' ', $this['date'])[0];
	}

	public function getTranscript(): string
	{
		$transcript = file_get_contents(__DIR__.'/../web/'.$this->getLink('transcript'));
		$output = '';
		foreach (explode("\n", $transcript) as $line)
		{
			if (preg_match('/(Speaker [0-9]+\s+)([0-9\:]+)(\s+)(.*)$/', $line, $matches))
			{
				sscanf($matches[2], '%d:%d:%d', $hours, $minutes, $seconds);
				$timestamp = ($hours * 60 * 60)+($minutes * 60)+$seconds;
				$output .= str_pad(trim($matches[1]), 14).'<a href="javascript:changePlayerTime('.$timestamp.');">'.$matches[2].'</a>'.$matches[3].$matches[4]."\n";
			}
			else
			{
				$output .= htmlspecialchars($line)."\n";
			}
		}
		return $output;
	}

	public static function getUpcomingAndOlder()
	{
		global $db;
		$res = $db->Execute('
			select *
			from meeting
			where date < ?
			order by date ASC, type
		', [strftime('%F %T', strtotime('+30 days'))]
		);
		$meetings = [];
		foreach ($res as $cur)
		{
			$meetings[] = new Meeting(['record' => $cur]);
		}

		return $meetings;
	}

	public static function getAll()
	{
		global $db;
		$res = $db->Execute('
			select *
			from meeting
			order by date desc, type
		');
		$meetings = [];
		foreach ($res as $cur)
		{
			$meetings[] = new Meeting(['record' => $cur]);
		}

		return $meetings;
	}

	public function hasHappened(): bool
	{
		return strtotime($this['date']) < time();
	}

	public function happensToday(): bool
	{
		$date = explode(' ', $this['date'])[0];
		return $date == strftime('%F');
	}

	public function add(array $params): bool
	{
		$params['last_updated'] = strftime('%F %T');
		return parent::add($params);
	}

	public function set(array $params): bool
	{
		$params['last_updated'] = strftime('%F %T');
		return parent::set($params);
	}

	public function get($key)
	{
		if ($key == 'board_type')
		{
			$descriptions = [
				'zoning'     => 'Zoning Board',
				'planning'   => 'Planning Board',
				'council'    => 'Township Council',
				're-warding' => 'Re-warding Commission',
				'ems'        => 'EMS Advisory Council',
				'library'    => 'Library',
			];

			return $descriptions[$this['type']] ?? $this['type'];
		}

		return parent::get($key);
	}

	public static function getRecent(): iterable
	{
		global $db;
		$res = $db->Execute('
			select *
			from meeting
			where date between date_sub(now(), interval 14 day) and date_add(now(), interval 14 day)
			order by date ASC, type
		');
		$meetings = [];
		foreach ($res as $cur)
		{
			$meetings[] = new Meeting(['record' => $cur]);
		}

		return $meetings;
	}

	public static function getFutureAndToday(): iterable
	{
		global $db;
		$res = $db->Execute('
			select *
			from meeting
			where date >= date_sub(now(), interval 1 day)
			order by date ASC, type
		');
		$meetings = [];
		foreach ($res as $cur)
		{
			$meetings[] = new Meeting(['record' => $cur]);
		}

		return $meetings;
	}

	public static function getTypes(): iterable
	{
		global $db;
		$res = $db->Execute('
			select distinct(type) as type
			from meeting
		');
		$types = [];
		foreach ($res as $cur)
		{
			$types[] = $cur['type'];
		}
		return $types;
	}

	public static function getUntranscribed()
	{
		global $db;
		$res = $db->Execute('
			select *
			from meeting
			where revai_jobid = "" and recording_available = "yes"
			order by date desc, type
		');
		$meetings = [];
		foreach ($res as $cur)
		{
			$meetings[] = new Meeting(['record' => $cur]);
		}

		return $meetings;
	}
}
