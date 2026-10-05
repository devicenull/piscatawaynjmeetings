<?php
class CampaignFile extends BaseDBObject
{
	var $fields = [
		'CAMPAIGNFILEID',
		'type',
		'year', // campaign year - not the same year as the file was submitted
		'filename',
	];

	const DB_KEY = 'CAMPAIGNFILEID';
	const DB_TABLE = 'campaign_files';
	var $virtual_fields = [
		'type_description',
	];

	const TYPE_DESCRIPTION = [
		'finance_statement' => 'Campaign Finance Statements',
		'summary_data'      => 'Contribution/Expenditure Data (CSV)',
	];

	// ELEC election types, in the order campaigns are listed
	const ELECTION_DESCRIPTION = [
		'PAC'                => 'Party Organization',
		'PRIMARY'            => 'Primary',
		'GENERAL'            => 'General',
		'SCHOOL BOARD-NOV.'  => 'School Board',
		'SCHOOL BOARD-APRIL' => 'School Board (April)',
		'FIRE COMMISSIONER'  => 'Fire Commissioner',
	];

	// short prefixes fetch_campaign_reports.php uses for the party organizations
	const PARTY_NAMES = [
		'PDO'  => 'PISCATAWAY REGULAR DEMOCRATIC ORGANIZATION',
		'PTRO' => 'PISCATAWAY TOWNSHIP REPUBLICAN ORGANIZATION',
	];

	// keyed on the form prefix (R-1 and R-3 are both "R")
	const FORM_DESCRIPTION = [
		'R'     => 'Contributions & expenditures report',
		'D'     => 'Committee registration',
		'A'     => 'Sworn statement (small campaign)',
		'72-24' => 'Large contribution notice',
	];

	public function __construct($params=[])
	{
		global $db;
		if (isset($params['type']) && isset($params['filename']))
		{
			$res = $db->Execute('select * from campaign_files where type=? and filename=?', [$params['type'], $params['filename']]);
			if ($res->RecordCount() == 1)
			{
				$this->record = $res->fields;
				return;
			}
		}
		parent::__construct($params);
	}

	public function get($offset)
	{
		if ($offset == 'type_description')
		{
			return self::TYPE_DESCRIPTION[$this['type']];
		}
		return parent::get($offset);
	}

	public function getLink(): string
	{
		return '/files/campaign/'.$this['year'].'/'.$this['filename'];
	}

	/**
	*	Splits a filename from fetch_campaign_reports.php into its parts, ex:
	*	"WAHLER CAHILL SHAH & ROUSE PRIMARY 2024-06-25 R-1 Amend 1 (3821670).pdf"
	*	"PDO Report 2017-04-17 R-3.pdf", "PDO 2019 contributions (397838).csv"
	*/
	public static function parseFilename(string $filename): ?array
	{
		$elections = implode('|', array_map('preg_quote', array_keys(self::ELECTION_DESCRIPTION)));
		if (!preg_match('/^(?:(?<party>PDO|PTRO) (?:Report|\d{4})|(?<name>.+?) (?<election>'.$elections.'))'
			.' (?:(?<kind>contributions|expenditures) \(\d+\)|(?<date>\d{4}-\d{2}-\d{2}) (?<form>.+?)(?: Amend (?<amend>\d+))?(?: \(\d+\))?)'
			.'\.(?:pdf|csv)$/', $filename, $m))
		{
			return null;
		}

		$form_prefix = preg_replace('/-\d$/', '', $m['form'] ?? '');
		return [
			'name'     => $m['party'] ? self::PARTY_NAMES[$m['party']] : $m['name'],
			'election' => $m['party'] ? 'PAC' : $m['election'],
			'kind'     => $m['kind'] ?? '',
			'date'     => $m['date'] ?? '',
			'form'     => $m['form'] ?? '',
			'form_description' => self::FORM_DESCRIPTION[$form_prefix] ?? '',
			'amend'    => $m['amend'] ?? '',
		];
	}

	/**
	*	Files grouped into campaigns: [year => [slug => campaign]], newest year first,
	*	party organizations first and then by election type within a year
	*/
	public static function getCampaigns(): array
	{
		$campaigns = [];
		foreach (self::getAll() as $file)
		{
			$info = self::parseFilename($file['filename']);
			// fire commissioner races are almost all sworn statements with no money, just noise here
			if (!$info || $info['election'] == 'FIRE COMMISSIONER')
			{
				continue;
			}

			$slug = $info['name'].' '.$info['election'];
			$campaign = &$campaigns[$file['year']][$slug];
			$campaign ??= [
				'year'     => $file['year'],
				'slug'     => $slug,
				'name'     => $info['name'],
				'election' => $info['election'],
				'election_description' => self::ELECTION_DESCRIPTION[$info['election']],
				'reports'  => [],
				'data'     => [],
				'raised'   => null,
				'spent'    => null,
			];

			if ($info['kind'])
			{
				$campaign['data'][$info['kind']] = $file;
				// ponytail: parses every CSV (~1MB total) per page load, cache the totals in the DB if it gets slow
				$campaign[$info['kind'] == 'contributions' ? 'raised' : 'spent'] = array_sum(array_column(
					self::readCSV($file),
					$info['kind'] == 'contributions' ? 'ContributionAmount' : 'ExpenseAmount'
				));
			}
			else
			{
				$campaign['reports'][] = $info + ['file' => $file];
			}
			unset($campaign);
		}

		$election_order = array_flip(array_keys(self::ELECTION_DESCRIPTION));
		krsort($campaigns);
		foreach ($campaigns as &$year)
		{
			uasort($year, fn($a, $b) => [$election_order[$a['election']], -$a['raised'], $a['name']] <=> [$election_order[$b['election']], -$b['raised'], $b['name']]);
			foreach ($year as &$campaign)
			{
				usort($campaign['reports'], fn($a, $b) => [$b['date'], $b['amend']] <=> [$a['date'], $a['amend']]);
			}
		}
		return $campaigns;
	}

	/**
	*	Rows of an ELEC contribution/expenditure CSV, keyed by the header names
	*/
	public static function readCSV(CampaignFile $file): array
	{
		$f = fopen(BASE_FILE_PATH.'campaign/'.$file['year'].'/'.$file['filename'], 'r');
		if (!$f)
		{
			return [];
		}
		$header = fgetcsv($f);
		$rows = [];
		while (($row = fgetcsv($f)) !== false)
		{
			if (count($row) == count($header))
			{
				$rows[] = array_combine($header, $row);
			}
		}
		fclose($f);
		return $rows;
	}

	/**
	*	ELEC uppercases everything: "MCLELLAND-CRAWLEY REBECCA" -> "McLelland-Crawley Rebecca"
	*/
	public static function nameCase(string $name): string
	{
		$name = ucwords(strtolower($name), " \t-&/(.'");
		$name = preg_replace_callback('/\bMc([a-z])/', fn($m) => 'Mc'.strtoupper($m[1]), $name);
		$name = preg_replace_callback('/\.(Com|Net|Org)\b/', fn($m) => strtolower($m[0]), $name);
		// roman numerals and abbreviations that should stay uppercase
		return preg_replace_callback('/\b(Ii|Iii|Iv|Llc|Llp|Pc|Pa|Nj|Na|Efo|Cte|Pac|Pdo|Ptro|Usa)\b/', fn($m) => strtoupper($m[1]), $name);
	}

	public static function getAll()
	{
		global $db;
		$res = $db->Execute('
			select *
			from campaign_files
			order by year DESC, type
		');
		$meetings = [];
		foreach ($res as $cur)
		{
			$meetings[] = new CampaignFile(['record' => $cur]);
		}

		return $meetings;
	}
}
