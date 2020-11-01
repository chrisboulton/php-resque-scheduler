<?php

declare(strict_types=1);

namespace ResqueScheduler;

use DateTime;
use Resque\Test;
use Resque\Test\Job;

use function time;

class ResqueSchedulerTest extends Test
{
    private const QUEUE_JOBS = 'jobs';

    protected ResqueScheduler $scheduler;

    public function setUp(): void
    {
        parent::setUp();
        $this->scheduler = new ResqueScheduler($this->redis);
    }

    public function testEnqueueAt(): void
    {
        $now = time();

        $this->scheduler->enqueueAt($timestampFirstJob = $now + 5, self::QUEUE_JOBS, Job::class);
        static::assertEquals(1, $this->scheduler->getDelayedTimestampCount($timestampFirstJob));

        $dateTime = new DateTime();
        $dateTime->setTimestamp($timestampSecondJob = $now + 10);
        $this->scheduler->enqueueAt($dateTime, self::QUEUE_JOBS, Job::class);
        static::assertEquals(1, $this->scheduler->getDelayedTimestampCount($timestampSecondJob));
        static::assertEquals(1, $this->scheduler->getDelayedTimestampCount($dateTime));
        static::assertEquals(1, $this->scheduler->getDelayedTimestampCount($timestampFirstJob));
        static::assertEquals(2, $this->scheduler->getDelayedQueueScheduleCount());
    }

    public function testEnqueueIn(): void
    {
        $now = time();

        $this->scheduler->enqueueIn(5, self::QUEUE_JOBS, Job::class);
        static::assertEquals(1, $this->scheduler->getDelayedTimestampCount($now + 5));

        $this->scheduler->enqueueIn(10, self::QUEUE_JOBS, Job::class);
        static::assertEquals(1, $this->scheduler->getDelayedTimestampCount($now + 10));
        static::assertEquals(1, $this->scheduler->getDelayedTimestampCount($now + 5));
        static::assertEquals(2, $this->scheduler->getDelayedQueueScheduleCount());
    }

    public function testRemoveDelayed(): void
    {
        [$at1, $at2, $args1, $args2] = $this->scheduleMultipleJobsInTheFuture();

        $this->scheduler->removeDelayed(self::QUEUE_JOBS, Job::class, []);
        static::assertEquals(2, $this->scheduler->getDelayedTimestampCount($at1));
        static::assertEquals(2, $this->scheduler->getDelayedTimestampCount($at2));

        $this->scheduler->removeDelayed(self::QUEUE_JOBS, Job::class, $args1);
        static::assertEquals(1, $this->scheduler->getDelayedTimestampCount($at1));
        static::assertEquals(1, $this->scheduler->getDelayedTimestampCount($at2));

        $this->scheduler->removeDelayed(self::QUEUE_JOBS, Job::class, $args2);
        static::assertEquals(0, $this->scheduler->getDelayedTimestampCount($at1));
        static::assertEquals(0, $this->scheduler->getDelayedTimestampCount($at2));
    }

    public function testRemoveDelayedJobFromTimestamp(): void
    {
        [$at1, $at2, $args1, $args2] = $this->scheduleMultipleJobsInTheFuture();

        $this->scheduler->removeDelayedJobFromTimestamp($at1, self::QUEUE_JOBS, Job::class, []);
        static::assertEquals(2, $this->scheduler->getDelayedTimestampCount($at1));
        static::assertEquals(2, $this->scheduler->getDelayedTimestampCount($at2));

        $this->scheduler->removeDelayedJobFromTimestamp($at1, self::QUEUE_JOBS, Job::class, $args1);
        static::assertEquals(1, $this->scheduler->getDelayedTimestampCount($at1));
        static::assertEquals(2, $this->scheduler->getDelayedTimestampCount($at2));

        $this->scheduler->removeDelayedJobFromTimestamp($at1, self::QUEUE_JOBS, Job::class, $args2);
        static::assertEquals(0, $this->scheduler->getDelayedTimestampCount($at1));
        static::assertEquals(2, $this->scheduler->getDelayedTimestampCount($at2));
    }

    public function testNextDelayedTimestamp(): void
    {
        $this->scheduler->enqueueAt(time() + 10, self::QUEUE_JOBS, Job::class);
        static::assertFalse($this->scheduler->nextDelayedTimestamp());

        $earlierAt = time() - 5;
        $olderAt   = time() - 10;

        $this->scheduler->enqueueAt($earlierAt, self::QUEUE_JOBS, Job::class);
        static::assertEquals($earlierAt, $this->scheduler->nextDelayedTimestamp());

        $this->scheduler->enqueueAt($olderAt, self::QUEUE_JOBS, Job::class);
        static::assertEquals($olderAt, $this->scheduler->nextDelayedTimestamp());
    }

    public function testNextItemForTimestamp(): void
    {
        [$at1, $at2, $args1,] = $this->scheduleMultipleJobsInTheFuture();

        $item = $this->scheduler->nextItemForTimestamp($at1);

        static::assertEquals(static::QUEUE_JOBS, $item['queue']);
        static::assertEquals(Job::class, $item['class']);
        static::assertEquals($args1, $item['args']);

        $item = $this->scheduler->nextItemForTimestamp($at2);

        static::assertEquals(static::QUEUE_JOBS, $item['queue']);
        static::assertEquals(Job::class, $item['class']);
        static::assertEquals($args1, $item['args']);
    }

    private function scheduleMultipleJobsInTheFuture(): array
    {
        $at1   = time() + 5;
        $at2   = time() + 10;
        $args1 = ['a' => 'b'];
        $args2 = ['c' => 'd'];

        $this->scheduler->enqueueAt($at1, self::QUEUE_JOBS, Job::class, $args1);
        $this->scheduler->enqueueAt($at1, self::QUEUE_JOBS, Job::class, $args2);
        $this->scheduler->enqueueAt($at2, self::QUEUE_JOBS, Job::class, $args1);
        $this->scheduler->enqueueAt($at2, self::QUEUE_JOBS, Job::class, $args2);

        return [$at1, $at2, $args1, $args2];
    }
}