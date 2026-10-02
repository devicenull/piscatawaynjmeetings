<?php
/**
*	Manage the local Meilisearch instance. Run on the host itself (over ssh for
*	pre-prod/prod); Meilisearch only listens on localhost.
*
*	Usage: php scripts/search_cli.php load <file.ndjson>   rebuild the index and swap it in
*	       php scripts/search_cli.php stats
*	       php scripts/search_cli.php tasks
*	       php scripts/search_cli.php search <query>
*
*	See docs/search.md
*/
require_once(__DIR__.'/../init.php');

const NEW_INDEX = Search::INDEX.'_new';
// Stay well under Meilisearch's default 100MB payload limit
const BATCH_BYTES = 20 * 1024 * 1024;

const SETTINGS = [
	// order = weight
	'searchableAttributes' => ['title', 'speakers', 'body'],
	'filterableAttributes' => ['kind', 'board', 'year', 'date'],
	'sortableAttributes'   => ['date'],
	// one result per file/transcript, using its best page/section
	'distinctAttribute'    => 'doc',
	// sort first so "newest" is strictly by date (it's a no-op when no sort is requested);
	// rank/date only break ties between equally good text matches
	'rankingRules'         => ['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness', 'rank:desc', 'date:desc'],
	// ordinance, block and lot numbers should match exactly
	'typoTolerance'        => ['disableOnNumbers' => true],
	'pagination'           => ['maxTotalHits' => 1000],
	'synonyms'             => [
		'twp'       => ['township'],
		'township'  => ['twp'],
		'ave'       => ['avenue'],
		'avenue'    => ['ave'],
		'rd'        => ['road'],
		'road'      => ['rd'],
		'st'        => ['street'],
		'street'    => ['st'],
		'ln'        => ['lane'],
		'lane'      => ['ln'],
		'hwy'       => ['highway'],
		'highway'   => ['hwy'],
		'ord'       => ['ordinance'],
		'warehouse' => ['logistics'],
		'logistics' => ['warehouse'],
	],
];

function admin(string $method, string $path, $body=null, string $content_type='application/json'): array
{
	return Search::request($method, $path, $body, MEILI_MASTER_KEY, $content_type);
}

function waitForTask(array $task): void
{
	while (true)
	{
		$status = admin('GET', '/tasks/'.$task['taskUid']);
		if ($status['status'] == 'succeeded')
		{
			return;
		}
		if ($status['status'] == 'failed' || $status['status'] == 'canceled')
		{
			throw new RuntimeException("Task {$task['taskUid']} ({$status['type']}) {$status['status']}: ".($status['error']['message'] ?? ''));
		}
		usleep(250000);
	}
}

function indexExists(string $uid): bool
{
	try
	{
		admin('GET', '/indexes/'.$uid);
		return true;
	}
	catch (RuntimeException $e)
	{
		if ($e->getCode() == 404)
		{
			return false;
		}
		throw $e;
	}
}

function load(string $file): void
{
	$fh = fopen($file, 'r');
	if (!$fh)
	{
		throw new RuntimeException("Can't read $file");
	}

	// Leftover from a failed run
	if (indexExists(NEW_INDEX))
	{
		waitForTask(admin('DELETE', '/indexes/'.NEW_INDEX));
	}
	waitForTask(admin('POST', '/indexes', ['uid' => NEW_INDEX, 'primaryKey' => 'id']));
	waitForTask(admin('PATCH', '/indexes/'.NEW_INDEX.'/settings', SETTINGS));

	$tasks = [];
	$batch = '';
	$count = 0;
	while (($line = fgets($fh)) !== false)
	{
		$batch .= $line;
		$count++;
		if (strlen($batch) >= BATCH_BYTES)
		{
			$tasks[] = admin('POST', '/indexes/'.NEW_INDEX.'/documents', $batch, 'application/x-ndjson');
			$batch = '';
		}
	}
	if ($batch !== '')
	{
		$tasks[] = admin('POST', '/indexes/'.NEW_INDEX.'/documents', $batch, 'application/x-ndjson');
	}
	fclose($fh);

	echo "Sent $count documents in ".count($tasks)." batches, waiting for indexing...\n";
	foreach ($tasks as $task)
	{
		waitForTask($task);
	}

	$indexed = admin('GET', '/indexes/'.NEW_INDEX.'/stats')['numberOfDocuments'];
	if ($indexed != $count)
	{
		throw new RuntimeException("Indexed $indexed of $count documents, leaving the live index alone");
	}

	// swap needs both sides to exist
	if (!indexExists(Search::INDEX))
	{
		waitForTask(admin('POST', '/indexes', ['uid' => Search::INDEX, 'primaryKey' => 'id']));
	}
	waitForTask(admin('POST', '/swap-indexes', [['indexes' => [Search::INDEX, NEW_INDEX]]]));
	waitForTask(admin('DELETE', '/indexes/'.NEW_INDEX));
	echo "Swapped in ".Search::INDEX." with $indexed documents\n";
}

$command = $argv[1] ?? '';
try
{
	switch ($command)
	{
		case 'load':
			if (!isset($argv[2]))
			{
				die("Usage: php scripts/search_cli.php load <file.ndjson>\n");
			}
			load($argv[2]);
			break;
		case 'stats':
			echo json_encode(admin('GET', '/stats'), JSON_PRETTY_PRINT)."\n";
			break;
		case 'tasks':
			foreach (admin('GET', '/tasks?limit=20')['results'] as $task)
			{
				printf("%6d  %-10s %-22s %-12s %s\n", $task['uid'], $task['status'], $task['type'], $task['indexUid'] ?? '', $task['error']['message'] ?? '');
			}
			break;
		case 'search':
			$result = Search::query(implode(' ', array_slice($argv, 2)));
			printf("%d results in %dms\n\n", $result['total'], $result['ms']);
			foreach ($result['hits'] as $hit)
			{
				printf("[%s] %s  %s\n    %s\n    %s\n\n", $hit['kind'], $hit['date'], $hit['title'], $hit['url'], mb_substr($hit['snippet'], 0, 200));
			}
			break;
		default:
			die("Usage: php scripts/search_cli.php load <file.ndjson> | stats | tasks | search <query>\n");
	}
}
catch (RuntimeException $e)
{
	fwrite(STDERR, $e->getMessage()."\n");
	exit(1);
}
