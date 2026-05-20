<?php
require(__DIR__.'/../init.php');
$vars = [
	'board' => [
		'name'         => 'Planning Board',
		'type'         => 'planning',
		'official_url' => 'https://www.piscatawaynj.org/departments/community_development/planning_division/planning_board.php',
		'members'      => [
			['name' => 'Brian Wahler',           'title' => 'Class I – Mayor',                  'term' => '01/01/25–12/31/28'],
			['name' => 'Dawn Corcoran-Gardella',  'title' => 'Class II',                         'term' => '01/01/26–12/31/26'],
			['name' => 'Gabrielle Cahill',        'title' => 'Class III – At-Large Councilmember', 'term' => '01/01/26–12/31/26'],
			['name' => 'Michael Foster',          'title' => '',                                 'term' => '01/01/26–12/31/29'],
			['name' => 'Rev. Henry Kenney',       'title' => '',                                 'term' => '01/01/25–12/31/28'],
			['name' => 'Carol Saunders',          'title' => 'Class IV',                         'term' => '01/01/25–12/31/28'],
			['name' => 'Brenda Smith',            'title' => 'Class IV',                         'term' => '01/01/25–12/31/28'],
			['name' => 'Alex Adkins',             'title' => 'Class IV',                         'term' => '01/01/24–12/31/27'],
			['name' => 'E. Basheer Ahammed',      'title' => '',                                 'term' => '01/01/25–12/31/28'],
			['name' => 'Philip Echevarria',       'title' => 'Alternate #1',                     'term' => '01/01/25–12/31/26'],
		],
	],
	'meetings' => Meeting::getUpcomingAndOlderByType('planning'),
];
displayPage('board.html', $vars);
