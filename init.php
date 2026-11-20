<?php
ini_set('date.timezone', 'America/New_York');
require_once(__DIR__.'/vendor/autoload.php');

$loader = new \Twig\Loader\FilesystemLoader(__DIR__.'/templates/');

$twig = new \Twig\Environment($loader, [
	'cache' => false,
	'auto_reload' => true,
	'strict_variables' => true,
	'autoescape' => 'html',
]);
$twig->addFilter(new \Twig\TwigFilter('name_case', 'CampaignFile::nameCase'));

spl_autoload_register(function ($class_name) {
	$class_name = basename($class_name);
	$path = __DIR__.'/classes/'.$class_name.'.php';
	if (file_exists($path))
	{
		require_once($path);
	}
});

require_once(__DIR__.'/config.php');

define('PISCATAWAY_UID', '1596769944');
define('DICTIONARY_FILE', __DIR__.'/vocabulary.txt');
define('BASE_FILE_PATH', __DIR__.'/web/files/');

define('ONE_HOUR', 60 * 60);
define('ONE_DAY', 24 * ONE_HOUR);

require_once(__DIR__.'/vendor/adodb/adodb-php/adodb.inc.php');
$db = newAdoConnection('mysqli');
$db->connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

define('DEFAULT_CACHE_TTL', 8 * ONE_HOUR);
define('SHORT_CACHE_TTL', 10 * 60);

// Sessions only carry flash messages, so only start one when a cookie exists or a message is set.
// Otherwise every response gets Set-Cookie + no-store and nothing can be cached.
session_cache_limiter('');
if (isset($_COOKIE[session_name()]))
{
	session_start();
}

function hasEditAuth(): bool
{
	return stripos($_SERVER['REMOTE_ADDR'], '192.168.5.') !== false;
}

/**
*	Send cache headers. Anything per-user (edit UI, flash messages) or $ttl <= 0 is never cached.
*/
function cacheFor(int $ttl)
{
	if ($ttl <= 0 || hasEditAuth() || session_status() == PHP_SESSION_ACTIVE)
	{
		header('Cache-Control: private, no-store');
		return;
	}
	header('Cache-Control: public, max-age='.$ttl);
	header('Expires: '.gmdate('D, d M Y H:i:s', time() + $ttl).' GMT');
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

	if (!preg_grep('/^Cache-Control:/i', headers_list()))
	{
		cacheFor(DEFAULT_CACHE_TTL);
	}

	echo $twig->render($template, $vars);
}

function displaySuccess(string $message, string $redirect='')
{
	if (session_status() != PHP_SESSION_ACTIVE)
	{
		session_start();
	}
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
	if (session_status() != PHP_SESSION_ACTIVE)
	{
		session_start();
	}
	if ($redirect != '')
	{
		$_SESSION['error_message'] = $message;
		Header('Location: '.$redirect);
		exit();
	}

	$_SESSION['error_message'] = $message;
}
