<?php
class YouTube extends BaseDBObject
{
	var $fields = [
		'video_id',
		'title',
		'published',
		'filename',
	];

	const DB_KEY = 'video_id';
	const DB_TABLE = 'youtube';

	public function getLink(): string
	{
		return 'https://www.youtube.com/watch?v='.$this['video_id'];
	}

	public function getFileLocation(): string
	{
		$videodate = explode(' ', $this['published'])[0];
		return 'files/youtube/'.$videodate.'/'.$this['filename'];
	}

	public static function getAll()
	{
		global $db;
		$res = $db->Execute('
			select *
			from youtube
			order by published desc
		');
		$videos = [];
		foreach ($res as $cur)
		{
			$videos[] = new YouTube(['record' => $cur]);
		}

		return $videos;
	}
}
