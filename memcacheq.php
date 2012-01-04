<?php
/**
 * A PHP wrapper for MemcacheQ
 *
 * @author Jack Hu <hutushen222@gmail.com>
 * @license BSD
 * @link https://github.com/hutushen222/memcacheq-php
 */

/**
 * MemcacheQ Class
 *
 * @author Jack Hu <hutushen222@gmail.com>
 * @version 0.1.0
 */
class MemcacheQ
{
	const DEFAULT_HOST = '127.0.0.1';
	const DEFAULT_PORT = 22201;
	const EOL          = "\r\n";

	/**
	 * @var Memcache
	 */
	protected $_cache = null;

	/**
	 * @var string
	 */
	protected $_host = null;

	/**
	 * @var integer
	 */
	protected $_port = null;

	/**
	 * @var resource
	 */
	protected $_socket = null;

	/**
	 * @var array
	 */
	protected $_queues = array();

	/**
	 * Constructor
	 *
	 * @param array $options
	 *
	 * @return void
	 * @throws Exception
	 */
	public function __construct($host = self::DEFAULT_HOST, $port = self::DEFAULT_PORT)
	{
		if (!extension_loaded('memcache')) {
			throw new Exception('Memcache extension does not appear to be loaded');
		}

		$this->_host = $host;
		$this->_port = $port;

		$this->_cache = new Memcache();

		$result = $this->_cache->connect($this->_host, $this->_port);
		if ($result === false) {
			throw new Exception('Could not connect to MemcacheQ.');
		}
		$this->getQueues();
	}

	/**
	 * Destructor
	 *
	 * @return void
	 */
	public function __destruct()
	{
		if ($this->_cache instanceof Memcache) {
			$this->_cache->close();
		}

		if (is_resource($this->_socket)) {
			$cmd = 'quit' . self::EOL;
			fwrite($this->_socket, $cmd);
			fclose($this->_socket);
		}
	}

	/**
	 * Does a queue already exist?
	 *
	 * @param string $name Queue name
	 *
	 * @return boolean
	 */
	public function isExistQueue($name)
	{
		if (empty($this->_queues)) {
			$this->getQueues();
		}

		return array_key_exists($name, $this->_queues);
	}


	/**
	 * Create a new queue
	 *
	 * @param string $name Queue name
	 *
	 * @return MemcacheQ_Queue
	 */
	public function createQueue($name)
	{
		if ($this->isExistQueue($name)) {
			return $this->_queues[$name];
		}

		// create a new queue with a message
		$result = $this->_cache->set($name, 'creating queue', 0, 15);
		// delete the message
		$result = $this->_cache->get($name);

		$queue = new MemcacheQ_Queue($name, $this->_cache);

		$this->_queues[$name] = $queue;
		return $this->_queues[$name];
	}

	/**
	 * Delete a queue and all of its messages.
	 *
	 * Return false if the queue is not found, true if the queue exists.
	 *
	 * @param string $name Queue name
	 *
	 * @return boolean
	 */
	public function deleteQueue($name)
	{
		$response = $this->_sendCommand('delete ' . $name, array('DELETED', 'NOT_FOUND'), true);

		if (in_array('DELETED', $response)) {
			$key = array_key_exists($name, $this->_queues);
			if ($key !== false) {
				unset($this->_queues[$name]);
			}
			return true;
		}

		return false;
	}

	/**
	 * Get an array of all available queues
	 *
	 * @return array
	 */
	public function getQueues()
	{
		$this->_queues = array();

		$response = $this->_sendCommand('stats queue', array('END'));

		foreach($response as $i => $line) {
			$queue = explode(' ', str_replace('STAT ', '', $line));
			$this->_queues[$queue[0]] = new MemcacheQ_Queue($queue[0], $this->_cache);
			$this->_queues[$queue[0]]->setStats($queue[1]);
		}

		return $this->_queues;
	}

	/**
	 * Delete all queues.
	 */
	public function deleteQueues()
	{
		foreach ($this->_queues as $queue) {
			$this->deleteQueue($queue->getName());
		}
	}

	/**
	 * Magic Get
	 */
	public function __get($name)
	{
		if ($this->isExistQueue($name)) {
			return $this->_queues[$name];
		} else {
			throw new Exception("Queue does not exist: $name");
		}
	}

	/**
	 * Sends a command to MemcacheQ.
	 *
	 * The memcache functions by php cannot handle all type of request supported by MemcacheQ.
	 * Non-standard requests are handled by this function.
	 *
	 * @param string $command command to send to MemcacheQ
	 * @param array $terminator string to indicate end of MemcacheQ response
	 * @param boolean $include_term include terminator in response
	 *
	 * @return array
	 * @throw Exception (connection cannnot be opened)
	 */
	protected function _sendCommand($command, array $terminator, $include_term=false)
	{
		if (!is_resource($this->_socket)) {
			$this->_socket = fsockopen($this->_host, $this->_port, $errno, $errstr, 10);
		}
		if ($this->_socket === false) {
			throw new Exception("Could not open a connection to $this->_host:$this->_port errno=$errno : $errstr");
		}

		$response = array();

		$cmd = $command . self::EOL;
		fwrite($this->_socket, $cmd);

		$continue_reading = true;
		while (!feof($this->_socket) && $continue_reading) {
			$resp = trim(fgets($this->_socket, 1024));
			if (in_array($resp, $terminator)) {
				if ($include_term) {
					$response[] = $resp;
				}
				$continue_reading = false;
			} else {
				$response[] = $resp;
			}
		}

		return $response;
	}
}

/**
 * MemcacheQ Queue Class
 *
 * @author Jack Hu <hutushen222@gmail.com>
 * @version 0.1.0
 */
class MemcacheQ_Queue
{

	/**
	 * Queue name
	 *
	 * @var string
	 */
	protected $_name = null;

	/**
	 * Queue stats
	 *
	 * array {
	 *     [0] => 3 // total messages count
	 *     [1] => 1 // received messages count
	 * }
	 *
	 * @var array
	 */
	protected $_stats = null;

	/**
	 * Queue memcache handler
	 *
	 * @var resource
	 */
	protected $_cache = null;

	/**
	 * Constructor
	 *
	 * @param string $name
	 * @param resource $cache
	 */
	public function __construct($name, $cache)
	{
		$this->_name = $name;
		$this->_cache = $cache;
		$this->_stats = array(1, 1);
	}

	/**
	 * Send a message to the queue.
	 */
	public function sendMessage($message)
	{
		$message = (string) $message;
		$result = $this->_cache->set($this->_name, $message, 0, 0);
		if ($result === true) {
			$this->_stats[0]++;
		}

		return $result;
	}

	/**
	 * Receive a message from the queue.
	 *
	 * @return mixed|bool
	 */
	public function receiveMessage()
	{
		$message = $this->_cache->get($this->_name);
		if ($message !== false) {
			$this->_stats[1]++;
		}

		return $message;
	}

	/**
	 * Receive multiple message from the queue.
	 *
	 * @param int $count 
	 */
	public function receiveMessages($count = 1)
	{
		$messages = array();
		for ($i = 0; $i < $count; $i++) {
			$messages[] = $this->receiveMessage();
		}

		return $messages;
	}

	/**
	 * Delete a message from the queue.
	 *
	 * Returns true if the message is deleted, false if the deletion is unsuccessful.
	 *
	 * @return boolean
	 * @throws Exception (unsupported)
	 */
	public function deleteMessage()
	{
		throw new Exception("MemcacheQ does not support delete message.");
	}

	/**
	 * Get queue name.
	 */
	public function getName()
	{
		return $this->_name;
	}

	/**
	 * Set queue stats.
	 *
	 * @param array|string $stats
	 */
	public function setStats($stats)
	{
		if (is_array($stats)) {
			$this->_stats = $stats;
		} else {
			$this->_stats = explode('/', $stats);
		}
		return $this;
	}

	/**
	 * Get queue stats
	 *
	 * @return array
	 */
	public function getStats()
	{
		return $this->_stats;
	}

	/**
	 * Get current queue total messages count.
	 */
	public function getTotal()
	{
		return $this->_stats[0];
	}

	/**
	 * Get current queue messages count of received.
	 */
	public function getReceived()
	{
		return $this->_stats[1];
	}

	/**
	 * Get current queue messages count of remain.
	 */
	public function getRemain()
	{
		return $this->_stats[0] - $this->_stats[1];
	}

}


// Omit PHP End Tag.
