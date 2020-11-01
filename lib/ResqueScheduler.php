<?php

declare(strict_types=1);

namespace ResqueScheduler;

use DateTime;
use Resque\Client\ClientInterface;
use Resque\ResqueException;

use function json_encode;

/**
 * ResqueScheduler core class to handle scheduling of jobs in the future.
 * Suggested clients: Predis, Credis_Client
 *
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class ResqueScheduler
{
    public const VERSION = "4.0";

    private const PREFIX_DELAYED = 'delayed:';

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * ResqueScheduler constructor.
     *
     * @param ClientInterface $client //fake interface, the client will most like not actually implement this!
     */
    public function __construct($client)
    {
        $this->client = $client;
    }

    /**
     * Enqueue a job in a given number of seconds from now.
     *
     * Identical to Resque\Resque::enqueue, however the first argument is the number
     * of seconds before the job should be executed.
     *
     * @param int    $in    Number of seconds from now when the job should be executed.
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The fully namespaced name of the class that contains the code to execute the job.
     * @param array  $args  Any optional arguments that should be passed when the job is executed.
     *
     * @return void
     */
    public function enqueueIn(int $in, string $queue, string $class, array $args = [])
    {
        $this->enqueueAt(time() + $in, $queue, $class, $args);
    }

    /**
     * Enqueue a job for execution at a given timestamp.
     *
     * Identical to Resque\Resque::enqueue, however the first argument is a timestamp
     * (either UNIX timestamp in integer format or an instance of the DateTime
     * class in PHP).
     *
     * @param DateTime|int $at    Instance of PHP DateTime object or int of UNIX timestamp.
     * @param string       $queue The name of the queue to place the job in.
     * @param string       $class The fully namespaced name of the class that contains the code to execute the job.
     * @param array        $args  Any optional arguments that should be passed when the job is executed.
     *
     * @return void
     */
    public function enqueueAt(DateTime|int $at, string $queue, string $class, array $args = [])
    {
        $this->validateJob($class, $queue);

        $job = $this->jobToHash($queue, $class, $args);
        $this->delayedPush($at, $job);
    }

    /**
     * Get the total number timestamps for which jobs are scheduled.
     */
    public function getDelayedQueueScheduleCount(): int
    {
        return (int)$this->client->zcard('delayed_queue_schedule');
    }

    /**
     * Get the number of jobs for a given timestamp in the delayed schedule.
     */
    public function getDelayedTimestampCount(DateTime|int $timestamp): int
    {
        $timestamp = $this->getTimestamp($timestamp);

        return $this->client->llen(self::PREFIX_DELAYED . $timestamp);
    }

    /**
     * Remove a delayed job from the queue
     *
     * note: you must specify exactly the same
     * queue, class and arguments that you used when you added
     * to the delayed queue
     * also, this is an expensive operation because all delayed keys have to be
     * searched
     *
     * @return int number of jobs that were removed
     */
    public function removeDelayed(string $queue, string $class, array $args): int
    {
        $destroyed = 0;
        $item      = json_encode($this->jobToHash($queue, $class, $args));

        foreach ($this->client->keys('delayed:*') as $key) {
            $destroyed += $this->client->lrem($key, 0, $item);
        }

        return $destroyed;
    }

    /**
     * removed a delayed job queued for a specific timestamp
     *
     * note: you must specify exactly the same
     * queue, class and arguments that you used when you added
     * to the delayed queue
     */
    public function removeDelayedJobFromTimestamp(
        DateTime|int $timestamp,
        string $queue,
        string $class,
        array $args
    ): int {
        $key   = self::PREFIX_DELAYED . $this->getTimestamp($timestamp);
        $item  = json_encode($this->jobToHash($queue, $class, $args));
        $count = $this->client->lrem($key, 0, $item);
        $this->cleanupTimestamp($key, $timestamp);

        return $count;
    }

    /**
     * Find the first timestamp in the delayed schedule before/including the timestamp.
     *
     * Will find and return the first timestamp upto and including the given
     * timestamp. This is the heart of the ResqueScheduler that will make sure
     * that any jobs scheduled for the past when the worker wasn't running are
     * also queued up.
     *
     * @param DateTime|int $at        Instance of DateTime or UNIX timestamp.
     *                                Defaults to now.
     *
     * @return int|false UNIX timestamp, or false if nothing to run.
     */
    public function nextDelayedTimestamp(DateTime|int $at = null): int|false
    {
        if ($at === null) {
            $at = time();
        } else {
            $at = $this->getTimestamp($at);
        }

        $items = $this->client->zrangebyscore('delayed_queue_schedule', '-inf', $at, ['limit' => [0, 1]]);
        if (!empty($items)) {
            return (int)$items[0];
        }

        return false;
    }

    /**
     * Pop a job off the delayed queue for a given timestamp.
     *
     * @param DateTime|int $timestamp Instance of DateTime or UNIX timestamp.
     *
     * @return array|null Matching job at timestamp if one exists
     */
    public function nextItemForTimestamp(DateTime|int $timestamp): ?array
    {
        $timestamp = $this->getTimestamp($timestamp);
        $key       = self::PREFIX_DELAYED . $timestamp;
        $data      = $this->client->lpop($key);

        if (!$data) {
            return null;
        }

        $item = json_decode($data, true);
        $this->cleanupTimestamp($key, $timestamp);

        return $item;
    }

    /**
     * Directly append an item to the delayed queue schedule.
     *
     * @param DateTime|int $timestamp Timestamp job is scheduled to be run at.
     * @param array        $item      Hash of item to be pushed to schedule.
     */
    private function delayedPush(DateTime|int $timestamp, array $item): void
    {
        $timestamp = $this->getTimestamp($timestamp);
        $this->client->rpush(self::PREFIX_DELAYED . $timestamp, json_encode($item));

        $this->client->zadd('delayed_queue_schedule', $timestamp, $timestamp);
    }

    /**
     * Ensure that supplied job class/queue is valid.
     *
     * @param string $class Name of job class.
     * @param string $queue Name of queue.
     *
     * @throws ResqueException
     */
    private function validateJob(string $class, string $queue): void
    {
        if (empty($class)) {
            throw new ResqueException('Jobs must be given a class.');
        } elseif (empty($queue)) {
            throw new ResqueException('Jobs must be put in a queue.');
        }
    }

    /**
     * Generate hash of all job properties to be saved in the scheduled queue.
     *
     * @param string $queue Name of the queue the job will be placed on.
     * @param string $class Name of the job class.
     * @param array  $args  Array of job arguments.
     */

    private function jobToHash(string $queue, string $class, array $args): array
    {
        return [
            'class' => $class,
            'args'  => $args,
            'queue' => $queue,
        ];
    }

    /**
     * If there are no jobs for a given key/timestamp, delete references to it.
     *
     * Used internally to remove empty delayed: items in Redis when there are
     * no more jobs left to run at that timestamp.
     *
     * @param string $key       Key to count number of items at.
     * @param int    $timestamp Matching timestamp for $key.
     */
    private function cleanupTimestamp(string $key, int $timestamp): void
    {
        $timestamp = $this->getTimestamp($timestamp);

        if ($this->client->llen($key) == 0) {
            $this->client->del($key);
            $this->client->zrem('delayed_queue_schedule', $timestamp);
        }
    }

    /**
     * Convert a timestamp in some format in to a unix timestamp as an integer.
     *
     * @param DateTime|int $timestamp Instance of DateTime or UNIX timestamp.
     */
    private function getTimestamp(DateTime|int $timestamp): int
    {
        if ($timestamp instanceof DateTime) {
            return $timestamp->getTimestamp();
        }

        return $timestamp;
    }
}
