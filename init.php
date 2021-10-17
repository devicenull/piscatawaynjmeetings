<?php
require_once(__DIR__.'/vendor/autoload.php');

$loader = new \Twig\Loader\FilesystemLoader(__DIR__.'/templates/');

$twig = new \Twig\Environment($loader, [
	'cache' => false,
	'auto_reload' => true,
	'strict_variables' => true,
	'autoescape' => 'html',
]);

require_once(__DIR__.'/classes/BaseDBObject.php');
require_once(__DIR__.'/classes/Meeting.php');
require_once(__DIR__.'/classes/Newsletter.php');
require_once(__DIR__.'/classes/MiscFile.php');
require_once(__DIR__.'/classes/Bid.php');
require_once(__DIR__.'/classes/Tweet.php');
require_once(__DIR__.'/classes/YouTube.php');
require_once(__DIR__.'/classes/ArchiveOrg.php');

require_once(__DIR__.'/config.php');

define('PISCATAWAY_UID', '1596769944');

require_once(__DIR__.'/vendor/adodb/adodb-php/adodb.inc.php');
$db = newAdoConnection('mysqli');
$db->connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

session_start();

function hasEditAuth(): bool
{
	return stripos($_SERVER['REMOTE_ADDR'], '192.168.5.') !== false;
}

function displayPage($template, $vars=[])
{
	global $twig;

	$vars['has_edit_auth'] = hasEditAuth();

	if (isset($_SESSION['success_message']))
	{
		$vars['success_message'] = $_SESSION['success_message'];
		unset($_SESSION['success_message']);
	}

	if (isset($_SESSION['error_message']))
	{
		$vars['error_message'] = $_SESSION['error_message'];
		unset($_SESSION['error_message']);
	}

	echo $twig->render($template, $vars);
}

function displaySuccess(string $message, string $redirect='')
{
	if ($redirect != '')
	{
		$_SESSION['success_message'] = $message;
		Header('Location: '.$redirect);
		exit();
	}

	$_SESSION['success_message'] = $message;
}

function displayError(string $message, string $redirect='')
{
	if ($redirect != '')
	{
		$_SESSION['error_message'] = $message;
		Header('Location: '.$redirect);
		exit();
	}

	$_SESSION['error_message'] = $message;
}
