<?php
require_once(__DIR__.'/init.php');

/**
*	Updates the database to represent what minutes/recordings are available
*/

$known_files = [];

foreach (Meeting::getAll() as $cur)
{
	if (!$cur->hasHappened()) continue;

	echo $cur['type']."\t".$cur['date']."\t";

	$link = $cur->getLink('minutes');
	if ($link != '')
	{
		$cur->set(['minutes_available' => 'yes', 'minutes_filetype' => pathinfo(__DIR__.'/web/'.$link, PATHINFO_EXTENSION)]);
		echo "minutes: yes\t";
		$known_files[] = $link;
	}
	else
	{
		echo "minutes: no\t";
		$cur->set(['minutes_available' => 'no']);
	}

	$link = $cur->getLink('recording');
	if ($link != '')
	{
		$cur->set(['recording_available' => 'yes', 'recording_filetype' => pathinfo($link, PATHINFO_EXTENSION)]);
		echo "recording: yes\n";
		$known_files[] = $link;
	}
	else
	{
		echo "recording: no\n";
		$cur->set(['recording_filetype' => 'no']);
	}
}
// report any files that don't have an associated meeting
foreach (getDirContents(__DIR__.'/web/files') as $cur)
{
	$cleanpath = str_replace(__DIR__.'/web', '', $cur);
	if (stripos($cleanpath, 'piscataway_youtube') != 0) continue;

	if (stripos($cleanpath, 'newsletter') != 0)
	{
		$newsletter = new Newsletter(['filename' => basename($cleanpath)]);
		if (!$newsletter->isInitialized())
		{
			$newsletter->add([
				'filename' => basename($cleanpath),
			]);
		}
		continue;
	}

	if (stripos($cleanpath, 'bids/') != 0)
	{
		$bid = new Bid(['filename' => basename($cleanpath)]);
		if (!$bid->isInitialized())
		{
			$bid->add([
				'filename' => basename($cleanpath),
			]);
		}
		continue;
	}

	$fileinfo = explode('/', $cleanpath);
	if (in_array($fileinfo[2], array_keys(MiscFile::TYPE_DESCRIPTION)))
	{
		$details = pathinfo($cleanpath);
		$date = $details['filename'];
		$extension = $details['extension'];

		if (!preg_match_all('/^[0-9]+\\-[0-9]+\\-[0-9]+$/i', $date))
		{
			echo "Invalid file format: $cleanpath\n";
			continue;
		}

		$miscfile = new MiscFile([
			'type' => $fileinfo[2],
			'date' => $date,
		]);

		if (!$miscfile->isInitialized())
		{
			$miscfile->add([
				'type'      => $fileinfo[2],
				'date'      => $date,
				'extension' => $extension,
			]);
		}

		continue;
	}

	if (!in_array($cleanpath, $known_files))
	{
		echo "Missing meeting: {$cleanpath}\n";
	}
}

echo "done\n";

function getDirContents($dir, &$results = array())
{
    $files = scandir($dir);

    foreach ($files as $key => $value)
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
