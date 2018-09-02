<?php

namespace Aensley\Cap;

/**
 * Alert class
 *
 * @package Aensley/Cap
 * @author    Andrew Ensley
 */
class Alert
{

    /**
     * The User Agent for web requests.
     *
     * @var string
     */
    const USER_AGENT = 'aensley/cap-alert-ifttt v1.0';

    /**
     * The Feed URL template for CAP alerts.
     *
     * @var string
     */
    const FEED_URL_TEMPLATE = 'https://alerts.weather.gov/cap/wwaatmget.php?x={ALERT_ZONE}&y=0';

    /**
     * The IFTTT Webhook URL Template.
     *
     * @var string
     */
    const POST_URL_TEMPLATE = 'https://maker.ifttt.com/trigger/{IFTTT_EVENT}/with/key/{IFTTT_KEY}';

    /**
     * The default name for the cache file.
     *
     * @var string
     */
    const CACHE_FILE_NAME = 'cap_alerts.json';

    /**
     * The array of properties that designate important events. For each key supplied, an entry must have one of the
     * values specified here. If it does not, it will be considered unimportant for the sake of alerting.
     *
     * @see https://www.oasis-open.org/committees/download.php/14759/emergency-CAPv1.1.pdf
     * @var array
     */
    public $important = array(
        'status' => array('Actual'),
        'msgType' => array('Alert', 'Update'),
        'urgency' => array('Immediate', 'Expected', 'Unknown'),
        'severity' => array('Extreme', 'Severe', 'Moderate', 'Unknown'),
        'certainty' => array('Observed', 'Likely', 'Unknown'),
    );

    /**
     * Valid log level names and their corresponding values.
     *
     * @var array
     */
    private $logLevels = array('none' => 1000, 'error' => 400, 'warning' => 300, 'info' => 200, 'debug' => 100);

    /**
     * The log level.
     *
     * @var int
     */
    private $logLevel = 2;

    /**
     * Logger object of a class implementing Psr\Log\LoggerInterface.
     *
     * @var object
     */
    private $logger;

    /**
     * The zone or county ID for the Alert feed.
     *
     * @see https://alerts.weather.gov/
     * @var string
     */
    public $alertZone = '';

    /**
     * The name of the IFTTT event to be used in the webhook.
     *
     * @var string
     */
    public $iftttEvent = '';

    /**
     * The API key for IFTTT to use the webhook.
     *
     * @var string
     */
    public $iftttKey = '';

    /**
     * Set to false to ignore updates to existing alerts.
     *
     * @var bool
     */
    public $sendUpdates = true;

    /**
     * The absolute path to the cache file.
     *
     * @var string
     */
    private $cacheFilePath = '';


    /**
     * Alert constructor.
     *
     * @param string $alertZone   The zone or county ID for the Alert feed. Lookup here: https://alerts.weather.gov/
     * @param string $iftttEvent  The name of the IFTTT event to be used in the webhook.
     * @param string $iftttKey    The API key for IFTTT to use the webhook.
     * @param bool   $sendUpdates Set to false to ignore updates to existing alerts.
     * @param string $cacheFile   The absolute path to the file to be used as a local cache.
     * @param mixed  $logger      Set the logger object (implementing Psr\Log\LoggerInterface) to handle messages.
     *                            Otherwise, set to a valid log level string to use internal simple logger.
     */
    public function __construct($alertZone, $iftttEvent, $iftttKey, $sendUpdates = true, $cacheFile = '', $logger = 'warning')
    {
        $this->alertZone = $alertZone;
        $this->iftttEvent = $iftttEvent;
        $this->iftttKey = $iftttKey;
        $this->sendUpdates = $sendUpdates;
        $this->cacheFilePath = ($cacheFile ? $cacheFile : '/tmp/' . self::CACHE_FILE_NAME);
        $this->setLogger($logger);
    }


    /**
     * Set the logger object.
     * The $logger is required and must be an object of a class implementing Psr\Log\LoggerInterface.
     *
     * @param mixed $logger Object of a class implementing Psr\Log\LoggerInterface to handle messages.
     *                      Otherwise, a valid log level string to use internal simple logger.
     */
    public function setLogger($logger)
    {
        if (is_object($logger)) {
            $this->logger = $logger;
            return;
        }
        if (is_string($logger) && isset($this->logLevels[$logger])) {
            $this->logLevel = $this->logLevels[$logger];
        }
    }


    /**
     * Runs the process.
     */
    public function run()
    {
        $feedUrl = $this->getFeedUrl();
        $rss = $this->getXml($feedUrl);
        if (!$rss) {
            return;
        }

        $isUpdate = false;
        $cache = $this->getCache();
        $this->log('info', count($rss->entry) . ' entries.');
        foreach ($rss->entry as $entry) {
            $entry = $this->normalizeEntry($entry);
            $this->log('info', 'Title: ' . $entry['title']);
            if ($entry['id'] == $feedUrl) {
                $this->log('info', 'There are no alerts.');
                break;
            }

            if (!$this->isImportantAlert($entry)) {
                continue;
            }

            if (isset($cache[$entry['id']])) {
                $cacheEntry = $cache[$entry['id']];
                if (!$this->sendUpdates || $cacheEntry['updated'] === $entry['updated']) {
                    $this->log(
                        'info',
                        'This entry has already been sent to IFTTT, and there is no update, or updates are disabled.'
                    );
                    continue;
                } elseif ($cacheEntry['updated'] > $entry['updated']) {
                    $this->log('debug', 'This is an update to an existing alert');
                    $isUpdate = true;
                }
            }

            $cache[$entry['id']] = $entry;
            $title = $entry['event'] . ($isUpdate ? ' Update' : '');
            $details = (
                    $entry['effective'] > time()
                    ? '. Effective ' . $this->formatTime($entry['effective'])
                    : ''
                )
                . '. Expires ' . $this->formatTime($entry['expires']) . '.';
            $this->log('debug', 'Alert to be sent: ' . $title . $details . ' ' );
            $data = array('value1' => $entry['id'], 'value2' => $title, 'value3' => $details);
            $this->postJson($data);
        }

        $cache = $this->trimCache($cache);
        $this->setCache($cache);
    }


    /**
     * Gets the feed URL.
     *
     * @return string The feed URL.
     */
    private function getFeedUrl()
    {
        return str_replace('{ALERT_ZONE}', $this->alertZone, self::FEED_URL_TEMPLATE);
    }


    /**
     * Gets the post URL.
     *
     * @return string the post URL.
     */
    private function getPostUrl()
    {
        return str_replace(
            '{IFTTT_KEY}',
            $this->iftttKey,
            str_replace('{IFTTT_EVENT}', $this->iftttEvent, self::POST_URL_TEMPLATE)
        );
    }


    /**
     * Gets cache file contents.
     *
     * @return array The cache file contents represented as an associative array.
     */
    private function getCache()
    {
        $this->log('info', 'Getting cache data');
        if (is_readable($this->cacheFilePath)) {
            return json_decode(file_get_contents($this->cacheFilePath), true);
        }

        $this->log('warning', 'Unable to read cache file.');
        return array();
    }


    /**
     * Trims the cache down to only the 10 most recent entries.
     *
     * @param array $data The cache data to trim.
     *
     * @return array The trimmed cache data.
     */
    private function trimCache($data)
    {
        $this->log('debug', 'Trimming cache of ' . count($data) . ' entries.');
        uasort($data, function($a, $b) {
            // Sort by updated time in descending order.
            return $a['updated'] < $b['updated'];
        });
        return array_slice($data, 0, 10, true);
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
        $this->log('info', 'Setting cache data.');
        try {
            return (file_put_contents($this->cacheFilePath, json_encode($data)) !== false);
        } catch (\Exception $e) {
            $this->log('error', 'Error setting cache data: ' . $e->getMessage());
            return false;
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
     * @param int $time Timestamp to format.
     *
     * @return false|string The formatted date/time.
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
        $this->log('info', 'Checking if ' . $entry['title'] . ' is important.');
        foreach ($this->important as $key => $validValues) {
            $this->log('debug', 'Checking ' . $key);
            if (!in_array($entry[$key], $validValues, true)) {
                $this->log('info', 'Failed on ' . $key . ' = "' . $entry[$key] . '". This entry is not important.');
                return false;
            }
        }

        if ($entry['expires'] <= time()) {
            $this->log('info', 'This entry has already expired. It is not important.');
            return false;
        }

        $this->log('debug', 'This entry IS important.');
        return true;
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
        $this->log('info', 'Retrieving XML from: ' . $url);
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
        $errorNumber = curl_error($curl);
        $httpResponse = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($errorNumber !== 0) {
            $this->log('error', 'Curl transport error ' . $errorNumber);
        } elseif ($httpResponse !== 200) {
            $this->log('error', 'HTTP Error ' . $httpResponse);
        } else {
            $this->log('debug', "Success! Result:\n" . $result);
            return simplexml_load_string($result);
        }

        return false;
    }


    /**
     * Posts JSON to a URL.
     *
     * @param array $data The data to post.
     *
     * @return bool True on success; False on failure.
     */
    private function postJson($data)
    {
        $data = json_encode($data);
        $url = $this->getPostUrl();
        $this->log('info', 'Posting JSON to: ' . $url);
        $this->log('debug', 'Data: ' . $data);
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
        $result = curl_exec($curl);
        $errorNumber = curl_error($curl);
        $httpResponse = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($errorNumber !== 0) {
            $this->log('error', 'Curl transport error ' . $errorNumber);
        } elseif ($httpResponse !== 200) {
            $this->log('error', 'HTTP Error ' . $httpResponse);
        } else {
            $this->log('debug', 'Success! Result: ' . $result);
            return true;
        }

        return false;
    }


    /**
     * Logs a message.
     *
     * @param string $level The log level of the message.
     * @param string $text  The message to log.
     */
    private function log($level = 'info', $text = '')
    {
        if (isset($this->logger)) {
            $this->logger->log($level, $text);
            return;
        }

        if ($this->logLevel <= $this->logLevels[$level]) {
            echo strtoupper($level), ': ', $text, "\n";
        }
    }
}
