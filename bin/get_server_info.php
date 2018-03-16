<?php
/**
* Plesk Get Server Info
*
* @author Joe Huss <detain@interserver.net>
* @package MyAdmin
* @category Scripts
* @copyright 2018
*/
include_once __DIR__.'/../../../../include/functions.inc.php';

use Detain\MyAdminPlesk\ApiRequestException;


function_requirements('get_webhosting_plesk_instance');
$plesk = get_webhosting_plesk_instance((isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : FALSE));

try {
	$result = $plesk->getServerInfo();
} catch (ApiRequestException $e) {
	echo 'Exception Error: '.$e->getMessage();
	print_r($e);
	die();
}
echo $plesk->varExport($result);
