<?php
require(__DIR__.'/../init.php');

if (isset($_POST['copy_text']))
{
	$copy = new CopyLogger();
	$copy->add([
		'source_ip' => $_SERVER['REMOTE_ADDR'],
		'copied_at' => strftime('%F %T'),
		'copy_text' => $_POST['copy_text'],
	]);
}
