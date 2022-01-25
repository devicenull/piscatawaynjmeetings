<?php
require(__DIR__.'/../init.php');

use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\DateTime;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\ValueObject\UniqueIdentifier;
use Eluceo\iCal\Domain\Entity\TimeZone;
use DateTimeZone as PhpDateTimeZone;

define('MYSQL_DATE_FORMAT', 'Y-m-d H:i:s');
$timezone = new DateTimeZone('America/New_York');

$events = [];
foreach (Meeting::getFutureAndToday() as $cur)
{
	// FIXME: timezones are still wrong
	$start = new DateTime(DateTimeImmutable::createFromFormat(MYSQL_DATE_FORMAT, $cur['date'], $timezone), false);
	$end = new DateTime(DateTimeImmutable::createFromFormat(MYSQL_DATE_FORMAT, strftime('%F %T', strtotime($cur['date']) + (3 * 60 * 60)), $timezone), false);

	$uid = new UniqueIdentifier('piscatawaynjmeetings.com/meeting/'.$cur['MEETINGID']);
	$event = new Event($uid);

	$event->setOccurrence(new TimeSpan($start, $end));
	$event->setSummary('PNJ ' .ucfirst($cur['type']).' Meeting');

	if ($cur['zoom_id'] != 0)
	{
		$event->setDescription('Join via Zoom: <a href="zoomus://zoom.us/join?confno='.urlencode($cur['zoom_id']).'&pwd='.sprintf('%06s', $cur['zoom_password']).'">Join (iOS/Android)</a><br>');
	}

	$events[] = $event;
}

$calendar = new \Eluceo\iCal\Domain\Entity\Calendar($events);
$calendar->addTimeZone(TimeZone::createFromPhpDateTimeZone(new PhpDateTimeZone('America/New_York')));

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="piscatawaymeetings.ics"');
echo (new \Eluceo\iCal\Presentation\Factory\CalendarFactory())->createCalendar($calendar);
