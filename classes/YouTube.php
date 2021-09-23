<?php
class YouTube extends BaseDBObject
{
	var $fields = [
		'video_id',
		'title',
		'published',
		'filename',
	];

	var $db_key = 'video_id';
	var $db_table = 'youtube';

	public function getLink(): string
	{
		return 'https://www.youtube.com/watch?v='.$this['video_id'];
	}

	public function getFileLocation(): string
	{
		$videodate = explode(' ', $this['published'])[0];
		return 'files/youtube/'.$videodate.'/'.$this['filename'];
	}
}
