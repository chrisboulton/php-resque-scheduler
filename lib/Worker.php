<?php

namespace ResqueScheduler;

use DateTime;

/**
 * ResqueScheduler worker to handle scheduling of delayed tasks.
 *
 * @package		ResqueScheduler
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @copyright	(c) 2012 Chris Boulton
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Worker
{
	const LOG_NONE = 0;
	const LOG_NORMAL = 1;
	const LOG_VERBOSE = 2;
	
	/**
	 * @var int Current log level of this worker.
	 */
	private $logLevel = 0;
	
	/**
	 * @var int Interval to sleep for between checking schedules.
	 */
	private $interval = 5;

    /**
     * @var ResqueScheduler
     */
    private $scheduler;

	/**
	 * @var Resque
	 */
	private $resque;

    /**
     * Worker constructor.
     * @param int $logLevel
     * @param int $interval
     * @param Resque $resque
     */
    public function __construct($logLevel, $interval, Resque $resque)
    {
        $this->logLevel = $logLevel;
        $this->interval = $interval;
        $this->resque = $resque;
        $this->scheduler = new ResqueScheduler($resque->getClient());;
    }


    /**
	* The primary loop for a worker.
	*
	* Every $interval (seconds), the scheduled queue will be checked for jobs
	* that should be pushed to Resque.
	*
    * @param Resque $resque The configured resque instance to use
	* @param int $interval How often to check schedules.
	*/
	public function work()
	{
		$this->updateProcLine('Starting');
		
		while (true) {
			$this->handleDelayedItems();
			$this->sleep();
		}
	}
	
	/**
	 * Handle delayed items for the next scheduled timestamp.
	 *
	 * Searches for any items that are due to be scheduled in Resque
	 * and adds them to the appropriate job queue in Resque.
	 *
	 * @param DateTime|int $timestamp Search for any items up to this timestamp to schedule.
	 */
	public function handleDelayedItems($timestamp = null)
	{
		while (($oldestJobTimestamp = $this->scheduler->nextDelayedTimestamp($timestamp)) !== false) {
			$this->updateProcLine('Processing Delayed Items');
			$this->enqueueDelayedItemsForTimestamp($oldestJobTimestamp);
		}
	}
	
	/**
	 * Schedule all of the delayed jobs for a given timestamp.
	 *
	 * Searches for all items for a given timestamp, pulls them off the list of
	 * delayed jobs and pushes them across to Resque.
	 *
	 * @param DateTime|int $timestamp Search for any items up to this timestamp to schedule.
	 */
	public function enqueueDelayedItemsForTimestamp($timestamp)
	{
        $item = null;
        while ($item = $this->scheduler->nextItemForTimestamp($timestamp)) {
            $this->log('queueing ' . $item['class'] . ' in ' . $item['queue'] .' [delayed]');

            $this->resque->enqueue($item['queue'], $item['class'], $item['args']);
        }
	}
	
	/**
	 * Sleep for the defined interval.
	 */
	protected function sleep()
	{
		sleep($this->interval);
	}
	
	/**
	 * Update the status of the current worker process.
	 *
	 * On supported systems (with the PECL proctitle module installed), update
	 * the name of the currently running process to indicate the current state
	 * of a worker.
	 *
	 * @param string $status The updated process title.
	 */
	private function updateProcLine($status)
	{
		if(function_exists('setproctitle')) {
			setproctitle('resque-scheduler-' . ResqueScheduler::VERSION . ': ' . $status);
		}
	}
	
	/**
	 * Output a given log message to STDOUT.
	 *
	 * @param string $message Message to output.
	 */
	public function log($message)
	{
		if($this->logLevel == self::LOG_NORMAL) {
			fwrite(STDOUT, "*** " . $message . "\n");
		}
		else if($this->logLevel == self::LOG_VERBOSE) {
			fwrite(STDOUT, "** [" . strftime('%T %Y-%m-%d') . "] " . $message . "\n");
		}
	}
}
