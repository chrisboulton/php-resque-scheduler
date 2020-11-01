<?php

declare(strict_types=1);

namespace ResqueScheduler;

use DateTime;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Resque\Resque;

use function current;
use function function_exists;
use function gethostname;
use function getmypid;
use function key;
use function pcntl_signal;
use function php_uname;
use function sleep;
use function strftime;

use const PHP_EOL;
use const SIGCONT;
use const SIGINT;
use const SIGPIPE;
use const SIGQUIT;
use const SIGTERM;
use const SIGUSR1;

/**
 * ResqueScheduler worker to handle scheduling of delayed tasks.
 * This worker also handles unix signals so it can be run as a daemon process
 */
class Worker implements LoggerAwareInterface
{
    public const LOG_NONE    = 0;
    public const LOG_NORMAL  = 1;
    public const LOG_VERBOSE = 2;

    protected int $logLevel;
    protected int $interval;
    protected ResqueScheduler $scheduler;
    protected Resque $resque;
    protected LoggerInterface $logger;
    protected bool $shutdown;
    private bool $paused;
    private string $id;
    private int $pushedJobsCount;
    /** @var array[] */
    private array $currentItem;

    public function __construct(int $logLevel, int $interval, Resque $resque)
    {
        $this->logLevel  = $logLevel;
        $this->interval  = $interval;
        $this->resque    = $resque;
        $this->scheduler = new ResqueScheduler($resque->getClient());
        $this->logger    = $resque->getLogger();

        $this->shutdown        = false;
        $this->paused          = false;
        $this->pushedJobsCount = 0;
        $this->resetCurrentItem();
        $this->setId();
    }

    /**
     * The primary loop for a worker.
     *
     * Every $interval (seconds), the scheduled queue will be checked for jobs
     * that should be pushed to Resque.
     *
     */
    public function work()
    {
        $this->updateProcLine('Starting');
        $this->logger->info('Starting to work');
        $this->startup();

        while (true) {
            pcntl_signal_dispatch();
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
    public function handleDelayedItems(DateTime|int $timestamp = null): void
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
    public function enqueueDelayedItemsForTimestamp(DateTime|int $timestamp): void
    {
        $item = null;
        while ($item = $this->scheduler->nextItemForTimestamp($timestamp)) {
            $this->setCurrentItem($item, $timestamp);

            $targetQueue = $item['queue'];
            $class       = $item['class'];
            $arguments   = $item['args'];
            $logMessage  = 'queueing ' . $class . ' in ' . $targetQueue . ' [delayed]';

            $data = json_encode(
                [
                    'target_queue' => $targetQueue,
                    'run_at'       => strftime('%a %b %d %H:%M:%S %Z %Y'),
                    'args'         => $arguments,
                ]
            );
            $this->resque->getClient()->set($this->id, $data);
            $this->printToStdout($logMessage);
            $this->logger->debug($logMessage);

            $this->resque->enqueue($targetQueue, $class, $arguments);
            $this->resetCurrentItem();

            $this->pushedJobsCount++;
        }
    }

    public function printToStdout(string $message)
    {
        if ($this->logLevel == self::LOG_NORMAL) {
            fwrite(STDOUT, '*** ' . $message . PHP_EOL);
        } elseif ($this->logLevel == self::LOG_VERBOSE) {
            fwrite(STDOUT, '** [' . strftime('%T %Y-%m-%d') . ']' . $message . PHP_EOL);
        }
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Handler to quit the worker, also takes care of cleaning everything up.
     *
     * @param int   $signo
     * @param mixed $signinfo
     */
    public function shutdownNow($signo, $signinfo)
    {
        $this->logger->notice('Cleaning up before shutdown');
        $this->teardown();
        $this->shutdown = true;
    }

    /**
     * Signal handler for SIGPIPE, in the event the redis connection has gone away.
     * Attempts to reconnect to redis, or raises an Exception.
     *
     * @param int   $signo
     * @param mixed $signinfo
     */
    public function reestablishRedisConnection($signo, $signinfo)
    {
        $this->logger->notice('SIGPIPE received; attempting to reconnect');
        $this->resque->reconnect();
    }

    /**
     * Signal handler for SIGCUSR1, pauses execution with the next interval tick
     * Note: The current interval will still be processed
     *
     * @param int   $signo
     * @param mixed $signinfo
     */
    public function pauseProcessing($signo, $signinfo)
    {
        $this->logger->notice('USR1 received; pausing execution');
        $this->paused = true;
    }

    /**
     * Signal handler for SIGCONT, resumes execution with the next interval tick
     *
     * @param int   $signo
     * @param mixed $signinfo
     */
    public function unPauseProcessing($signo, $signinfo)
    {
        $this->logger->notice('CONT received; resuming execution');
        $this->paused = false;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPushedJobsCount(): int
    {
        return $this->pushedJobsCount;
    }

    public function getScheduler(): ResqueScheduler
    {
        return $this->scheduler;
    }

    /**
     * Sleep for the defined interval.
     */
    protected function sleep(): void
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
    protected function updateProcLine(string $status): void
    {
        if (function_exists('setproctitle')) {
            setproctitle('resque-scheduler-' . ResqueScheduler::VERSION . ': ' . $status);
        }
    }

    private function setCurrentItem(array $item, DateTime|int $timestamp): void
    {
        $this->currentItem[$timestamp] = $item;
    }

    private function resetCurrentItem(): void
    {
        $this->currentItem = [];
    }

    /**
     * Registers signal handlers to handle cleanup behavior when running as a daemon
     */
    private function startup(): void
    {
        $this->resque->getClient()->set($this->id . ':started', strftime('%a %b %d %H:%M:%S %Z %Y'));

        if (!function_exists('pcntl_signal')) {
            $this->logger->warning('Cannot register signal handlers');

            return;
        }

        declare(ticks=1);
        pcntl_signal(SIGTERM, [$this, 'shutdownNow']);
        pcntl_signal(SIGINT, [$this, 'shutdownNow']);
        pcntl_signal(SIGQUIT, [$this, 'shutdownNow']);
        pcntl_signal(SIGUSR1, [$this, 'pauseProcessing']);
        pcntl_signal(SIGCONT, [$this, 'unPauseProcessing']);
        pcntl_signal(SIGPIPE, [$this, 'reestablishRedisConnection']);

        $this->logger->notice('Registered signals');
    }

    /**
     * Generates the identifier used to store some information in the redis database
     * Mainly interesting for potential status pages (like admin tools)
     */
    private function setId(): void
    {
        $id       = 'delayed_worker:';
        $id       .= function_exists('gethostname') ? gethostname() : php_uname('n');
        $id       .= ':' . getmypid();
        $this->id = $id;
    }

    /**
     * Removes stored status info from redis database and cleans
     */
    private function teardown(): void
    {
        $this->resque->getClient()->del($this->id);
        $this->resque->getClient()->del($this->id . ':started');

        $this->logger->notice('Pushing current task back into redis if necessary.');
        if (!empty($this->currentItem)) {
            $item = current($this->currentItem);
            $this->scheduler->enqueueAt(key($this->currentItem), $item['queue'], $item['class'], $item['args']);
        }
    }
}
