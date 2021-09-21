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
	];

	var $db_key = 'MEETINGID';
	var $db_table = 'meeting';

	// link_type minutes|recording
	public function getLink($link_type): string
	{
		$extensions = [
			'minutes' => ['doc', 'pdf'],
			'recording' => ['mp3', 'm4a'],
		];

		$date = explode(' ', $this['date']);

		$basepath = '/files/'.$this['type'].'/'.$date[0].'.';
		foreach ($extensions[$link_type] as $ext)
		{
			if (file_exists(__DIR__.'/../web/'.$basepath.$ext))
			{
				return $basepath.$ext;
			}
		}

		return '';
	}

	public static function getAll()
	{
		global $db;
		$res = $db->Execute('
			select *
			from meeting
			order by date ASC, type
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

	public static function getRecent()
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

	public static function getFutureAndToday()
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
}
