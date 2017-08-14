<?php

/**
 * Ushahidi Settings
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi\Application\Config
 * @copyright  2013 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

return array(
	'default_providers' => array(
		Ushahidi\App\DataSource\Message\Type::SMS => 'smssync',
		Ushahidi\App\DataSource\Message\Type::IVR => false,
		Ushahidi\App\DataSource\Message\Type::EMAIL => 'email',
		Ushahidi\App\DataSource\Message\Type::TWITTER => 'twitter'
	),
	'providers' => array(
		// List of data providers key=provider value=enabled
		'smssync' => false,
		'email' => false,
		'twilio' => false,
		'nexmo' => false,
		'twitter' => false,
		'frontlinesms' => false
	),

	// Config params for individual providers
	// 'nexmo' => array(
	// 	'from' => '',
	// 	'api_key' => '',
	// 	'api_secret' => ''
	// ),

	// // 'twilio' => array(
	// 	'from' => '',
	// 	'account_sid' => '',
	// 	'auth_token' => '',
	// 	'sms_auto_response' => ''
	// ),

	// 'smssync' => array(
	// 	'from' => '12345',
	// 	'secret' => '1234'
	// ),

	'email' => array(
	// 	'from' => '',
	// 	'from_name' => '',

		'incoming_type' => '',
		'incoming_server' => '',
		'incoming_port' => '',
		'incoming_security' => '',
		'incoming_username' => '',
		'incoming_password' => '',
	),

	'twilio'       => [],
	'smssync'      => [],
	'twitter'      => [],
	'nexmo'        => [],
	'frontlinesms' => [],
);
