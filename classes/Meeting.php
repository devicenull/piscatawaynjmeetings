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
		'joining_information',
	];

	var $db_key = 'MEETINGID';
	var $db_table = 'meeting';

	public function getLink(string $link_type): string
	{
		if ($this[$link_type.'_available'] == 'no') return '';

		$date = explode(' ', $this['date'])[0];

		return 'files/'.$this['type'].'/'.$date.'.'.$this[$link_type.'_filetype'];
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
}
