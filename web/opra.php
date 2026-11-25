<?php
require(__DIR__.'/../init.php');

if (!hasEditAuth())
{
	http_response_code(403);
	die('Access Denied');
}

$fields = OpraRequest::lastFields();
$fields['request'] = '';

if (($_POST['action'] ?? '') == 'submit')
{
	[$fields, $error] = OpraRequest::cleanInput($_POST);
	if (!$error)
	{
		[$req, $error] = OpraRequest::submit($fields, $_POST['signature_image'] ?? '');
		if (!$error)
		{
			displaySuccess('Submitted, reference number '.($req['reference_number'] ?? '?').' (request '.$req['request_id'].')', '/opra.php');
		}
		$error .= ' — saved locally as #'.($req['OPRAID'] ?? '?');
	}
	// fall through and redisplay with what was typed
}

$requestors = array_map(fn($r) => $r['first_name'].' '.$r['last_name'], OpraRequest::requestors());
if (!$requestors)
{
	$error = 'No requestors configured: define OPRA_REQUESTORS in config.php';
}

displayPage('opra.html', [
	'fields'   => $fields,
	'requestors' => $requestors,
	'choices'  => OpraRequest::CHOICES,
	'requests' => OpraRequest::getAll(),
	'error_message' => $error ?? '',
]);
