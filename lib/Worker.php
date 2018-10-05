<?php

namespace ResqueScheduler;

use DateTime;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Resque\Resque;

/**
 * ResqueScheduler worker to handle scheduling of delayed tasks.
 * This worker also handles unix signals so it can be run as a daemon process
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
     * @var string
     */
    private $id;

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
                $this->logger->info('Exiting now, good bye');
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

            $target_queue = $item['queue'];
            $class = $item['class'];
            $arguments = $item['args'];
            $log_message = 'queueing ' . $class. ' in ' . $target_queue .' [delayed]';

            $data = json_encode(
                [
                    'target_queue' => $target_queue,
                    'run_at' => strftime('%a %b %d %H:%M:%S %Z %Y'),
                    'args' => $arguments
                ]
            );
            $this->resque->getClient()->set($this->id, $data);
            $this->printToStdout($log_message);
            $this->logger->debug($log_message);

            $this->resque->enqueue($target_queue, $class, $arguments);
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
    public function printToStdout($message)
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
        $this->setId();
        $this->resque->getClient()->set($this->id . ':started', strftime('%a %b %d %H:%M:%S %Z %Y'));

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

    /**
     * Handler to quit the worker, also takes care of cleaning everything up.
     */
    public function shutdownNow() {
        $this->logger->notice('Cleaning up before shutdown');
        $this->teardown();
        $this->shutdown = true;
    }

    /**
     * Removes stored status info from redis database and cleans
     */
    private function teardown()
    {
        $this->resque->getClient()->del($this->id);
        $this->resque->getClient()->del($this->id . ':started');

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

    /**
     * Signal handler for SIGCUSR1, pauses execution with the next interval tick
     * Note: The current interval will still be processed
     */
    public function pauseProcessing()
    {
        $this->logger->notice('USR1 received; pausing execution');
        $this->paused = true;
    }

    /**
     * Signal handler for SIGCONT, resumes execution with the next interval tick
     */
    public function unPauseProcessing()
    {
        $this->logger->notice('CONT received; resuming execution');
        $this->paused = false;
    }

    /**
     * Generates the identifier used to store some information in the redis database
     * Mainly interesting for potential status pages (like admin tools)
     */
    private function setId()
    {
        $id = 'delayed_worker:';
        $id .= function_exists('gethostname') ? gethostname() : php_uname('n');
        $id .= ':' . getmypid();
        $this->id = $id;
    }
}
