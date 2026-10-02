<?php
/**
*	Extract searchable text from every registered meeting/file into
*	NDJSON for Meilisearch. Runs on pre-prod (needs web/files), then the output
*	is loaded on each host with scripts/search_cli.php load.
*
*	Usage: php scripts/build_search_docs.php > data/search_docs.ndjson
*	       php scripts/build_search_docs.php --selftest
*
*	See docs/search.md
*/
require_once(__DIR__.'/../init.php');

const WEB_DIR = __DIR__.'/../web';
// extracted text per file, so unchanged files aren't re-read every build
const SEARCH_CACHE_DIR = __DIR__.'/../data/search_cache';
// seconds of transcript per search record
const TRANSCRIPT_CHUNK_SECONDS = 120;

// Tiebreak between equally-good text matches (higher wins)
const RANK = [
	'summary'              => 3,
	'minutes'              => 3,
	'transcript'           => 2,
	'budget'               => 2,
	'audits'               => 2,
	'debt_statements'      => 2,
	'financial_statements' => 2,
	'redevelopment'        => 2,
	'campaign'             => 1,
	'bid'                  => 1,
	'newsletter'           => 1,
];

if (($argv[1] ?? '') == '--selftest')
{
	selftest();
	exit(0);
}

$counts = [];
$emit = function (array $doc) use (&$counts)
{
	$doc['rank'] = RANK[$doc['kind']];
	$doc['year'] = (int)date('Y', $doc['date']);
	$doc += ['board' => null, 'speakers' => [], 'anchor' => ''];
	$counts[$doc['kind']] = ($counts[$doc['kind']] ?? 0) + 1;
	echo json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
};

foreach (Meeting::getAll() as $meeting)
{
	if (!$meeting->hasHappened())
	{
		continue;
	}
	$date  = explode(' ', $meeting['date'])[0];
	$ts    = strtotime($date);
	$nice  = date('F j, Y', $ts);
	$base  = ['board' => $meeting['type'], 'date' => $ts];
	$mid   = 'meeting-'.$meeting['MEETINGID'];

	if ($meeting['minutes_available'] == 'yes')
	{
		$link = $meeting->getLink('minutes');
		emitFile($emit, $link, $base + [
			'kind'  => 'minutes',
			'doc'   => $mid.'-minutes',
			'title' => $meeting['board_type'].' Minutes, '.$nice,
		]);
	}

	if ($meeting['transcript_available'] == 'yes')
	{
		$page = '/'.$meeting['type'].'/meeting/'.$date;
		$doc  = $mid.'-transcript';
		foreach (chunkTranscript($meeting->getTranscriptLines(), TRANSCRIPT_CHUNK_SECONDS) as $chunk)
		{
			$emit($base + [
				'id'       => $mid.'-t'.$chunk['ts'],
				'doc'      => $doc,
				'kind'     => 'transcript',
				'title'    => $meeting['board_type'].' Meeting Transcript, '.$nice,
				'body'     => $chunk['text'],
				'speakers' => $chunk['speakers'],
				'url'      => $page,
				'anchor'   => '#t='.$chunk['ts'],
			]);
		}
		foreach ($meeting->getTranscriptSections() as $i => $section)
		{
			$emit($base + [
				'id'     => $mid.'-s'.$i,
				'doc'    => $doc,
				'kind'   => 'summary',
				'title'  => $section['title'].' ('.$meeting['board_type'].', '.$nice.')',
				'body'   => trim(($section['description'] ?? '').' '.implode(' ', $section['cases'] ?? [])),
				'url'    => $page,
				'anchor' => '#t='.$section['ts_seconds'],
			]);
		}
	}
}

foreach (Bid::getAll() as $bid)
{
	emitFile($emit, $bid->getLink(), [
		'kind'  => 'bid',
		'doc'   => 'bid-'.$bid['BIDID'],
		'title' => pathinfo($bid['filename'], PATHINFO_FILENAME),
		'date'  => strtotime($bid['date_created']),
	]);
}

foreach (Newsletter::getAll() as $newsletter)
{
	$months = ['Winter' => 1, 'Spring' => 4, 'Summer' => 7, 'Fall' => 10];
	emitFile($emit, $newsletter->getLink(), [
		'kind'  => 'newsletter',
		'doc'   => 'newsletter-'.$newsletter['NEWSLETTERID'],
		'title' => $newsletter['season'].' '.$newsletter['year'].' Newsletter',
		'date'  => mktime(0, 0, 0, $months[$newsletter['season']], 1, $newsletter['year']),
	]);
}

foreach (MiscFile::getAll() as $file)
{
	if (!isset(RANK[$file['type']]))
	{
		fwrite(STDERR, "Skipping misc file type {$file['type']}: no RANK entry\n");
		continue;
	}
	emitFile($emit, $file->getLink(), [
		'kind'  => $file['type'],
		'doc'   => 'misc-'.$file['FILEID'],
		'title' => $file['type_description'].', '.date('F j, Y', strtotime($file['date'])),
		'date'  => strtotime($file['date']),
	]);
}

foreach (RedevelopmentStudy::getAll() as $study)
{
	emitFile($emit, $study->getLink(), [
		'kind'  => 'redevelopment',
		'doc'   => 'redevelopment-'.$study['STUDYID'],
		'title' => 'Redevelopment '.$study['label'].': '.pathinfo($study['filename'], PATHINFO_FILENAME),
		'date'  => strtotime($study['date']),
	]);
}

foreach (CampaignFile::getAll() as $file)
{
	// Filenames look like "PDO Report 2017-04-17 R-3.pdf"; fall back to the campaign year
	$date = preg_match('/\d{4}-\d{2}-\d{2}/', $file['filename'], $m) ? strtotime($m[0]) : mktime(0, 0, 0, 1, 1, $file['year']);
	emitFile($emit, $file->getLink(), [
		'kind'  => 'campaign',
		'doc'   => 'campaign-'.$file['CAMPAIGNFILEID'],
		'title' => $file['year'].' Campaign Finance: '.pathinfo($file['filename'], PATHINFO_FILENAME),
		'date'  => $date,
	]);
}

ksort($counts);
foreach ($counts as $kind => $count)
{
	fwrite(STDERR, sprintf("%-22s %d\n", $kind, $count));
}

/**
*	Emit one record per PDF page, or one record for a whole .doc/.docx
*/
function emitFile(callable $emit, string $link, array $doc): void
{
	$path = WEB_DIR.$link;
	if (!file_exists($path))
	{
		fwrite(STDERR, "Missing file: $link\n");
		return;
	}

	$pages = cachedPages($path);
	$emitted = 0;
	foreach ($pages as $i => $text)
	{
		if (!isUsefulText($text))
		{
			continue;
		}
		$multi = count($pages) > 1;
		$emit($doc + [
			'id'     => $doc['doc'].($multi ? '-p'.($i + 1) : ''),
			'body'   => $text,
			'url'    => $link,
			'anchor' => $multi ? '#page='.($i + 1) : '',
		]);
		$emitted++;
	}

	if ($emitted == 0)
	{
		fwrite(STDERR, "No text: $link\n");
	}
}

/**
*	extractPages(), skipping the (slow) extraction when the file's size and mtime
*	match the cached copy. Deleting SEARCH_CACHE_DIR just forces a full re-extract.
*/
function cachedPages(string $path): array
{
	$stat = stat($path);
	$cache_file = SEARCH_CACHE_DIR.'/'.md5(realpath($path)).'.json';
	$cached = is_file($cache_file) ? json_decode(file_get_contents($cache_file), true) : null;
	if ($cached && $cached['size'] == $stat['size'] && $cached['mtime'] == $stat['mtime'])
	{
		return $cached['pages'];
	}

	$pages = extractPages($path);
	if (!is_dir(SEARCH_CACHE_DIR))
	{
		mkdir(SEARCH_CACHE_DIR, 0755, true);
	}
	// ponytail: entries for deleted files are never pruned; rm -r the dir if it ever matters
	file_put_contents($cache_file, json_encode(['size' => $stat['size'], 'mtime' => $stat['mtime'], 'pages' => $pages], JSON_INVALID_UTF8_SUBSTITUTE));
	return $pages;
}

/**
*	Returns a list of page texts (a single entry for non-PDF documents)
*/
function extractPages(string $path): array
{
	switch (strtolower(pathinfo($path, PATHINFO_EXTENSION)))
	{
		case 'pdf':
			return splitPages(shell_exec('pdftotext -q '.escapeshellarg($path).' - 2>/dev/null') ?? '');
		case 'doc':
			return [normalizeText(shell_exec('antiword -w 0 '.escapeshellarg($path).' 2>/dev/null') ?? '')];
		case 'docx':
			$zip = new ZipArchive();
			if ($zip->open($path) !== true)
			{
				return [];
			}
			$xml = $zip->getFromName('word/document.xml') ?: '';
			$zip->close();
			$xml = str_replace(['</w:p>', '<w:tab/>'], ["\n", ' '], $xml);
			return [normalizeText(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1))];
		default:
			return [];
	}
}

/**
*	pdftotext separates pages with form feeds; keep empty pages so page numbers stay aligned
*/
function splitPages(string $text): array
{
	$pages = explode("\f", $text);
	if (end($pages) !== false && trim(end($pages)) === '')
	{
		array_pop($pages);
	}
	return array_map('normalizeText', $pages);
}

function normalizeText(string $text): string
{
	return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/**
*	Drops blank pages and OCR noise (mostly punctuation/stray glyphs)
*/
function isUsefulText(string $text): bool
{
	if (strlen($text) < 20)
	{
		return false;
	}
	// not mb_strlen(): without the mbstring extension it's symfony's polyfill, ~60x slower here
	$letters = preg_match_all('/\pL/u', $text);
	return $letters / max(1, preg_match_all('/./su', $text)) > 0.5;
}

/**
*	Groups transcript lines into windows of $seconds, keyed by the first timestamp
*	in each window. Returns [{ts, text, speakers}, ...]
*/
function chunkTranscript(array $lines, int $seconds): array
{
	$chunks = [];
	$cur = null;
	foreach ($lines as $line)
	{
		if ($line['ts'] !== null && ($cur === null || $line['ts'] - $cur['ts'] >= $seconds))
		{
			if ($cur !== null)
			{
				$chunks[] = $cur;
			}
			$cur = ['ts' => (int)$line['ts'], 'text' => '', 'speakers' => []];
		}
		if ($cur === null)
		{
			// text before the first timestamp
			$cur = ['ts' => 0, 'text' => '', 'speakers' => []];
		}
		$cur['text'] .= ($cur['text'] === '' ? '' : ' ').$line['text'];
		// "Speaker 3" isn't useful to search for, identified names are
		if ($line['speaker'] !== null && !preg_match('/^Speaker \d+$/', $line['speaker']) && !in_array($line['speaker'], $cur['speakers']))
		{
			$cur['speakers'][] = $line['speaker'];
		}
	}
	if ($cur !== null)
	{
		$chunks[] = $cur;
	}
	return $chunks;
}

function selftest(): void
{
	$line = fn($speaker, $ts, $text) => ['speaker' => $speaker, 'ts' => $ts, 'display_ts' => '', 'text' => $text];
	$chunks = chunkTranscript([
		$line(null, null, 'header'),
		$line('Speaker 0', 5, 'a'),
		$line('Jane Doe', 60.7, 'b'),
		$line('Jane Doe', 125, 'c'),
		$line(null, null, 'd'),
		$line('Speaker 1', 300, 'e'),
	], 120);
	// production php.ini disables assert(), so check explicitly
	$checks = [
		'chunk count'          => count($chunks) == 3,
		'chunk 0'              => $chunks[0] == ['ts' => 0, 'text' => 'header a b', 'speakers' => ['Jane Doe']],
		'chunk 1'              => $chunks[1] == ['ts' => 125, 'text' => 'c d', 'speakers' => ['Jane Doe']],
		'chunk 2'              => $chunks[2] == ['ts' => 300, 'text' => 'e', 'speakers' => []],
		'empty transcript'     => chunkTranscript([], 120) == [],
		// a blank page must still count so page 3 stays page 3
		'trailing form feed'   => splitPages("one\n\fx\f  three  \f") == ['one', 'x', 'three'],
		'blank page kept'      => splitPages("one\f\fthree") == ['one', '', 'three'],
		'real text useful'     => isUsefulText('Resolution authorizing the award of a contract'),
		'OCR noise not useful' => !isUsefulText('| . , ; ~ -- __ || .. :: ;; ,,, ...'),
		'short not useful'     => !isUsefulText('short'),
	];
	foreach ($checks as $name => $ok)
	{
		if (!$ok)
		{
			fwrite(STDERR, "selftest FAILED: $name\n");
			exit(1);
		}
	}
	echo "selftest ok\n";
}
