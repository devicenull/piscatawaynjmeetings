<?php
require_once(__DIR__.'/../init.php');

/**
*	Import files into the relevant object types
*/
foreach (getDirContents(__DIR__.'/../web/files') as $cur)
{
	$cleanpath = str_replace(__DIR__.'/../web', '', $cur);

	// these are imported elsewhere
	if (stripos($cleanpath, 'piscataway_youtube') !== false)
	{
		continue;
	}

	$fileinfo = explode('/', $cleanpath);
	$details = pathinfo($cleanpath);
	// campaign files are currently stored in year subdirectories,
	// make sure they get handled properly
	// ex: /home/piscataway/web/files/campaign/2021/PDO Report 2021-04-14 R-3.pdf
	if (count($fileinfo) == 8)
	{
		$parentdirectory = basename($fileinfo[5]);
	}
	else
	{
		$parentdirectory = basename($details['dirname']);
	}

	$filebasename = $details['filename'];
	$extension = $details['extension'];

	switch ($parentdirectory)
	{
		case 'newsletter':
			$newsletter = new Newsletter(['filename' => basename($cleanpath)]);
			if (!$newsletter->isInitialized())
			{
				$newsletter->add([
					'filename' => basename($cleanpath),
				]);
			}
		break;

		case 'bids':
			$bid = new Bid(['filename' => basename($cleanpath)]);
			if (!$bid->isInitialized())
			{
				$bid->add([
					'filename' => basename($cleanpath),
				]);
			}
		break;

		case 'council':
		case 'planning':
		case 'zoning':
		case 're-warding':
		case 'ems':
			if (!preg_match_all('/^[0-9]+\\-[0-9]+\\-[0-9]+$/i', $filebasename))
			{
				echo "Invalid file format: $cleanpath\n";
				continue 2;
			}

			$meeting = new Meeting([
				'type' => $parentdirectory,
				'date' => $filebasename,
			]);
			if (!$meeting->isInitialized())
			{
				if (!$meeting->add([
					'type' => $parentdirectory,
					'date' => $filebasename,
				]))
				{
					echo "Unable to add meeting: ".$meeting->error()."\n";
					continue 2;
				}
				else
				{
					echo "auto-added ".$meeting['MEETINGID'];

					$meeting = new Meeting(['MEETINGID' => $meeting]);
				}
			}

			if (!$meeting->hasHappened())
			{
				continue 2;
			}

			echo $meeting['type']."\t".$meeting['date']."\t";

			$link = $meeting->getLink('minutes');
			if ($link != '')
			{
				$meeting->set(['minutes_available' => 'yes', 'minutes_filetype' => pathinfo(__DIR__.'/web/'.$link, PATHINFO_EXTENSION)]);
				echo "minutes: yes\t";
			}
			else
			{
				echo "minutes: no\t";
				$meeting->set(['minutes_available' => 'no']);
			}

			$link = $meeting->getLink('recording');
			if ($link != '')
			{
				$meeting->set(['recording_available' => 'yes', 'recording_filetype' => pathinfo($link, PATHINFO_EXTENSION)]);
				echo "recording: yes\n";
				$known_files[] = $link;
			}
			else
			{
				echo "recording: no\n";
				$meeting->set(['recording_filetype' => 'no']);
			}
		break;

		case 'campaign':
			if (count($fileinfo) == 7)
			{
				// these are some scratch files
				continue 2;
			}
			$GLOBALS['db']->debug = true;
			$campaignfile = new CampaignFile([
				'type'     => 'finance_statement',
				'filename' => $filebasename.'.'.$extension,
			]);

			if (!$campaignfile->isInitialized())
			{
				$campaignfile->add([
					'type'     => 'finance_statement',
					'year'     => basename($fileinfo[6]),
					'filename' => $filebasename.'.'.$extension,
				]);
			}
		break;

		default:
			if (in_array($parentdirectory, array_keys(MiscFile::TYPE_DESCRIPTION)))
			{
				if (!preg_match_all('/^[0-9]+\\-[0-9]+\\-[0-9]+$/i', $filebasename))
				{
					echo "Invalid file format: $cleanpath\n";
					continue 2;
				}

				$miscfile = new MiscFile([
					'type' => $fileinfo[2],
					'date' => $filebasename,
				]);

				if (!$miscfile->isInitialized())
				{
					$miscfile->add([
						'type'      => $fileinfo[2],
						'date'      => $filebasename,
						'extension' => $extension,
					]);
				}

				continue 2;
			}
			else
			{
				echo "Unknown file type: ".$parentdirectory."\n";
			}
		break;
	}
}

echo "done\n";

function getDirContents($dir, &$results = array())
{
    $files = scandir($dir);

    foreach ($files as $value)
    {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
        if (!is_dir($path))
        {
            $results[] = $path;
        }
        else if ($value != "." && $value != "..")
        {
            getDirContents($path, $results);
        }
    }

    return $results;
}
