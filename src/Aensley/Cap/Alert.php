<?php

namespace Aensley\Cap;

/**
 * Alert class
 *
 * @package Aensley/Cap
 * @author	Andrew Ensley
 */
class Alert
{

	const USER_AGENT = 'aensley/cap-alert-ifttt v1.0';
	const FEED_URL_TEMPLATE = 'https://alerts.weather.gov/cap/wwaatmget.php?x={ALERT_ZONE}&y=0';
	const POST_URL_TEMPLATE = 'https://maker.ifttt.com/trigger/{IFTTT_EVENT}/with/key/{IFTTT_KEY}';
	const CACHE_FILE_NAME = 'cap_alerts.json';

	// https://www.oasis-open.org/committees/download.php/14759/emergency-CAPv1.1.pdf
	public $important = array(
		'status'		=> array('Actual'),
		'msgType'	 => array('Alert', 'Update'),
		'urgency'	 => array('Immediate', 'Expected', 'Unknown'),
		'severity'	=> array('Extreme', 'Severe', 'Moderate', 'Unknown'),
		'certainty' => array('Observed', 'Likely', 'Unknown'),
	);

	public $alertZone = '';
	public $iftttEvent = '';
	public $iftttKey = '';
	public $sendUpdates = true;
	private $cacheFilePath = '';


	/**
	 * Alert constructor
	 * .
	 * @param string $alertZone	 The zone or county ID for the Alert feed. Lookup here: https://alerts.weather.gov/
	 * @param string $iftttEvent	The name of the IFTTT event to be used in the webhook.
	 * @param string $iftttKey		The API key for IFTTT to use the webhook.
	 * @param bool	 $sendUpdates Set to false to ignore updates to existing alerts.
	 * @param string $cacheFile	 The absolute path to the file to be used as a local cache.
	 */
	public function __construct($alertZone, $iftttEvent, $iftttKey, $sendUpdates = true, $cacheFile = '')
	{
		$this->alertZone = $alertZone;
		$this->iftttEvent = $iftttEvent;
		$this->iftttKey = $iftttKey;
		$this->sendUpdates = $sendUpdates;
		$this->cacheFilePath = ($cacheFile ? $cacheFile : '/tmp/' . self::CACHE_FILE_NAME);
	}


	/**
	 * Runs the process.
	 */
	public function run()
	{
		$feedUrl = str_replace('{ALERT_ZONE}', $this->alertZone, self::FEED_URL_TEMPLATE);
		$rss = $this->getXml($feedUrl);
		if (!$rss) {
			return;
		}

		$isUpdate = false;
		$cache = $this->getCache();
		foreach ($rss->entry as $entry) {
			$entry = $this->normalizeEntry($entry);
			if ($entry['id'] == $feedUrl) {
				// NO ALERTS
				break;
			}

			if (!$this->isImportantAlert($entry)) {
				// This alert is not important. Skip it.
				continue;
			}

			if (isset($cache[$entry['id']])) {
				$cacheEntry = $cache[$entry['id']];
				if (!$this->sendUpdates || $cacheEntry['updated'] === $entry['updated']) {
					continue;
				} elseif ($cacheEntry['updated'] > $entry['updated']) {
					$isUpdate = true;
				}
			}

			$cache[$entry['id']] = $entry;
			$message = $entry['event']
				. ($entry['effective'] > time()
					? '. Effective ' . $this->formatTime($entry['effective'])
					: ''
				)
				. '. Expires ' . $this->formatTime($entry['expires']) . '.';
			// echo $message, "\n";
			$data = array('value2' => $message, 'value1' => (string) $entry->id);
			$postUrl = str_replace(
				'{IFTTT_KEY}',
				$this->iftttKey,
				str_replace('{IFTTT_EVENT}', $this->iftttEvent, self::POST_URL_TEMPLATE)
			);
			$this->postJson($postUrl, $data);
		}

		$this->setCache($cache);
	}


	/**
	 * Gets cache file contents.
	 *
	 * @return array The cache file contents represented as an associative array.
	 */
	private function getCache()
	{
		return json_decode(file_get_contents($this->cacheFilePath), true);
	}


	/**
	 * Sets cache file contents.
	 *
	 * @param array $data The data to set in the cache file.
	 *
	 * @return bool True on success. False on failure.
	 */
	private function setCache($data)
	{
		return (file_put_contents($this->cacheFilePath, json_encode($data)) !== false);
	}


	/**
	 * Takes a SimpleXMLElement representing a single alert entry and returns the relevant data in a suitable format.
	 *
	 * @param \SimpleXMLElement $entry The entry to normalize.
	 *
	 * @return array The normalized entry.
	 */
	private function normalizeEntry($entry)
	{
		$cap = $entry->children('urn:oasis:names:tc:emergency:cap:1.1');
		return array(
			'id' => (string) $entry->id,
			'title' => (string) $entry->title,
			'event' => (string) $cap->event,
			'published' => strtotime($entry->published),
			'updated' => strtotime($entry->updated),
			'status' => (string) $cap->status,
			'msgType' => (string) $cap->msgType,
			'urgency' => (string) $cap->urgency,
			'severity' => (string) $cap->severity,
			'certainty' => (string) $cap->certainty,
			'effective' => strtotime($cap->effective),
			'expires' => strtotime($cap->expires)
		);
	}


	/**
	 * Takes a timestamp and formats it to a readable format.
	 *
	 * @param $time
	 * @return false|string
	 */
	private function formatTime($time)
	{
		return date('F j \a\t g:ia', $time);
	}


	/**
	 * Checks if the given alert is important enough to process. Configured by the $important property of this class.
	 *
	 * @param array $entry The entry to evaluate.
	 *
	 * @return bool True if the alert is important. False if not.
	 */
	private function isImportantAlert($entry)
	{
		foreach ($this->important as $key => $validValues) {
			if (!in_array($entry[$key], $validValues, true)) {
				return false;
			}
		}

		return ($entry['expires'] > time());
	}


	/**
	 * Gets an XML document from a URL.
	 *
	 * @param string $url The URL to fetch.
	 *
	 * @return bool|\SimpleXMLElement False if there is an error. Otherwise, the XML that was requested.
	 */
	private function getXml($url)
	{
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_TIMEOUT, 20);
		curl_setopt($curl, CURLOPT_ENCODING, '');
		curl_setopt($curl, CURLOPT_USERAGENT, self::USER_AGENT);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		if (!ini_get('open_basedir')) {
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		}

		$result = curl_exec($curl);
		return (
			curl_errno($curl) === 0 && curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200
				? simplexml_load_string($result)
				: false
		);
	}


	/**
	 * Posts JSON to a URL.
	 *
	 * @param string $url	The URL to post to.
	 * @param array	$data The data to post.
	 *
	 * @return bool True on success; False on failure.
	 */
	private function postJson($url, $data)
	{
		$data = json_encode($data);
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($curl, CURLOPT_TIMEOUT, 20);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		curl_setopt($curl, CURLOPT_USERAGENT, self::USER_AGENT);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt(
			$curl,
			CURLOPT_HTTPHEADER,
			array(
				'Content-Type: application/json',
				'Content-Length: ' . strlen($data)
			)
		);
		curl_exec($curl);
		return (curl_errno($curl) === 0 && curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200);
	}
}
