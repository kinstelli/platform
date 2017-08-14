<?php

namespace Ushahidi\App\DataSource\Twitter;

/**
 * Twitter Data Provider
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    DataSource\Twitter
 * @copyright  2013 Ushahidi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU General Public License Version 3 (GPLv3)
 */

use Ushahidi\App\DataSource\DataSource;
use Ushahidi\App\DataSource\Message\Type as MessageType;
use Abraham\TwitterOAuth\TwitterOAuth;
use Symm\Gisconverter\Decoders\WKT;
use Symm\Gisconverter\Decoders\GeoJSON;
use Log;

use Ushahidi\Core\Entity\Contact;
use Ushahidi\Core\Entity\ConfigRepository;

class Twitter implements DataSource {

	const MAX_REQUESTS_PER_WINDOW = 180;
	const REQUEST_WINDOW = 900; // Twitter request window in seconds

	protected $since_id; // highest id fetched
	protected $request_count; // track requests per window
	protected $search_terms;
	protected $window_timestamp;

	protected $config;
	protected $configRepo;

	/**
	 * Constructor function for DataSource
	 */
	public function __construct(array $config, ConfigRepository $configRepo = null)
	{
		$this->config = $config;
		$this->configRepo = $configRepo;
	}

	public function getName() {
		return 'Twitter';
	}

	public function getServices()
	{
		return [MessageType::TWITTER];
	}

	public function getOptions()
	{
		return [
			'intro_step1' => array(
				'label' => 'Step 1: Create a new Twitter application',
				'input' => 'read-only-text',
				'description' => function() {
					return 'Create a <a href="https://apps.twitter.com/app/new">new twitter application</a>';
				}
			),
			// @todo figure out how to inject link and fix base url
			'intro_step2' => array(
				'label' => 'Step 2: Generate a consumer key and secret',
				'input' => 'read-only-text',
				'description' => function() {
					return 'Once you\'ve created the application click on "Keys and Access Tokens".<br /> Then click "Generate Consumer Key and Secret".<br /> Copy keys, tokens and secrets into the fields below.';
				}
			),
			'consumer_key' => array(
				'label' => 'Consumer Key',
				'input' => 'text',
				'description' => 'Add the consumer key from your Twitter app. ',
				'rules' => array('required')
			),
			'consumer_secret' => array(
				'label' => 'Consumer Secret',
				'input' => 'text',
				'description' => 'Add the consumer secret from your Twitter app.',
				'rules' => array('required')
			),
			'oauth_access_token' => array(
				'label' => 'Access Token',
				'input' => 'text',
				'description' => 'Add the access token you generated for your Twitter app.',
				'rules' => array('required')
			),
			'oauth_access_token_secret' => array(
				'label' => 'Access Token Secret',
				'input' => 'text',
				'description' => 'Add the access secret that you generated for your Twitter app.',
				'rules' => array('required')
			),
			'twitter_search_terms' => array(
				'label' => 'Twitter search terms',
				'input' => 'text',
				'description' => 'Add search terms separated with commas',
				'rules' => array('required')
			)
		];
	}


	public function fetch($limit = FALSE) {
		$this->initialize();

		// Check we have the required config
		if (!isset($config['twitter_search_terms']))
		{
			Log::warning('Could not fetch messages from twitter, incomplete config');
			return 0;
		}

		if ($limit === FALSE) {
			$limit = 50;
		}

		$connection = $this->connect();
		if (is_int($connection) && $connection == 0) {
			// The connection didn't succeed, but this is not fatal to the application flow
			// Just return 0 messages fetched
			return 0;
		}
		$connection->setDecodeJsonAsArray(true);
		$count = 0;

		try
		{
			$results = $connection->get("search/tweets", [
				"q" => $this->constructGetQuery($this->search_terms),
				"since_id" => $this->since_id,
				"count" => $limit,
				"result_type" => 'recent'
			]);

			if (empty($results['statuses']))
			{
				return 0;
			}

			$statuses = $results['statuses'];

			// Store the highest id
			$this->since_id = $statuses[0]['id'];

			foreach ($statuses as $status) {
				$id = $status['id'];
				$user = $status['user'];
				$screen_name = $user['screen_name'];
				$text = $status['text'];

				$additional_data = [];

				// Skip retweets
				if (array_key_exists('retweeted_status', $status) && array_key_exists('text', $status['retweeted_status'])) {
					continue;
				}

				if ($status['coordinates'] || $status['place']) {
					$additional_data['location'] = [];
					if ($status['coordinates']) {
						$additional_data['location'][] = $status['coordinates'];
					}

					if ($status['place'] && $status['place']['bounding_box']) {
						// Make a valid linear ring
						$status['place']['bounding_box']['coordinates'][0][] = $status['place']['bounding_box']['coordinates'][0][0];

						// If we don't already have a location
						if (empty($additional_data['location'])) {
							// Find center of bounding box
							$geom = GeoJSON::geomFromText(json_encode($status['place']['bounding_box']));
							// Use mysql to run Centroid
							$result = DB::select([
							 	DB::expr('AsText(Centroid(GeomFromText(:poly)))')->param(':poly', $geom->toWKT()), 'center']
							)->execute(service('kohana.db'));

							$centerGeom = WKT::geomFromText($result->get('center', 0));
							// Save center as location
							$additional_data['location'][] = $centerGeom->toGeoArray();
						}

						// Add that to location
						// Also save the original bounding box
						$additional_data['location'][] = $status['place']['bounding_box'];
					}
				} else if ($status['user'] && $status['user']['location']) {
					# Search the provided location for matches in twitter's geocoder
					$results = $connection->get("geo/search", [
						"query" => $status['user']['location']
					]);
					# If there are results, get the centroid of the first one
					if (!empty($results['result']['places'])) {
						$geoloc = $results['result']['places'][0];
						if ($geoloc['centroid']) {
							$additional_data['location'][] = array(
								'coordinates' => $geoloc['centroid'],
								'type' => 'Point'
							);
						}
						# Add the bounding box too (if available)
						if ($geoloc['bounding_box']) {
							$additional_data['location'][] = $geoloc['bounding_box'];
						}
					}
				}

				// @todo Check for similar messages in the database before saving
				$this->receive('twitter', DataSource\Message\Type::TWITTER, Contact::TWITTER, $screen_name, $text, $to = NULL, $title = NULL, $id, $additional_data);

				$count++;
			}

			$this->request_count++; //Increment for successful request

			$this->update($config);
		}
		catch (\TwitterOAuthException $toe)
		{
			Log::error($toe->getMessage());
		}
		catch(Exception $e)
		{
			Log::error($e->getMessage());
		}

		return $count;
	}

	public function send($to, $message, $title='')
	{
		$connection = $this->connect();

		try
		{
			$response = $connection->post("statuses/update", [
				"status" => '@' . $to . ' ' . $message
			]);

			if (!$response->id) {
				return array(DataSource\Message\Status::FAILED, FALSE);
			}
			return array(DataSource\Message\Status::SENT, $response->id);
		}
		catch (TwitterOAuthException $toe)
		{
			return array(DataSource\Message\Status::FAILED, FALSE);
		}
		catch(Exception $e)
		{
			return array(DataSource\Message\Status::FAILED, FALSE);
		}
	}


	private function constructGetQuery($search_terms)
	{
		return implode(" OR ", array_map('trim', explode(",", $search_terms)));
	}

	private function canMakeRequest()
	{
		return  $this->request_count < self::MAX_REQUESTS_PER_WINDOW;
	}

	private function initialize($config)
	{
		$twitterConfig = $configRepo->get('twitter');

		isset($twitterConfig->since_id) ?
							   $this->since_id = $twitterConfig->since_id:
							   $this->since_id = 0;

		$this->search_terms = $this->config['twitter_search_terms'];

		// If search terms have changed, reset since_id
		if ($this->config['twitter_search_terms'] !== $twitterConfig->search_terms) {
			$this->since_id = 0;
		}

		$twitter_config && isset($twitterConfig->request_count)?
							   $this->request_count = $twitterConfig->request_count:
							   $this->request_count = 0;

		if (isset($twitterConfig->window_timestamp))
		{
			$this->window_timestamp = $twitterConfig->window_timestamp;
			$window_has_expired = time() - $twitterConfig->window_timestamp > self::REQUEST_WINDOW;

			if ($window_has_expired)
			{
				// reset
				$this->request_count = 0;
				$this->window_timestamp = time();
			}
		}
		else
		{
			// save window timestamp for the first time
			$this->window_timestamp = time();
		}
	}

	private function update($config)
	{
		// XXX: Store state in database config for now
		$twitterConfig = $this->configRepo->get('twitter');

		$twitterConfig->setState([
			'request_count' => $this->request_count,
			'since_id' => $this->since_id,
			'search_terms' => $this->search_terms,
			'window_timestamp' => $this->window_timestamp
		]);

		$this->configRepo->update($twitterConfig);
	}

	private function connect() {
		// check if we have reached our rate limit
		if ( !$this->canMakeRequest())
		{
			Log::warning('You have reached your rate limit for this window');
			return 0;
		}
			// Check we have the required config
		if ( !isset($this->config['consumer_key']) ||
			 !isset($this->config['consumer_secret']) ||
			 !isset($this->config['oauth_access_token']) ||
			 !isset($this->config['oauth_access_token_secret'])
		)
		{
			Log::warning('Could not connect to twitter, incomplete config');
			return 0;
		}

		$connection = new TwitterOAuth(
			$this->config['consumer_key'],
			$this->config['consumer_secret'],
			$this->config['oauth_access_token'],
			$this->config['oauth_access_token_secret']
		);

		// Increase curl timeout values
		$connection->setTimeouts(100, 150);
		return $connection;
	}

}
