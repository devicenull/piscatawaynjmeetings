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
				echo "Adding newsletter {$cleanpath}\n";
				$newsletter->add([
					'filename' => basename($cleanpath),
				]);
				if ($extension == 'pdf')
				{
					ocrPDF($cur);
				}
				setExifMetadata($cleanpath, $newsletter->getExifTitle());
			}
		break;

		case 'bids':
			$bid = new Bid(['filename' => basename($cleanpath)]);
			if (!$bid->isInitialized())
			{
				echo "Adding bid {$cleanpath}\n";
				$bid->add([
					'filename' => basename($cleanpath),
				]);

				if ($extension == 'pdf')
				{
					ocrPDF($cur);
				}

				setExifMetadata($cleanpath, $bid->getExifTitle());
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

			$link = $meeting->getLink('minutes');
			if ($link != '' && $meeting['minutes_available'] == 'no')
			{
				$meeting->set(['minutes_available' => 'yes', 'minutes_filetype' => pathinfo(__DIR__.'/../web/'.$link, PATHINFO_EXTENSION)]);
				echo $meeting['type']."\t".$meeting['date']."\t"."minutes: yes\t";

				if (str_ends_with($link, '.pdf'))
				{
					ocrPDF(__DIR__.'/../web/'.$link);
				}

				setExifMetadata(__DIR__.'/../web/'.$link, $meeting->getExifTitle('minutes'));
			}

			$link = $meeting->getLink('recording');
			if ($link != '' && $meeting['recording_available'] == 'no')
			{
				$meeting->set(['recording_available' => 'yes', 'recording_filetype' => pathinfo(__DIR__.'/../web/'.$link, PATHINFO_EXTENSION)]);
				echo $meeting['type']."\t".$meeting['date']."\t"."recording: yes\n";
				$known_files[] = $link;
				setMP3Metadata(__DIR__.'/../web/'.$link, $meeting->getExifTitle('recording'));
			}
		break;

		case 'campaign':
			if (count($fileinfo) == 7)
			{
				// these are some scratch files
				continue 2;
			}
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

				if ($extension == 'pdf')
				{
					ocrPDF($cur);
				}
			}
		break;

		default:
			if (in_array($parentdirectory, array_keys(MiscFile::TYPE_DESCRIPTION)))
			{
				if (!preg_match_all('/^[0-9]+\\-[0-9]+\\-[0-9]+$/i', $filebasename))
				{
					echo "Invalid file name format: $cleanpath\n";
					continue 2;
				}

				$miscfile = new MiscFile([
					'type' => $parentdirectory,
					'date' => $filebasename,
				]);

				if (!$miscfile->isInitialized())
				{
					$miscfile->add([
						'type'      => $parentdirectory,
						'date'      => $filebasename,
						'extension' => $extension,
					]);
					if ($extension == 'pdf')
					{
						ocrPDF($cur);
					}
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
function ocrPDF(string $filename)
{
	// most posted documents have no text layer... they're printed and scanned back in.  Fix that
	passthru('ocrmypdf --deskew --clean-final --user-words '.escapeshellarg(DICTIONARY_FILE).' '.escapeshellarg($filename).' /tmp/ocr.pdf', $exit_code);
	if ($exit_code == 0)
	{
		copy('/tmp/ocr.pdf', $filename);
		unlink('/tmp/ocr.pdf');
	}
}
// this is a little slow, only call if necessary
function setExifMetadata(string $filename, string $title)
{
	// exiftool doesn't support updating doc files :(
	if (pathinfo($filename, PATHINFO_EXTENSION) == 'doc') return;
	system('/usr/bin/exiftool -overwrite_original -Title='.escapeshellarg($title).' '.escapeshellarg($filename));
}

function setMP3Metadata(string $filename, string $title)
{
	system('/usr/bin/id3v2 -t '.escapeshellarg($title).' '.escapeshellarg($filename));
}
