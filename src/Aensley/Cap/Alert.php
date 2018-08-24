<?php

namespace Aensley\Cap;

/**
 * Alert class
 *
 * @package Aensley/Cap
 * @author  Andrew Ensley
 */
class Alert
{

	const FEED_URL_TEMPLATE = 'https://alerts.weather.gov/cap/wwaatmget.php?x={CODE}&y=0';
	const POST_URL_TEMPLATE = 'https://maker.ifttt.com/trigger/{event}/with/key/{key}';
	const CACHE_FILE_NAME = 'cap_alerts.json';

	// https://www.oasis-open.org/committees/download.php/14759/emergency-CAPv1.1.pdf
	public $important = array(
		'status' => array('Actual'),
		'msgType' => array('Alert', 'Update'),
		'urgency' => array('Immediate', 'Expected', 'Unknown'),
		'severity' => array('Extreme', 'Severe', 'Moderate', 'Unknown'),
		'certainty' => array('Observed', 'Likely', 'Unknown'),
	);

	private $feedCode = '';
	private $event = '';
	private $key = '';
	private $cacheFilePath = '';


	/**
	 * Alert constructor
	 * .
	 * @param string $feedCode  The zone or county ID for the Alert feed. Lookup here: https://alerts.weather.gov/
	 * @param string $event     The name of the IFTTT event to be used in the webhook.
	 * @param string $key       The API key for IFTTT to use the webhook.
	 * @param string $cacheFile The absolute path to the file to be used as a local cache.
	 */
	public function __construct($feedCode, $event, $key, $cacheFile = '')
	{
		$this->feedCode = $feedCode;
		$this->event = $event;
		$this->key = $key;
		$this->cacheFilePath = ($cacheFile ? $cacheFile : '/tmp/' . self::CACHE_FILE_NAME);
	}


	/**
	 * Runs the process.
	 */
	public function run()
	{
		$feedUrl = str_replace('{CODE}', $this->feedCode, self::FEED_URL_TEMPLATE);
		$rss = $this->getXml($feedUrl);
		foreach ($rss->entry as $entry) {
			$entry = $this->normalizeEntry($entry);
			echo "\n\n# ", $entry['title'], "\n\n";
			if ($entry['id'] == $feedUrl) {
				echo 'NO ALERTS', "\n";
				break;
			}

			if (!$this->isImportantAlert($entry)) {
				echo 'This alert is not important', "\n";
				continue;
			}

			$message = $entry['event']
				. ($entry['effective'] > time()
					? '. Effective ' . $this->formatTime($entry['effective'])
					: ''
				)
				. '. Expires ' . $this->formatTime($entry['expires']) . '.';
			echo $message, "\n";
			$data = ['value2' => $message, 'value1' => (string) $entry->id];
			$postUrl = str_replace('{key}', $this->key, str_replace('{event}', $this->event, self::POST_URL_TEMPLATE));
			$this->postJson($postUrl, $data);
		}
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
		return [
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
		];
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
		curl_setopt($curl, CURLOPT_USERAGENT, 'aensley/cap-alert-ifttt v1.0');
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		if (!ini_get('open_basedir')) {
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		}

		$result = curl_exec($curl);
		return curl_errno($curl) === 0
		&& curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200
			? simplexml_load_string($result)
			: false;
	}


	/**
	 * Posts JSON to a URL.
	 *
	 * @param string $url  The URL to post to.
	 * @param array  $data The data to post.
	 *
	 * @return bool True on success; False on failure.
	 */
	private function postJson($url, $data)
	{
		$data = json_encode($data);
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
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
