<?php

namespace Ushahidi\App\DataSource\Email;

/**
 * Email Data Provider
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    DataSource\Email
 * @copyright  2013 Ushahidi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU General Public License Version 3 (GPLv3)
 */

use Ushahidi\App\DataSource\DataSource;
use Ushahidi\App\DataSource\Message\Type as MessageType;
use Shadowhand\Email as ShadowhandEmail;
use Ushahidi\Core\Entity\Contact;

class Email implements DataSource {

	protected $config;

	/**
	 * Constructor function for DataSource
	 */
	public function __construct(array $config)
	{
		$this->config = $config;
	}

	public function getName() {
		return 'Email';
	}

	public function getServices()
	{
		return [MessageType::EMAIL];
	}

	public function getOptions()
	{
		return array(
			'intro_text' => array(
				'label' => '',
				'input' => 'read-only-text',
				'description' => 'In order to receive reports by email, please input your email account settings below'
			),
			'incoming_type' => array(
				'label' => 'Incoming Server Type',
				'input' => 'radio',
				'description' => '',
				'options' => array('POP', 'IMAP'),
				'rules' => array('required', 'number')
			),
			'incoming_server' => array(
				'label' => 'Incoming Server',
				'input' => 'text',
				'description' => '',
				'description' => 'Examples: mail.yourwebsite.com, imap.gmail.com, pop.gmail.com',
				'rules' => array('required')
			),
			'incoming_port' => array(
				'label' => 'Incoming Server Port',
				'input' => 'text',
				'description' => 'Common ports: 110 (POP3), 143 (IMAP), 995 (POP3 with SSL), 993 (IMAP with SSL)',
				'rules' => array('required','number')
			),
			'incoming_security' => array(
				'label' => 'Incoming Server Security',
				'input' => 'radio',
				'description' => '',
				'options' => array('None', 'SSL', 'TLS')
			),
			'incoming_username' => array(
				'label' => 'Incoming Username',
				'input' => 'text',
				'description' => '',
				'placeholder' => 'Email account username',
				'rules' => array('required')
			),
			'incoming_password' => array(
				'label' => 'Incoming Password',
				'input' => 'text',
				'description' => '',
				'placeholder' => 'Email account password',
				'rules' => array('required')
			),
			// 'from' => array(
			// 	'label' => 'Email Address',
			// 	'input' => 'text',
			// 	'description' => 'This will be used to send outgoing emails',
			// 	'rules' => array('required')
			// ),
			// 'from_name' => array(
			// 	'label' => 'Email Sender Name',
			// 	'input' => 'text',
			// 	'description' => 'Appears in the \'from:\' field on outgoing emails',
			// 	'rules' => array('required')
			// ),
		);
	}

	/**
	 * Contact type user for this provider
	 */
	public $contact_type = Contact::EMAIL;

	/**
	 * @return mixed
	 */
	public function send($to, $message, $title = "")
	{
		// Always try to send emails!
		// if (!$this->_is_provider_available()) {
		//    Kohana::$log->add(Log::ERROR, 'The email data source is not currently available. It can be accessed by upgrading to a higher Ushahidi tier.');
		// 		return array(DataSource\Message\Status::FAILED, FALSE);
		// }

		$provider_options = $this->options();

		$driver = $provider_options['outgoing_type'];
		$options = array(
			'hostname' => $provider_options['outgoing_server'],
			'port' => $provider_options['outgoing_port'],
			'encryption' =>
				($provider_options['outgoing_security'] != 'none') ?
				$provider_options['outgoing_security'] :
				'',
			'username' => $provider_options['outgoing_username'],
			'password' => $provider_options['outgoing_password']
			);

		$config = Kohana::$config->load('email');
		$config->set('driver', $driver);
		$config->set('options', $options);

		$tracking_id = self::tracking_id('email');

		$body = View::factory('email/layout');
		$body->message = $message;
		$body->tracking_id = $tracking_id;
		$body->site_url = rtrim(URL::site(), '/');

		$from = $this->from();

		if (!$from) {
			$from = Kohana::$config->load('site.email') ?: 'noreply@ushahididev.com';
		}

		$from_name = ! empty($provider_options['from_name']) ? $provider_options['from_name'] : $from;

		try
		{
			$result = ShadowhandEmail::factory($title, $body->render(), 'text/html')
				->to($to)
				->from($from, $from_name)
				->send();

			return array(DataSource\Message\Status::SENT, $tracking_id);
		}
		catch (Exception $e)
		{
			Kohana::$log->add(Log::ERROR, $e->getMessage());
			// Failed
			return array(DataSource\Message\Status::FAILED, FALSE);
		}

	}

	/**
	 * Fetch email messages from server
	 *
	 * For services where we have to poll for message (Twitter, Email, FrontlineSMS) this should
	 * poll the service and pass messages to $this->receive()
	 *
	 * @param  boolean $limit   maximum number of messages to fetch at a time
	 * @return int              number of messages fetched
	 */
	public function fetch($limit = FALSE)
	{
		if (!$this->_is_provider_available()) {
			Kohana::$log->add(Log::WARNING, 'The email data source is not currently available. It can be accessed by upgrading to a higher Ushahidi tier.');
			return 0;
		}

		$count = 0;

		$limit = 200;

		$options = $this->options();
		$type = $options['incoming_type'];
		$server = $options['incoming_server'];
		$port = $options['incoming_port'];
		$encryption = $options['incoming_security'];
		$username = $options['incoming_username'];
		$password = $options['incoming_password'];

		// Encryption type
		$encryption = (strcasecmp($encryption, 'none') != 0) ? '/'.$encryption : '';

		try
		{
			// Try to connect
			$connection = imap_open('{'.$server.':'.$port.'/'.$type.$encryption.'}INBOX', $username, $password);

			// Return on connection error
			if (! $connection)
			{
				Kohana::$log->add(Log::ERROR, "Could not connect to incoming email server");
				return 0;
			}

			$last_uid = service('repository.message')->getLastUID('email');
			$max_range = $last_uid + $limit;
			$search_string = $last_uid ? $last_uid + 1 . ':' . $max_range : '1:' . $max_range;

			$emails = imap_fetch_overview($connection, $search_string, FT_UID);

			if ($emails)
			{
				// reverse sort emails?
				//rsort($emails);
				foreach($emails as $email)
				{
					// Break out if we've hit our limit
					// @todo revist and decide if this is worth doing when imap_search has grabbed everything anyway.
					if ($limit AND $count >= $limit)
						break;

					$message = $html_message = "";
					$structure = imap_fetchstructure($connection, $email->uid, FT_UID);

					// Get HTML message from multipart message
					if (! empty($structure->parts))
					{
						$no_of_parts = count($structure->parts);

						foreach ($structure->parts as $part_number => $part)
						{
							if ($part->subtype == 'HTML')
							{
								$html_message .= imap_fetchbody($connection, $email->uid, $part_number, FT_UID);
							} elseif ($part->subtype == 'PLAIN') {
								$message .= imap_fetchbody($connection, $email->uid, $part_number, FT_UID);
							}
						}
					}
					else
					{
						// or just fetch the body if not a multipart message
						$message = imap_body($connection, $email->uid, FT_UID);
					}


					// Process the email
					if (! empty($html_message)) {
						$html_message = imap_qprint($html_message);
						$this->_process_incoming($email, $html_message);
					}
					elseif (! empty($message))
					{
						$message = imap_qprint($message);
						$this->_process_incoming($email, $message);
					}

					$count++;
				}
			}

			imap_errors();

			imap_close($connection);
		}
		catch (Exception $e)
		{
			$errors = imap_errors();
			$errors = is_array($errors) ? implode(', ', $errors) : "";
			Kohana::$log->add(Log::ERROR, $e->getMessage() . ". Errors: :errors",
				[':errors' => $errors]);
		}

		return $count;
	}

  /**
   * Check if the email data provider is available
   *
   */
  protected function _is_provider_available()
  {
	$config = Kohana::$config;
	$providers_available = $config->load('features.data-providers');

	return $providers_available['email']? true : false;
  }

	/**
	 * Process individual incoming email
	 *
	 * @param object $overview
	 * @param string message - the email message
	 */
	protected function _process_incoming($overview, $message)
	{
		$from = $this->_get_email($overview->from);
		$to = isset($overview->to) ? $this->_get_email($overview->to) : $this->from();
		$title = isset($overview->subject) ? $overview->subject : NULL;
		$data_provider_message_id = isset($overview->uid) ? $overview->uid : NULL;
		// @todo revist hard coded HTML stripping & decoding
		// strip all html

		$message = trim(strip_tags($message, ""));
		// convert all HTML entities to their applicable characters
		$message = html_entity_decode($message, ENT_QUOTES, 'UTF-8');
		if ($message)
		{
			// Save the message
			$this->receive(DataSource\Message\Type::EMAIL, $from, $message, $to, $title, $data_provider_message_id);
		}

		return;
	}

	/**
	 * Extract the FROM email address string
	 *
	 * @param string $from - from address string from email
	 * @return string email address or NULL
	 */
	protected function _get_email($from)
	{
		$pattern = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}\b/i';

		if ( preg_match_all($pattern, $from, $emails) )
		{
			foreach ($emails as $key => $value)
			{
				if (isset($value[0]))
				{
					return $value[0];
				}
			}
		}

		return NULL;
	}

	// DataSource
	public function receive($request) {
		return false;
	}

	// DataSource
	public function format($request) {
		return false;
	}

}
