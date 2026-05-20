<?php
require(__DIR__.'/../init.php');
$vars = [
	'board' => [
		'name'         => 'Township Council',
		'type'         => 'council',
		'official_url' => 'https://www.piscatawaynj.org/government/elected_officials/index.php',
		'members'      => [
			['name' => 'Brian C. Wahler',    'title' => 'Mayor',                              'term' => ''],
			['name' => 'Michele Lombardi',   'title' => 'Council President (Ward 4)',         'term' => ''],
			['name' => 'Sharon Carmichael',  'title' => 'Council Vice President (Ward 3)',    'term' => ''],
			['name' => 'Gabrielle Cahill',   'title' => 'At-Large Councilmember',             'term' => ''],
			['name' => 'Laura Leibowitz',    'title' => 'At-Large Councilmember',             'term' => ''],
			['name' => 'Sarah Rashid',       'title' => 'At-Large Councilmember',             'term' => ''],
			['name' => 'Frank Uhrin',        'title' => 'Ward 1 Councilmember',               'term' => ''],
			['name' => 'Dennis Espinosa',    'title' => 'Ward 2 Councilmember',               'term' => ''],
		],
	],
	'meetings' => Meeting::getUpcomingAndOlderByType('council'),
];
displayPage('board.html', $vars);
