<?php

declare(strict_types=1);

namespace ResqueScheduler;

use Resque\Test;
use Resque\Test\Job;

class WorkerTest extends Test
{
    private const QUEUE_JOBS = 'jobs';

    protected ResqueScheduler $scheduler;
    protected Worker $worker;

    public function setUp(): void
    {
        parent::setUp();
        $this->worker    = new Worker(Worker::LOG_NONE, 0, $this->resque);
        $this->scheduler = $this->worker->getScheduler();
    }

    public function testHandleDelayedItems(): void
    {
        $now = time();

        $this->scheduler->enqueueAt($now - 10, self::QUEUE_JOBS, Job::class);
        $this->scheduler->enqueueAt($now - 5, self::QUEUE_JOBS, Job::class);
        $this->scheduler->enqueueAt($now + 10, self::QUEUE_JOBS, Job::class);

        $this->worker->handleDelayedItems($now);

        static::assertEquals(2, $this->worker->getPushedJobsCount());
        static::assertEquals(1, $this->scheduler->getDelayedQueueScheduleCount());

        static::assertContains(self::QUEUE_JOBS, $this->resque->queues());
        static::assertNotEmpty($this->resque->pop(self::QUEUE_JOBS));
        static::assertNotEmpty($this->resque->pop(self::QUEUE_JOBS));
        static::assertNull($this->resque->pop(self::QUEUE_JOBS));
    }
}