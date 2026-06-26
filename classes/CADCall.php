<?php
class CADCall extends BaseDBObject
{
	var $fields = [
		'incident',
		'call_time',
		'location',
		'call_type',
		'ADDRESSID',
		'name',
	];

	var $virtual_fields = [
		'lat',
		'lng',
	];

	const DB_KEY = 'incident';
	const DB_TABLE = 'cad_call';

	const TYPE_LABELS = [
		'SICK PERS'      => 'Sick Person',
		'DISORDERLY COND'=> 'Disorderly Conduct',
		'911 HANGUP'     => '911 Hangup',
		'NOISE COMPLAINT'=> 'Noise Complaint',
		'TRF STOP'       => 'Traffic Stop',
		'MV ACCIDENT'    => 'Motor Vehicle Accident',
		'OTHER/POLICE'   => 'Other Police Matter',
		'FOUND PROPERTY' => 'Found Property',
		'THEFT'          => 'Theft',
		'TRAFFIC/OTHER'  => 'Other Traffic Matter',
		'GAS LEAK'       => 'Gas Leak',
		'BURG ALARM BUS' => 'Business Burglar Alarm',
		'PARKING'        => 'Parking',
		'MISC EVENT'     => 'Misc',
		'ASSIST CITIZEN' => 'Assist Citizen',
		'FIRE - OTHER'   => 'Other Fire Matter',
		'WIRES DOWN'     => 'Wires Down',
		'SUSP PER'       => 'Suspicious Person',
		'CUSTODY DISPUTE'=> 'Custody Dispute',
		'WELFARE CHK'    => 'Welfare Check',
		'911 TRANSFER'   => '911 Transfer',
		'ANIMAL'         => 'Animal',
		'HIT/RUN'        => 'Hit & Run',
		'FRAUD'          => 'Fraud',
		'LIGHT/SIGN'     => 'Light/Sign',
		'FIRE ALARM'     => 'Fire Alarm',
		'ACC PROP DAMAGE'=> 'Accidental Property Damage',
		'WARRANT ARREST' => 'Arrest Warrant',
		'ESCORT'         => 'Escort Citizen',
		'DECEASED'       => 'Deceased Citizen',
		'LOST PROP'      => 'Lost Property',
		'ASST OTH PD'    => 'Assist Other Police Dept',
		'SUSP ODOR'      => 'Suspicious Odor',
		'BURG ALARM HOME'=> 'Home Burglar Alarm',
		'SEC CHECK'      => 'Security Check',
		'ACCIDENTAL INJ' => 'Accidental Injury',
		'RESTRAINING ORD'=> 'Restraining Order',
		'FIRE - BRUSH'   => 'Brush Fire',
		'HARASSMENT'     => 'Harassment',
		'DISABLED MV'    => 'Disabled Motor Vehicle',
		'AGG DRIVER'     => 'Aggressive Driver',
		'DOMESTIC DISP'  => 'Domestic Dispute',
		'SUSP VEH'       => 'Suspicious Vehicle',
		'SUSP INC'       => 'Suspicious Incident',
		'LOCKOUT'        => 'Lockout',
		'VERBAL DISPUTE' => 'Verbal Dispute',
		'CO ALARM'       => 'Carbon Monoxide Alarm',
		'BURGLARY'       => 'Burglary',
		'REC STOLEN MV'  => 'Recover Stolen Motor Vehicle',
		'ILLEGAL DUMPING'=> 'Illegal Dumping',
		'MISS PERS'      => 'Missing Person',
		'THEFT/MV'       => 'Stolen Car',
		'DCPP REF'       => 'DCPP Referral',
		'CRIM MISCHIEF'  => 'Criminal Mischief',
		'DWI'            => 'Driving While Intoxicated',
		'TEST'           => 'Test',
		'WEAPONS'        => 'Weapons',
		'MUN COURT TRANS'=> 'Municipal Court Transport',
		'ROR'            => 'Released on Own Recognizance',
		'VIO OF TRO/FRO' => 'Violation of Restraining Order',
		'ANIMAL BITE'    => 'Animal Bite',
		'FIRE INVESTIGA' => 'Fire Investigation',
		'POLE/TREE DOWN' => 'Pole/Tree Down',
	];

	public static function getTypeName(string $code): string
	{
		return self::TYPE_LABELS[$code] ?? $code;
	}

	public static function formatAddress(string $addr): string
	{
		return ucwords($addr);
	}

	var $insert_ignore = true;

	public function add(array $params): bool
	{
		$no_geocode = !empty($params['no_geocode']);
		unset($params['no_geocode']);

		if (!isset($params['ADDRESSID']))
		{
			if ($no_geocode)
			{
				global $db;
				$row = $db->GetRow('SELECT ADDRESSID FROM address WHERE address = ?', [trim(strtolower($params['location']))]);
				if ($row)
				{
					$params['ADDRESSID'] = $row['ADDRESSID'];
				}
			}
			else
			{
				$address = new Address(['address' => $params['location']]);
				if ($address->isInitialized())
				{
					$params['ADDRESSID'] = $address['ADDRESSID'];
				}
			}
		}

		return parent::add($params);
	}

	public static function getMonthlyTotals(): array
	{
		global $db;
		$res = $db->Execute("
			SELECT DATE_FORMAT(call_time, '%Y-%m') AS mo, COUNT(*) AS n
			FROM cad_call
			WHERE call_time >= '2025-10-01'
			GROUP BY mo
			ORDER BY mo
		");
		$totals = [];
		foreach ($res as $row) $totals[$row['mo']] = (int)$row['n'];
		return $totals;
	}

	public static function getDailyTotals(string $yearMonth): array
	{
		global $db;
		$start = $yearMonth.'-01';
		$end   = date('Y-m-d', strtotime($start.' +1 month'));
		$res = $db->Execute("
			SELECT DAY(call_time) AS d, COUNT(*) AS n
			FROM cad_call
			WHERE call_time >= ? AND call_time < ?
			GROUP BY d ORDER BY d
		", [$start, $end]);
		$totals = [];
		foreach ($res as $row) $totals[(int)$row['d']] = (int)$row['n'];
		return $totals;
	}

	public static function getTopTypes(string $yearMonth, int $limit = 12): array
	{
		global $db;
		$start = $yearMonth.'-01';
		$end   = date('Y-m-d', strtotime($start.' +1 month'));
		$res = $db->Execute("
			SELECT call_type, COUNT(*) AS n
			FROM cad_call
			WHERE call_time >= ? AND call_time < ?
			GROUP BY call_type ORDER BY n DESC LIMIT ?
		", [$start, $end, $limit]);
		$types = [];
		foreach ($res as $row) $types[$row['call_type']] = (int)$row['n'];
		return $types;
	}

	public static function getMonthTotal(string $yearMonth): int
	{
		global $db;
		$start = $yearMonth.'-01';
		$end   = date('Y-m-d', strtotime($start.' +1 month'));
		return (int)$db->GetOne("SELECT COUNT(*) FROM cad_call WHERE call_time >= ? AND call_time < ?", [$start, $end]);
	}

	public static function getTopAddresses(string $yearMonth, int $limit = 10): array
	{
		global $db;
		$start = $yearMonth.'-01';
		$end   = date('Y-m-d', strtotime($start.' +1 month'));
		$res = $db->Execute("
			SELECT
				CASE
					WHEN a.ADDRESSID IS NOT NULL AND a.name != '' THEN CONCAT(a.name, ' (', a.address, ')')
					WHEN a.ADDRESSID IS NOT NULL THEN a.address
					ELSE c.location
				END AS display_name,
				COUNT(*) AS n
			FROM cad_call c
			LEFT JOIN address a USING(ADDRESSID)
			WHERE c.call_time >= ? AND c.call_time < ?
			GROUP BY display_name
			ORDER BY n DESC
			LIMIT ?
		", [$start, $end, $limit]);
		$addrs = [];
		foreach ($res as $row) $addrs[self::formatAddress($row['display_name'])] = (int)$row['n'];
		return $addrs;
	}

	public static function getCallsForMonth(string $yearMonth): array
	{
		global $db;
		$start = $yearMonth.'-01';
		$end   = date('Y-m-d', strtotime($start.' +1 month'));
		$res = $db->Execute("
			SELECT
				c.incident, c.call_time, c.call_type,
				CASE
					WHEN a.ADDRESSID IS NOT NULL AND a.name != '' THEN CONCAT(a.name, ' (', a.address, ')')
					WHEN a.ADDRESSID IS NOT NULL THEN a.address
					ELSE c.location
				END AS display_location
			FROM cad_call c
			LEFT JOIN address a USING(ADDRESSID)
			WHERE c.call_time >= ? AND c.call_time < ?
			ORDER BY c.call_time DESC
		", [$start, $end]);
		$calls = [];
		foreach ($res as $row) {
			$row['display_location'] = self::formatAddress($row['display_location']);
			$calls[] = $row;
		}
		return $calls;
	}

	public static function getAll()
	{
		global $db;
		// only have full cad logs going back to oct 2025, don't taint the data with older records from just two addresses
		$res = $db->Execute('
			select *
			from cad_call
			left join address using(ADDRESSID)
			where call_time > "2025-10-01"
			order by incident
		');
		$calls = [];
		foreach ($res as $cur)
		{
			$calls[] = new CADCall(['record' => $cur]);
		}

		return $calls;
	}

	public function get($offset)
	{
		//fixme: dynamically fetch these if needed
		if ($offset == 'lat' || $offset == 'lng')
		{
			return $this->record[$offset];
		}
		return parent::get($offset);;
	}
}
