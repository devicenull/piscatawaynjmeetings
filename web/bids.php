<?php
require(__DIR__.'/../init.php');
$vars = [
	'bids' => Bid::getAll(),
];
displayPage('bids.html', $vars);
