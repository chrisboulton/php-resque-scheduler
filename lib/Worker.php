<?php

namespace ResqueScheduler;

use DateTime;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Resque\Resque;

/**
 * ResqueScheduler worker to handle scheduling of delayed tasks.
 *
 * @package		ResqueScheduler
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @copyright	(c) 2012 Chris Boulton
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Worker implements LoggerAwareInterface
{
    const LOG_NONE = 0;
    const LOG_NORMAL = 1;
    const LOG_VERBOSE = 2;

    /**
     * @var int Current log level of this worker.
     */
    protected $logLevel = 0;

    /**
     * @var int Interval to sleep for between checking schedules.
     */
    protected $interval = 5;

    /**
     * @var ResqueScheduler
     */
    protected $scheduler;

    /**
     * @var Resque
     */
    protected $resque;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var bool
     */
    protected $shutdown;

    /**
     * @var array
     */
    private $current_item;

    /**
     * @var bool
     */
    private $paused;

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
        $this->logger = $resque->getLogger();

        $this->shutdown = false;
        $this->resetCurrentItem();
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
        $this->logger->info('Starting to work');
        $this->startup();

        while (true) {
            if ($this->shutdown) {
                $this->logger->info('Exiting now, good bye')
				return;
			}

            if (!$this->paused) {
                $this->handleDelayedItems();
            }

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
            $this->setCurrentItem($item, $timestamp);
            $log_message = 'queueing ' . $item['class'] . ' in ' . $item['queue'] .' [delayed]';
            $this->print($log_message);
            $this->logger->debug($log_message);

            $this->resque->enqueue($item['queue'], $item['class'], $item['args']);
            $this->resetCurrentItem();
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
    protected function updateProcLine($status)
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
    public function print($message)
    {
        if($this->logLevel == self::LOG_NORMAL) {
            fwrite(STDOUT, "*** " . $message . "\n");
        }
        else if($this->logLevel == self::LOG_VERBOSE) {
            fwrite(STDOUT, "** [" . strftime('%T %Y-%m-%d') . "] " . $message . "\n");
        }
    }

    /**
     * Sets a logger instance on the object
     *
     * @param LoggerInterface $logger
     * @return null
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    private function setCurrentItem($item, $timestamp)
    {
        $this->current_item[$timestamp] = $item;
    }

    private function resetCurrentItem()
    {
        $this->current_item = [];
    }

    /**
     * Registers signal handlers to handle cleanup behavior when running as a daemon
     */
    private function startup()
    {
        if (!function_exists('pcntl_signal')) {
            $this->logger->warning('Cannot register signal handlers');
            return;
        }

        declare(ticks = 1);
        pcntl_signal(SIGTERM, array($this, 'shutdownNow'));
        pcntl_signal(SIGINT, array($this, 'shutdownNow'));
        pcntl_signal(SIGQUIT, array($this, 'shutdownNow'));
        pcntl_signal(SIGUSR1, array($this, 'pauseProcessing'));
        pcntl_signal(SIGCONT, array($this, 'unPauseProcessing'));
        pcntl_signal(SIGPIPE, array($this, 'reestablishRedisConnection'));

        $this->logger->notice('Registered signals');
    }

    public function shutdownNow() {
        $this->logger->notice('Cleaning up before shutdown');
        $this->teardown();
    }

    private function teardown()
    {
        $this->logger->notice('Pushing current task back into redis if necessary.');
        if (!empty($this->current_item)) {
            $item = current($this->current_item);
            $this->scheduler->enqueueAt(key($this->current_item), $item['queue'], $item['class'], $item['args'])
		}
    }

    /**
     * Signal handler for SIGPIPE, in the event the redis connection has gone away.
     * Attempts to reconnect to redis, or raises an Exception.
     */
    public function reestablishRedisConnection()
    {
        $this->logger->notice('SIGPIPE received; attempting to reconnect');
        $this->resque->reconnect();
    }

    public function pauseProcessing()
    {
        $this->logger->notice('USR1 received; pausing execution');
        $this->paused = true;
    }

    public function unPauseProcessing()
    {
        $this->logger->notice('CONT received; resuming execution');
        $this->paused = false;
    }
}
