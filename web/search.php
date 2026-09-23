<?php
require(__DIR__.'/../init.php');

$q = mb_substr(trim($_GET['q'] ?? ''), 0, 200);
$filters = [
	'type'  => array_values(array_intersect((array)($_GET['type'] ?? []), array_keys(Search::TYPE_FILTERS))),
	'board' => isset(Meeting::BOARD_TYPES[$_GET['board'] ?? '']) ? $_GET['board'] : '',
	'from'  => (int)($_GET['from'] ?? 0) ?: '',
	'to'    => (int)($_GET['to'] ?? 0) ?: '',
];
$sort = ($_GET['sort'] ?? '') == 'newest' ? 'newest' : 'relevance';
$page = max(1, (int)($_GET['page'] ?? 1));
$json = ($_GET['format'] ?? '') == 'json';

$results = null;
$error = '';
if ($q !== '')
{
	try
	{
		$results = Search::query($q, $filters, $sort, $page);
	}
	catch (RuntimeException $e)
	{
		error_log('search: '.$e->getMessage());
		$error = 'Search is temporarily unavailable, please try again later.';
	}
}

if ($json)
{
	header('Content-Type: application/json');
	if ($error !== '')
	{
		http_response_code(503);
		echo json_encode(['error' => $error]);
		exit();
	}
	foreach ($results['hits'] ?? [] as $i => $hit)
	{
		unset($results['hits'][$i]['title_html'], $results['hits'][$i]['snippet_html']);
		$results['hits'][$i]['url'] = ($hit['url'][0] == '/' ? 'https://piscatawaynjmeetings.com' : '').$hit['url'];
	}
	echo json_encode($results ?? ['hits' => [], 'total' => 0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit();
}

displayPage('search.html', [
	'q'            => $q,
	'filters'      => $filters,
	'sort'         => $sort,
	'results'      => $results,
	'search_error' => $error,
	'type_filters' => Search::TYPE_FILTERS,
	'boards'       => Meeting::BOARD_TYPES,
	// for pagination links
	'query'        => array_filter(['q' => $q, 'sort' => $sort] + $filters),
]);
