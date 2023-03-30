<?php
// could do this in nginx but... effort
if (isset($_GET['MEETINGID']) && intval($_GET['MEETINGID']) > 0)
{
	header('HTTP/1.1 301 Moved Permanently');
	Header('Location: /transcript.php?MEETINGID='.intval($_GET['MEETINGID']));
}
