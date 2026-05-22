<?php
require(__DIR__.'/../init.php');
$vars = [
	'board' => [
		'name'         => 'Zoning Board of Adjustment',
		'type'         => 'zoning',
		'official_url' => 'https://www.piscatawaynj.org/departments/community_development/planning_division/zoning_board_of_adjustment.php',
		'members'      => [
			['name' => 'Shawn Cahill',        'title' => 'Chair & Secretary', 'term' => '01/01/23–12/31/26'],
			['name' => 'Roy O\'Reggio',        'title' => '',                  'term' => '01/01/26–12/31/29'],
			['name' => 'Kalpesh Patel',        'title' => '',                  'term' => '01/01/24–12/31/27'],
			['name' => 'Jeffrey Tillery',      'title' => '',                  'term' => '01/01/23–12/31/26'],
			['name' => 'Steven Weisman',       'title' => '',                  'term' => '01/01/24–12/31/27'],
			['name' => 'Rodney Blount',        'title' => '',                  'term' => '01/01/25–12/31/28'],
			['name' => 'Alternate #1: VACANT', 'title' => '',                  'term' => ''],
			['name' => 'William Mitterando',   'title' => 'Alternate #2',      'term' => '01/01/25–12/31/26'],
			['name' => 'Waqar Ali',            'title' => 'Alternate #3',      'term' => '01/01/25–12/31/26'],
			['name' => 'Laura Buckley',        'title' => 'Recording Secretary', 'term' => ''],
			['name' => 'James Kinneally, III Esq.', 'title' => 'Zoning Board Attorney', 'term' => ''],
		],
	],
	'meetings' => Meeting::getUpcomingAndOlderByType('zoning'),
];
displayPage('board.html', $vars);
