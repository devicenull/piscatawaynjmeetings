<?php
/**
*	Site search, backed by a Meilisearch instance on localhost (see docs/search.md)
*/
class Search
{
	const INDEX = 'docs';
	const PER_PAGE = 20;

	const KIND_LABELS = [
		'minutes'              => 'Minutes',
		'transcript'           => 'Transcripts',
		'summary'              => 'Meeting Summaries',
		'bid'                  => 'Bids',
		'newsletter'           => 'Newsletters',
		'budget'               => 'Budgets',
		'audits'               => 'Audits',
		'debt_statements'      => 'Debt Statements',
		'financial_statements' => 'Financial Statements',
		'redevelopment'        => 'Redevelopment Studies',
		'campaign'             => 'Campaign Finance',
		'tweet'                => 'Tweets',
	];

	// Filter choices shown to users, each covering one or more kinds
	const TYPE_FILTERS = [
		'minutes'       => ['label' => 'Minutes', 'kinds' => ['minutes']],
		'transcripts'   => ['label' => 'Transcripts & Summaries', 'kinds' => ['transcript', 'summary']],
		'bids'          => ['label' => 'Bids', 'kinds' => ['bid']],
		'finance'       => ['label' => 'Budgets & Audits', 'kinds' => ['budget', 'audits', 'debt_statements', 'financial_statements']],
		'redevelopment' => ['label' => 'Redevelopment Studies', 'kinds' => ['redevelopment']],
		'campaign'      => ['label' => 'Campaign Finance', 'kinds' => ['campaign']],
		'newsletters'   => ['label' => 'Newsletters', 'kinds' => ['newsletter']],
		'tweets'        => ['label' => 'Tweets', 'kinds' => ['tweet']],
	];

	// Marks matched words; private-use characters survive HTML escaping untouched
	const MARK_START = "\u{E000}";
	const MARK_END   = "\u{E001}";

	/**
	*	Raw Meilisearch API call. Throws on transport errors and non-2xx responses.
	*/
	public static function request(string $method, string $path, $body=null, string $key=MEILI_SEARCH_KEY, string $content_type='application/json'): array
	{
		$ch = curl_init(MEILI_URL.$path);
		$headers = ['Authorization: Bearer '.$key];
		if ($body !== null)
		{
			$headers[] = 'Content-Type: '.$content_type;
			curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
		}
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT        => 60,
		]);
		$raw = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if ($raw === false)
		{
			throw new RuntimeException('Meilisearch unreachable: '.curl_error($ch));
		}
		$response = json_decode($raw, true) ?? [];
		if ($status < 200 || $status >= 300)
		{
			throw new RuntimeException("Meilisearch $method $path failed (HTTP $status): ".($response['message'] ?? $raw), $status);
		}
		return $response;
	}

	/**
	*	$filters: type (list of TYPE_FILTERS keys), board (Meeting::BOARD_TYPES key),
	*	from/to (years). $sort: relevance|newest
	*/
	public static function query(string $q, array $filters=[], string $sort='relevance', int $page=1): array
	{
		// Only whitelisted values reach the filter expression, so no quoting issues
		$filter = [];
		if (isset(Meeting::BOARD_TYPES[$filters['board'] ?? '']))
		{
			$filter[] = 'board = "'.$filters['board'].'"';
		}
		if (!empty($filters['from']))
		{
			$filter[] = 'year >= '.(int)$filters['from'];
		}
		if (!empty($filters['to']))
		{
			$filter[] = 'year <= '.(int)$filters['to'];
		}
		$kinds = [];
		foreach ($filters['type'] ?? [] as $type)
		{
			$kinds = array_merge($kinds, self::TYPE_FILTERS[$type]['kinds'] ?? []);
		}
		$type_filter = $kinds ? ['kind IN ['.implode(', ', array_map(fn($k) => '"'.$k.'"', $kinds)).']'] : [];

		// Second query counts every type ignoring the type filter, so unchecked types still show their counts
		[$response, $counts] = self::request('POST', '/multi-search', ['queries' => [
			[
				'indexUid'              => self::INDEX,
				'q'                     => $q,
				'filter'                => array_merge($filter, $type_filter),
				'sort'                  => $sort == 'newest' ? ['date:desc'] : [],
				'page'                  => max(1, $page),
				'hitsPerPage'           => self::PER_PAGE,
				'attributesToRetrieve'  => ['doc', 'kind', 'board', 'title', 'url', 'anchor', 'date', 'speakers'],
				'attributesToHighlight' => ['title', 'body'],
				'attributesToCrop'      => ['body:40'],
				'highlightPreTag'       => self::MARK_START,
				'highlightPostTag'      => self::MARK_END,
			],
			[
				'indexUid'    => self::INDEX,
				'q'           => $q,
				'filter'      => $filter,
				'facets'      => ['kind'],
				'hitsPerPage' => 0,
			],
		]])['results'];

		$hits = [];
		foreach ($response['hits'] as $hit)
		{
			$hits[] = [
				'title'        => self::stripMarks($hit['_formatted']['title']),
				'title_html'   => self::markToHtml($hit['_formatted']['title']),
				'snippet'      => self::stripMarks($hit['_formatted']['body'] ?? ''),
				'snippet_html' => self::markToHtml($hit['_formatted']['body'] ?? ''),
				// site paths contain raw filenames ("/files/bids/2022-ROAD PROGRAM ... & ADA.pdf")
				'url'          => ($hit['url'][0] == '/' ? implode('/', array_map('rawurlencode', explode('/', $hit['url']))) : $hit['url']).$hit['anchor'],
				'kind'         => $hit['kind'],
				'kind_label'   => self::KIND_LABELS[$hit['kind']] ?? $hit['kind'],
				'board'        => $hit['board'],
				'board_label'  => Meeting::BOARD_TYPES[$hit['board']] ?? null,
				'date'         => date('Y-m-d', $hit['date']),
				'speakers'     => $hit['speakers'],
			];
		}

		$type_counts = [];
		foreach (self::TYPE_FILTERS as $type => $info)
		{
			$type_counts[$type] = array_sum(array_intersect_key($counts['facetDistribution']['kind'] ?? [], array_flip($info['kinds'])));
		}

		return [
			'hits'        => $hits,
			'total'       => $response['totalHits'],
			'page'        => $response['page'],
			'total_pages' => $response['totalPages'],
			'type_counts' => $type_counts,
			'ms'          => $response['processingTimeMs'],
		];
	}

	private static function markToHtml(string $text): string
	{
		return str_replace([self::MARK_START, self::MARK_END], ['<mark>', '</mark>'], htmlspecialchars($text));
	}

	private static function stripMarks(string $text): string
	{
		return str_replace([self::MARK_START, self::MARK_END], '', $text);
	}
}
