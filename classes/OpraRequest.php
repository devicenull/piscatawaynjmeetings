<?php
/**
*	OPRA requests submitted to the township's GovPilot form, posted straight to the same JSON API
*	the public form's JS uses (main.govpilot.com/web/public/5039c59f-406_Open-Records-Request-Township-of-Piscataway).
*/
class OpraRequest extends BaseDBObject
{
	var $fields = [
		'OPRAID',
		'submitted_at',
		'reference_number',
		'request_id',
		'request',
		'fields',
		'response',
	];

	const DB_KEY = 'OPRAID';
	const DB_TABLE = 'opra_requests';
	var $virtual_fields = ['requestor_name'];

	const SUBMIT_URL = 'https://api-main.govpilot.com/forms/7213/7efce/submit';
	const FORM_URL = 'https://main.govpilot.com/web/public/5039c59f-406_Open-Records-Request-Township-of-Piscataway?uid=7213&ust=NJ&pu=1&id=1';

	// our field name => GovPilot field id (sent as "cf<id>")
	const FIELD_MAP = [
		'delivery'           => '08e58',
		'first_name'         => 'a3604',
		'last_name'          => '1674d',
		'company'            => '605d7',
		'address'            => '39a79',
		'city'               => '622c2',
		'state'              => 'b3ffb',
		'zip'                => 'e2b5a',
		'phone'              => '102fa',
		'fax'                => '5ae91',
		'email'              => 'ba884',
		'request'            => '90ffb',
		'request_address'    => 'def2f',
		'block'              => '20444',
		'lot'                => 'b4ad4',
		'qualifier'          => '228ae',
		'common_law'         => '9ac07',
		'interest'           => 'df5ae',
		'commercial'         => '5a763',
		'commercial_upgrade' => '0469d',
		'legal'              => 'd83bc',
		'legal_proceeding'   => '90fa4',
		'convict'            => 'bfb53',
	];

	// per-person details, from OPRA_REQUESTORS in config.php (kept out of git)
	const REQUESTOR_FIELDS = ['first_name', 'last_name', 'company', 'address', 'city', 'state', 'zip', 'phone', 'fax', 'email'];

	const REQUIRED = ['delivery', 'first_name', 'last_name', 'phone', 'email', 'request', 'common_law', 'commercial', 'legal', 'convict'];

	// fields the public form's masked inputs send as raw digits
	const DIGITS_ONLY = ['zip', 'phone', 'fax'];

	// select fields and their allowed values
	const CHOICES = [
		'delivery'           => ['Email', 'Fax', 'On-Site Inspect', 'Pick Up', 'U.S. Mail'],
		'common_law'         => ['No', 'Yes'],
		'commercial'         => ['Will Not', 'Will'],
		'commercial_upgrade' => ['No', 'Yes'],
		'legal'              => ['I Am Not', 'I Am'],
		'convict'            => ['Have Not', 'Have'],
	];

	public static function requestors(): array
	{
		return defined('OPRA_REQUESTORS') ? OPRA_REQUESTORS : [];
	}

	public function get($offset)
	{
		if ($offset == 'requestor_name')
		{
			$f = json_decode($this->record['fields'] ?? '', true) ?: [];
			return trim(($f['first_name'] ?? '').' '.($f['last_name'] ?? ''));
		}
		return parent::get($offset);
	}

	public static function getAll()
	{
		global $db;
		$res = $db->Execute('select * from opra_requests order by submitted_at desc');
		$out = [];
		foreach ($res as $row)
		{
			$out[] = new OpraRequest(['record' => $row]);
		}
		return $out;
	}

	/**
	*	Field values from the last submission, so the requestor and certification answers carry over.
	*/
	public static function lastFields(): array
	{
		global $db;
		$json = $db->GetOne('select fields from opra_requests order by OPRAID desc limit 1');
		return $json ? json_decode($json, true) : [];
	}

	/**
	*	Validate + normalize posted values. Returns [$fields, $error].
	*/
	public static function cleanInput(array $post): array
	{
		$requestors = self::requestors();
		$requestor = $post['requestor'] ?? '';
		if (!isset($requestors[$requestor]))
		{
			return [$post, 'Unknown requestor'];
		}
		// requestor details come only from config, never the POST
		$post = array_merge($post, array_fill_keys(self::REQUESTOR_FIELDS, ''), $requestors[$requestor]);

		$fields = ['requestor' => $requestor];
		foreach (array_keys(self::FIELD_MAP) as $key)
		{
			$fields[$key] = trim($post[$key] ?? '');
		}
		$fields['signature'] = $fields['first_name'].' '.$fields['last_name'];
		foreach (self::DIGITS_ONLY as $key)
		{
			$fields[$key] = preg_replace('/\D/', '', $fields[$key]);
		}
		foreach (self::CHOICES as $key => $allowed)
		{
			if (!in_array($fields[$key], $allowed, true))
			{
				$fields[$key] = $allowed[0];
			}
		}
		// mirrors the form's logic rules
		if ($fields['commercial'] != 'Will' || $fields['delivery'] == 'Email')
		{
			$fields['commercial_upgrade'] = 'No';
		}
		if ($fields['common_law'] != 'Yes')
		{
			$fields['interest'] = '';
		}
		if ($fields['legal'] != 'I Am')
		{
			$fields['legal_proceeding'] = '';
		}

		$required = self::REQUIRED;
		if ($fields['delivery'] == 'U.S. Mail') $required = array_merge($required, ['address', 'city', 'state', 'zip']);
		if ($fields['delivery'] == 'Fax') $required[] = 'fax';
		if ($fields['common_law'] == 'Yes') $required[] = 'interest';
		if ($fields['legal'] == 'I Am') $required[] = 'legal_proceeding';

		$missing = array_filter($required, fn($k) => $fields[$k] === '');
		if ($missing)
		{
			return [$fields, 'Missing required fields: '.implode(', ', $missing)];
		}
		if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL))
		{
			return [$fields, 'Invalid email address'];
		}
		return [$fields, ''];
	}

	/**
	*	The JSON body the GovPilot form's SubmitRecord() builds.
	*/
	public static function buildPayload(array $fields, string $signature_image): array
	{
		$data = ['cal' => [], 'cfb6232' => '', 'cf12cef' => date('n/j/Y g:i A')];
		foreach (self::FIELD_MAP as $key => $id)
		{
			$value = $fields[$key];
			// their JS swaps quotes for backticks on free-text fields
			if (!isset(self::CHOICES[$key]) && !in_array($key, self::DIGITS_ONLY))
			{
				$value = str_replace(['"', "'"], '`', $value);
			}
			$data['cf'.$id] = $value;
		}
		$data['cf1aa49'] = json_encode(['Name' => $fields['signature'], 'Image' => $signature_image, 'signed' => false]);
		$data += [
			'RUID'        => '7213',
			'pid'         => '1',
			'pst'         => 'NJ',
			'status'      => 'submit',
			'uid'         => '7213',
			'tid'         => '7efce',
			'payment_due' => 0,
			'FSID'        => '25A65',
		];

		return [
			'metadata' => [
				'origin'      => 'https://main.govpilot.com',
				'referrer'    => '',
				'browserName' => 'Netscape',
				'userAgent'   => 'Mozilla/5.0',
				'url'         => self::FORM_URL,
				'onLine'      => true,
				'trails'      => [],
				'tid'         => '7efce',
				'uid'         => '7213',
				'pid'         => '1',
			],
			'data' => $data,
		];
	}

	/**
	*	Submit to GovPilot and record it. The row is saved even when the submit fails so the text isn't lost.
	*	Returns [OpraRequest, $error].
	*/
	public static function submit(array $fields, string $signature_image): array
	{
		$ch = curl_init(self::SUBMIT_URL);
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode(self::buildPayload($fields, $signature_image)),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Origin: https://main.govpilot.com',
				'Referer: '.self::FORM_URL,
			],
		]);
		$body = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = $body === false ? 'curl: '.curl_error($ch) : '';
		curl_close($ch);

		$resp = json_decode((string)$body, true);
		$result = is_array($resp) ? ($resp['data'] ?? $resp) : [];
		if (!$error && ($status != 200 || !empty($resp['error']) || !empty($result['ERR_CODE']) || empty($result['request_id'])))
		{
			$error = 'GovPilot submit failed (HTTP '.$status.'): '.($result['ERR_MSG'] ?? $resp['error'] ?? substr((string)$body, 0, 500));
		}

		$req = new OpraRequest();
		$req->add([
			'submitted_at'     => date('Y-m-d H:i:s'),
			'reference_number' => $result['cfb6232'] ?? $result['b6232'] ?? null,
			'request_id'       => $result['request_id'] ?? null,
			'request'          => $fields['request'],
			'fields'           => json_encode($fields),
			'response'         => (string)$body,
		]);
		return [$req, $error];
	}
}
