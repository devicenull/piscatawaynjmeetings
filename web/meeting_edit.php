<?php
require(__DIR__.'/../init.php');

if (!hasEditAuth())
{
	die('Access Denied');
}

if (!isset($_REQUEST['MEETINGID']) || $_REQUEST['MEETINGID'] == 'new')
{
	$MEETINGID = 'new';
	$meeting = new Meeting();
}
else
{
	$MEETINGID = $_REQUEST['MEETINGID'];
	$meeting = new Meeting(['MEETINGID' => $_REQUEST['MEETINGID']]);
}

if (isset($_POST['action']) && $_POST['action'] == 'update_meeting')
{
	$params = [];
	foreach ($meeting->fields as $key)
	{
		if (isset($_POST[$key]) && in_array($key, ['zoom_id', 'zoom_password']))
		{
			// these are usually provided with spaces, which will break storing in mysql
			$params[$key] = trim(str_replace(' ', '', $_POST[$key]));
		}
		else if (isset($_POST[$key]) && $key != 'MEETINGID')
		{
			$params[$key] = $_POST[$key];
		}
	}

	if ($MEETINGID == 'new')
	{
		$params['minutes_available'] = 'no';
		$params['recording_available'] = 'no';
		if ($meeting->add($params))
		{
			displaySuccess('Meeting Added', '/');
		}
		else
		{
			displayError('Unable to add meeting: '.$meeting->error);
		}
	}
	else
	{
		if ($meeting->set($params))
		{
			displaySuccess('Meeting Updated', '/');
		}
		else
		{
			displayError('Unable to update meeting: '.$meeting->error);
		}
	}
}

$vars = [
	'MEETINGID' => $MEETINGID,
	'meeting'   => $meeting,
];
displayPage('meeting_edit.html', $vars);
