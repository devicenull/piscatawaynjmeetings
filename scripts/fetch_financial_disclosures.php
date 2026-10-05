<?php
/**
*	Download Piscataway Township Local Government Ethics Law financial disclosure statements from
*	the NJ DCA FDS search (https://fds.dca.nj.gov/njdca_prod/fdssearch.aspx)
*	into web/files/financial_disclosures/YEAR/
*
*	Usage: php fetch_financial_disclosures.php [year ...]   (defaults to the current year)
*
*	Existing files are skipped.  The site sits behind Imperva, which 403s anything that
*	doesn't look like a browser, hence the headers.
*/
const FDS_BASE = 'https://fds.dca.nj.gov';
const FDS_SEARCH = FDS_BASE.'/njdca_prod/fdssearch.aspx';
const FDS_AGENCY = 'Piscataway';
const FDS_ENTITY = 'Piscataway Township - County of Middlesex';
const FDS_DIR = __DIR__.'/../web/files/financial_disclosures';

$cookies = tempnam(sys_get_temp_dir(), 'fds');

function fds(string $url, ?array $post = null): string
{
	global $cookies;
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_COOKIEJAR      => $cookies,
		CURLOPT_COOKIEFILE     => $cookies,
		CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
		CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,application/pdf', 'Accept-Language: en-US,en;q=0.9'],
		CURLOPT_REFERER        => FDS_SEARCH,
		CURLOPT_TIMEOUT        => 120,
	]);
	if ($post !== null)
	{
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	}
	for ($attempt = 1; $attempt <= 5; $attempt++)
	{
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($body !== false && $code == 200)
		{
			break;
		}
		sleep(5 * $attempt);
	}
	curl_close($ch);
	if ($body === false || $code != 200)
	{
		throw new RuntimeException("FDS request failed ($code): $url");
	}
	return $body;
}

// ASP.NET WebForms postback: resend the page's hidden state plus the search fields
function postback(string $page, int $year, array $extra): string
{
	preg_match_all('/<input type="hidden" name="([^"]+)" id="[^"]*" value="([^"]*)"/', $page, $m, PREG_SET_ORDER);
	$form = [];
	foreach ($m as [, $name, $value])
	{
		$form[$name] = html_entity_decode($value);
	}
	return fds(FDS_SEARCH, $extra + [
		'ctl00$ContentPlaceHolder1$txtAgency'    => FDS_AGENCY,
		'ctl00$ContentPlaceHolder1$ddYear'       => $year,
		'ctl00$ContentPlaceHolder1$txtLastName'  => '',
		'ctl00$ContentPlaceHolder1$txtFirstName' => '',
	] + $form);
}

function parseRows(string $page): array
{
	preg_match_all('#<tr class="(?:alt)?rowclass".*?</tr>#s', $page, $trs);
	$rows = [];
	foreach ($trs[0] as $tr)
	{
		preg_match_all('#<td[^>]*>(.*?)</td>#s', $tr, $tds);
		$cells = array_map(fn($c) => trim(html_entity_decode(strip_tags($c))), $tds[1]);
		preg_match('/__doPostBack\(&#39;([^&]+lnkbtnFile)&#39;/', $tr, $target);
		$rows[] = [
			'last'      => $cells[1],
			'first'     => $cells[2],
			'entity'    => $cells[3],
			'position'  => $cells[5],
			'submitted' => $cells[6],
			'target'    => $target[1],
		];
	}
	return $rows;
}

$years = array_slice($argv, 1) ?: [date('Y')];
foreach ($years as $year)
{
	$dir = FDS_DIR.'/'.$year;
	@mkdir($dir, 0755, true);

	$page = postback(fds(FDS_SEARCH), $year, ['ctl00$ContentPlaceHolder1$btnSearch' => 'Search']);
	$seen = [];
	for ($pagenum = 1; ; $pagenum++)
	{
		foreach (parseRows($page) as $row)
		{
			// the agency search is "contains", so it also picks up the school district, fire districts, etc.
			if ($row['entity'] != FDS_ENTITY)
			{
				continue;
			}
			$name = str_replace(['/', "\0"], '-', "{$row['first']} {$row['last']} - {$row['position']}");
			// same person + position filed twice
			if (isset($seen[$name]))
			{
				$name .= ' '.date('Y-m-d', strtotime($row['submitted']));
			}
			$seen[$name] = true;
			$file = "$dir/$name.pdf";
			if (file_exists($file))
			{
				continue;
			}

			// "View" renders the PDF to a temp file on their side and returns a window.open() to it
			$resp = postback($page, $year, ['__EVENTTARGET' => $row['target'], '__EVENTARGUMENT' => '']);
			if (!preg_match("#window\.open\('(/NJDCA_Prod/FDSFiles/[^']+\.pdf)#", $resp, $pdf))
			{
				echo "No PDF link for $name ($year)\n";
				continue;
			}
			$body = fds(FDS_BASE.$pdf[1]);
			if (strncmp($body, '%PDF', 4) != 0)
			{
				echo "Not a PDF for $name ($year)\n";
				continue;
			}
			file_put_contents($file, $body);
			echo "Saved $year/$name.pdf\n";
		}

		$next = $pagenum + 1;
		if (strpos($page, "&#39;Page\$$next&#39;") === false)
		{
			break;
		}
		$page = postback($page, $year, ['__EVENTTARGET' => 'ctl00$ContentPlaceHolder1$gvFDSSearch', '__EVENTARGUMENT' => "Page\$$next"]);
	}
}
unlink($cookies);
