<?php

namespace Detain\MyAdminPlesk;

use Detain\MyAdminPlesk\Plesk;
use Symfony\Component\EventDispatcher\GenericEvent;

class Plugin {

	public static $name = 'Plesk Webhosting';
	public static $description = 'Allows selling of Plesk Server and VPS License Types.  More info at https://www.netenberg.com/plesk.php';
	public static $help = 'It provides more than one million end users the ability to quickly install dozens of the leading open source content management systems into their web space.  	Must have a pre-existing cPanel license with cPanelDirect to purchase a plesk license. Allow 10 minutes for activation.';
	public static $module = 'webhosting';
	public static $type = 'service';


	public function __construct() {
	}

	public static function getHooks() {
		return [
			self::$module.'.settings' => [__CLASS__, 'getSettings'],
			self::$module.'.activate' => [__CLASS__, 'getActivate'],
			self::$module.'.reactivate' => [__CLASS__, 'getReactivate'],
		];
	}

	public static function getActivate(GenericEvent $event) {
		$service = $event->getSubject();
		if ($event['category'] == SERVICE_TYPES_WEB_PLESK) {
			myadmin_log(self::$module, 'info', 'Plesk Activation', __LINE__, __FILE__);
			$serviceInfo = $service->getServiceInfo();
			$settings = get_module_settings(self::$module);
			$serverdata = get_service_master($serviceInfo[$settings['PREFIX'].'_server'], self::$module);
			$hash = $serverdata[$settings['PREFIX'].'_key'];
			$ip = $serverdata[$settings['PREFIX'].'_ip'];
			$extra = run_event('parse_service_extra', $serviceInfo[$settings['PREFIX'].'_extra'], self::$module);
			$hostname = $serviceInfo[$settings['PREFIX'].'_hostname'];
			if (trim($hostname) == '')
				$hostname = $serviceInfo[$settings['PREFIX'].'_id'] . '.server.com';
			$password = website_get_password($serviceInfo[$settings['PREFIX'].'_id']);
			$username = get_new_webhosting_username($serviceInfo[$settings['PREFIX'].'_id'], $hostname, $serviceInfo[$settings['PREFIX'].'_server']);
			$data = $GLOBALS['tf']->accounts->read($serviceInfo[$settings['PREFIX'].'_custid']);
			$debugCalls = FALSE;
			if (!is_array($extra))
				$extra = [];
			$plesk = get_webhosting_plesk_instance($serverdata);
			/**
			 * Gets the Shared IP Address
			 */
			$result = $plesk->list_ip_addresses();
			if ((!isset($result['ips'][0]['ip_address']) && !isset($result['ips']['ip_address'])) || $result['status'] == 'error')
				throw new Exception('Failed getting server information.'.(isset($result['errtext']) ? ' Error message was: '.$result['errtext'].'.' : ''));
			if ($debugCalls == TRUE)
				echo "plesk->list_ip_adddresses() = ".var_export($result, TRUE).PHP_EOL;
			if (isset($result['ips']['ip_address']))
				$sharedIp = $result['ips']['ip_address'];
			else
				foreach ($result['ips'] as $idx => $ip_data)
					if (trim($ip_data['type']) == 'shared' && (!isset($sharedIp) || $ip_data['is_default']))
						$sharedIp = $ip_data['ip_address'];
			if (!isset($sharedIp)) {
				myadmin_log(self::$module, 'critical', 'Plesk Could not find any shared IP addresses', __LINE__, __FILE__);
				return FALSE;
			}
			/**
			 * Gets the Service Plans and finds the one matching the desired parameters
			 */
			try {
				$result = $plesk->list_service_plans();
			} catch (\ApiRequestException $e) {
				myadmin_log(self::$module, 'info', 'list_service_plans Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
				return FALSE;
			}
			if ($debugCalls == TRUE)
				echo "plesk->list_service_plans() = ".var_export($result, TRUE).PHP_EOL;
			foreach ($result as $idx => $plan) {
				if ($plan['name'] == 'ASP.NET plan') {
					$plan_id = $plan['id'];
					break;
				}
			}
			if (!isset($plan_id)) {
				myadmin_log(self::$module, 'critical', 'Plesk Could not find the appropriate service plan');
				return FALSE;
			}
			/**
			 * Creates a Client in with Plesk
			 */
			if (!isset($data['name']) || trim($data['name']) == '') {
				$data['name'] = str_replace('@', ' ', $data['account_lid']);
			}
			$request = [
				'name' => $data['name'],
				'username' => $username,
				'password' => $password
			];
			try {
				myadmin_log(self::$module, 'DEBUG', 'create_client called with '.json_encode($request), __LINE__, __FILE__);
				$result = $plesk->create_client($request);
			} catch (\ApiRequestException $e) {
				$error = $e->getMessage();
				myadmin_log(self::$module, 'info', 'create_client Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
			}
			if (!isset($result['id'])) {
				$cantFix = FALSE;
				$password_updated = FALSE;
				while ($cantFix == FALSE && !isset($result['id'])) {
					if (mb_strpos($error, 'The password should') !== FALSE) {
						// Error #2204 System user setting was failed. Error: The password should be  4 - 255 characters long and should not contain the username. Do not use quotes, spaces, and national alphabetic characters in the password.
						$password_updated = TRUE;
						$password = Plesk::randomString(16);
						$request['password'] = $password;
						myadmin_log(self::$module, 'info', "Generated '{$request['password']}' for a replacement password and trying again", __LINE__, __FILE__);
						try {
							myadmin_log(self::$module, 'DEBUG', 'create_client called with '.json_encode($request), __LINE__, __FILE__);
							$result = $plesk->create_client($request);
						} catch (\ApiRequestException $e) {
							$error = $e->getMessage();
							myadmin_log(self::$module, 'info', 'create_client Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
						}
					} elseif (mb_strpos($error, 'Error #1007') !== FALSE) {
						// Error #1007 User account  already exists.
						$usernameUpdated = TRUE;
						$username = mb_substr($username, 0, 7).strtolower(Plesk::randomString(1));
						$request['username'] = $username;
						myadmin_log(self::$module, 'info', "Generated '{$request['username']}' for a replacement username and trying again", __LINE__, __FILE__);
						try {
							myadmin_log(self::$module, 'DEBUG', 'create_client called with '.json_encode($request), __LINE__, __FILE__);
							$result = $plesk->create_client($request);
						} catch (\ApiRequestException $e) {
							$error = $e->getMessage();
							myadmin_log(self::$module, 'info', 'create_client Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
						}
					} else
						$cantFix = TRUE;
				}
				if ($password_updated == TRUE) {
					$GLOBALS['tf']->history->add($settings['PREFIX'], 'password', $serviceInfo[$settings['PREFIX'].'_id'], $options['password']);
				}
			}
			request_log(self::$module, $serviceInfo[$settings['PREFIX'].'_custid'], __FUNCTION__, 'plesk', 'create_client', $request, $result);
			if (!isset($result['id'])) {
				//myadmin_log(self::$module, 'info', 'create_client did not return the expected id information: '.$e->getMessage(), __LINE__, __FILE__);
				myadmin_log(self::$module, 'info', 'create_client did not return the expected id', __LINE__, __FILE__);
				if (is_array($extra) && isset($extra[0]) && is_numeric($extra[0]) && $extra[0] > 0) {
					myadmin_log(self::$module, 'info', 'continuing using pre-existing client id', __LINE__, __FILE__);
					$account_id = $extra[0];
				} else {
					return FALSE;
				}
			} else {
				$account_id = $result['id'];
			}
			//$ftp_login = 'ftpuser'.$serviceInfo[$settings['PREFIX'].'_id'];
			$ftp_login = 'ftp'.Plesk::randomString(9);
			//$ftp_login = 'ftp'.str_replace('.',''), array('',''), $hostname);
			//$ftp_password = Plesk::randomString(16);
			$ftp_password = generateRandomString(10, 2, 1, 1, 1);
			while (mb_strpos($ftp_password, '&') !== FALSE)
				$ftp_password = generateRandomString(10, 2, 1, 1, 1);
			$extra[0] = $account_id;
			$ser_extra = $db->real_escape(myadmin_stringify($extra));
			$db->query("update {$settings['TABLE']} set {$settings['PREFIX']}_ip='{$ip}', {$settings['PREFIX']}_extra='{$ser_extra}' where {$settings['PREFIX']}_id='{$serviceInfo[$settings['PREFIX'].'_id']}'", __LINE__, __FILE__);
			myadmin_log(self::$module, 'info', "create_client got client id {$account_id}", __LINE__, __FILE__);
			//$plesk->debug = TRUE;
			//$debugCalls = TRUE;
			$request = array(
				'domain' => $hostname,
				'owner_id' => $account_id,
				'htype' => 'vrt_hst',
				'ftp_login' => $username,
				'ftp_password' => $ftp_password,
				'ip' => $ip,
				'status' => 0,
				'plan_id' => $plan_id,
			);
			$result = [];
			try {
				myadmin_log(self::$module, 'info', 'createSubscription called with '.json_encode($request), __LINE__, __FILE__);
				$result = $plesk->createSubscription($request);
			} catch (\ApiRequestException $e) {
				myadmin_log(self::$module, 'warning', ' createSubscription Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
				try {
					myadmin_log(self::$module, 'info', 'deleteClient called with '.json_encode($request), __LINE__, __FILE__);
					$result = $plesk->deleteClient(['login' => $username]);
				} catch (\ApiRequestException $e) {
					$error = $e->getMessage();
					myadmin_log(self::$module, 'warning', 'deleteClient Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
				}
				return FALSE;
			}

			if (!isset($result['id'])) {
				$cantFix = FALSE;
				$usernameUpdated = FALSE;
				while ($cantFix == FALSE && !isset($result['id'])) {
					// Error #1007 User account  already exists.
					if (mb_strpos($error, 'Error #1007') !== FALSE) {
						$usernameUpdated = TRUE;
						$username = mb_substr($username, 0, 7).strtolower(Plesk::randomString(1));
						$request['ftp_login'] = $username;
						myadmin_log(self::$module, 'info', "Generated '{$request['ftp_login']}' for a replacement username and trying again", __LINE__, __FILE__);
						try {
							myadmin_log(self::$module, 'DEBUG', 'createSubscription called with '.json_encode($request), __LINE__, __FILE__);
							$result = $plesk->createSubscription($request);
						} catch (ApiRequestException $e) {
							$error = $e->getMessage();
							myadmin_log(self::$module, 'info', 'create_client Caught exception: '.$e->getMessage(), __LINE__, __FILE__);
						}
					} else
						$cantFix = TRUE;
				}
			}
			request_log(self::$module, $serviceInfo[$settings['PREFIX'].'_custid'], __FUNCTION__, 'plesk', 'createSubscription', $request, $result);
			if (!isset($result['id'])) {
				myadmin_log(self::$module, 'info', 'createSubscription did not return the expected id information: '.$e->getMessage(), __LINE__, __FILE__);
				return FALSE;
			}
			$subscriptoinId = $result['id'];
			$extra[1] = $subscriptoinId;
			$ser_extra = $db->real_escape(myadmin_stringify($extra));
			$db->query("update {$settings['TABLE']} set {$settings['PREFIX']}_ip='{$ip}', {$settings['PREFIX']}_extra='{$ser_extra}', {$settings['PREFIX']}_username='{$username}' where {$settings['PREFIX']}_id='{$serviceInfo[$settings['PREFIX'].'_id']}'", __LINE__, __FILE__);
			if ($debugCalls == TRUE)
				echo "plesk->createSubscription(".var_export($request, TRUE).") = ".var_export($result, TRUE).PHP_EOL;
			myadmin_log(self::$module, 'info', "createSubscription got Subscription ID {$subscriptoinId}\n", __LINE__, __FILE__);
			if (is_numeric($subscriptoinId)) {
				website_welcome_email($serviceInfo[$settings['PREFIX'].'_id']);
			}
			$event->stopPropagation();
		}
	}

	public static function getReactivate(GenericEvent $event) {
		$service = $event->getSubject();
		if ($event['category'] == SERVICE_TYPES_WEB_PLESK) {
			$serviceInfo = $service->getServiceInfo();
			$settings = get_module_settings(self::$module);
			$serverdata = get_service_master($serviceInfo[$settings['PREFIX'].'_server'], self::$module);
			$hash = $serverdata[$settings['PREFIX'].'_key'];
			$ip = $serverdata[$settings['PREFIX'].'_ip'];
			$extra = run_event('parse_service_extra', $serviceInfo[$settings['PREFIX'].'_extra'], self::$module);
			function_requirements('get_webhosting_plesk_instance');
			$plesk = get_webhosting_plesk_instance($serverdata);
			list($user_id, $subscriptoinId) = $extra;
			$request = ['username' => $serviceInfo[$settings['PREFIX'].'_username'], 'status' => 0];
			try {
				$result = $plesk->update_client($request);
			} catch (\Exception $e) {
				echo 'Caught exception: '.$e->getMessage().PHP_EOL;
			}
			myadmin_log(self::$module, 'info', 'update_client Called got '.json_encode($result), __LINE__, __FILE__);
			$event->stopPropagation();
		}
	}

	public static function getChangeIp(GenericEvent $event) {
		if ($event['category'] == SERVICE_TYPES_WEB_PLESK) {
			$license = $event->getSubject();
			$settings = get_module_settings(self::$module);
			$plesk = new Plesk(FANTASTICO_USERNAME, FANTASTICO_PASSWORD);
			myadmin_log(self::$module, 'info', "IP Change - (OLD:".$license->get_ip().") (NEW:{$event['newip']})", __LINE__, __FILE__);
			$result = $plesk->editIp($license->get_ip(), $event['newip']);
			if (isset($result['faultcode'])) {
				myadmin_log(self::$module, 'error', 'Plesk editIp('.$license->get_ip().', '.$event['newip'].') returned Fault '.$result['faultcode'].': '.$result['fault'], __LINE__, __FILE__);
				$event['status'] = 'error';
				$event['status_text'] = 'Error Code '.$result['faultcode'].': '.$result['fault'];
			} else {
				$GLOBALS['tf']->history->add($settings['TABLE'], 'change_ip', $event['newip'], $license->get_ip());
				$license->set_ip($event['newip'])->save();
				$event['status'] = 'ok';
				$event['status_text'] = 'The IP Address has been changed.';
			}
			$event->stopPropagation();
		}
	}

	public static function getMenu(GenericEvent $event) {
		$menu = $event->getSubject();
		if ($GLOBALS['tf']->ima == 'admin') {
			$menu->add_link(self::$module, 'choice=none.reusable_plesk', 'icons/database_warning_48.png', 'ReUsable Plesk Licenses');
			$menu->add_link(self::$module, 'choice=none.plesk_list', 'icons/database_warning_48.png', 'Plesk Licenses Breakdown');
			$menu->add_link(self::$module.'api', 'choice=none.plesk_licenses_list', 'whm/createacct.gif', 'List all Plesk Licenses');
		}
	}

	public static function getRequirements(GenericEvent $event) {
		$loader = $event->getSubject();
		$loader->add_requirement('crud_plesk_list', '/../vendor/detain/crud/src/crud/crud_plesk_list.php');
		$loader->add_requirement('crud_reusable_plesk', '/../vendor/detain/crud/src/crud/crud_reusable_plesk.php');
		$loader->add_requirement('get_plesk_licenses', '/../vendor/detain/myadmin-plesk-webhosting/src/plesk.inc.php');
		$loader->add_requirement('get_plesk_list', '/../vendor/detain/myadmin-plesk-webhosting/src/plesk.inc.php');
		$loader->add_requirement('plesk_licenses_list', '/../vendor/detain/myadmin-plesk-webhosting/src/plesk_licenses_list.php');
		$loader->add_requirement('plesk_list', '/../vendor/detain/myadmin-plesk-webhosting/src/plesk_list.php');
		$loader->add_requirement('get_available_plesk', '/../vendor/detain/myadmin-plesk-webhosting/src/plesk.inc.php');
		$loader->add_requirement('activate_plesk', '/../vendor/detain/myadmin-plesk-webhosting/src/plesk.inc.php');
		$loader->add_requirement('get_reusable_plesk', '/../vendor/detain/myadmin-plesk-webhosting/src/plesk.inc.php');
		$loader->add_requirement('reusable_plesk', '/../vendor/detain/myadmin-plesk-webhosting/src/reusable_plesk.php');
		$loader->add_requirement('class.Plesk', '/../vendor/detain/plesk-webhosting/src/Plesk.php');
		$loader->add_requirement('vps_add_plesk', '/vps/addons/vps_add_plesk.php');
	}

	public static function getSettings(GenericEvent $event) {
		$settings = $event->getSubject();
		$settings->add_select_master(self::$module, 'Default Servers', self::$module, 'new_website_plesk_server', 'Default Plesk Setup Server', NEW_WEBSITE_PLESK_SERVER, SERVICE_TYPES_WEB_PLESK);
		$settings->add_dropdown_setting(self::$module, 'Out of Stock', 'outofstock_webhosting_plesk', 'Out Of Stock Plesk Webhosting', 'Enable/Disable Sales Of This Type', $settings->get_setting('OUTOFSTOCK_WEBHOSTING_PLESK'), array('0', '1'), array('No', 'Yes',));
	}

}
