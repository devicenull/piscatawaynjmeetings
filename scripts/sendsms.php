<?php
// send me sms reminders when a meeting is happening soon
require_once(__DIR__.'/../init.php');

ini_set('date.timezone', 'America/New_York');

$startdate = strftime('%F %T');
$enddate = strftime('%F %T', time() + (16 * 60));

$res = $db->Execute('
	select *
	from meeting
	where date between ? and ?
', [
	$startdate,
	$enddate,
]);
foreach ($res as $meeting)
{
	$voipms = new VoIPms();
	$voipms->api_username = VOIPMS_LOGIN;
	$voipms->api_password = VOIPMS_PASSWORD;

	$time = explode(' ', $meeting['date'])[1];

	$response = $voipms->sendSMS(SMS_FROM, SMS_TO, $meeting['type'].' meeting starts at '.$time);
}
