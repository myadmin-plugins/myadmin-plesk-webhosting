<?php
/**
* Plesk List Clients
*
* @author Joe Huss <detain@interserver.net>
* @package MyAdmin
* @subpackage Scripts
* @copyright 2017
*/
include_once(__DIR__.'/../../../../include/functions.inc.php');

$plesk = get_webhosting_plesk_instance((isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : FALSE));

try {
	$result = $plesk->listClients();
} catch (ApiRequestException $e) {
	echo "Exception Error: ".$e;
	print_r($e);
	die();
}
echo $plesk->varExport($result);
