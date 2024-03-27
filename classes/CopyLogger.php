<?php
class CopyLogger extends BaseDBObject
{
	var $fields = [
		'COPYID',
		'source_ip',
		'copied_at',
		'copy_text',
	];

	const DB_KEY = 'COPYID';
	const DB_TABLE = 'textcopy';
}
