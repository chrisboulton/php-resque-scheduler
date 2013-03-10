php-resque-scheduler: PHP Resque Scheduler
==========================================

php-resque-scheduler is a PHP port of [resque-scheduler](http://github.com/defunkt/resque),
which adds support for scheduling items in the future to Resque.

The PHP port of resque-scheduler has been designed to be an almost direct-copy
of the Ruby plugin, and is designed to work with the PHP port of resque,
[php-resque](http://github.com/chrisboulton/php-resque).

At the moment, php-resque-scheduler only supports delayed jobs, which is the
ability to push a job to the queue and have it run at a certain timestamp, or
in a number of seconds. Support for recurring jobs (similar to CRON) is planned
for a future release.

Because the PHP port is almost a direct API copy of the Ruby version, it is also
compatible with the web interface of the Ruby version, which provides the
ability to view and manage delayed jobs.

## Delayed Jobs

To quote the documentation for the Ruby resque-scheduler:

> Delayed jobs are one-off jobs that you want to be put into a queue at some
point in the future. The classic example is sending an email:

    require 'Resque/Resque.php';
    require 'ResqueScheduler/ResqueScheduler.php';
   
    $in = 3600;
    $args = array('id' => $user->id);
    ResqueScheduler::enqueueIn($in, 'email', 'SendFollowUpEmail', $args);

The above will store the job for 1 hour in the delayed queue, and then pull the
job off and submit it to the `email` queue in Resque for processing as soon as
a worker is available.

Instead of passing a relative time in seconds, you can also supply a timestamp
as either a DateTime object or integer containing a UNIX timestamp to the
`enqueueAt` method:

	require 'Resque/Resque.php';
    require 'ResqueScheduler/ResqueScheduler.php';
    
    $time = 1332067214;
    ResqueScheduler::enqueueAt($time, 'email', 'SendFollowUpEmail', $args);

	$datetime = new DateTime('2012-03-18 13:21:49');
	ResqueScheduler::enqueueAt(datetime, 'email', 'SendFollowUpEmail', $args);

NOTE: resque-scheduler does not guarantee a job will fire at the time supplied.
At the time supplied, resque-scheduler will take the job out of the delayed
queue and push it to the appropriate queue in Resque. Your next available Resque
worker will pick the job up. To keep processing as quick as possible, keep your
queues as empty as possible.

## Worker

Like resque, resque-scheduler includes a worker that runs in the background. This
worker is responsible for pulling items off the schedule/delayed queue and adding
them to the queue for resque. This means that for delayed or scheduled jobs to be
executed, the worker needs to be running.

A basic "up-and-running" resque-scheduler.php file is included that sets up a
running worker environment is included in the root directory. It accepts many
of the same environment variables as php-resque:

* `REDIS_BACKEND` - Redis server to connect to
* `LOGGING` - Enable logging to STDOUT
* `VERBOSE` - Enable verbose logging
* `VVERBOSE` - Enable very verbose logging
* `INTERVAL` - Sleep for this long before checking scheduled/delayed queues
* `APP_INCLUDE` - Include this file when starting (to launch your app)
* `PIDFILE` - Write the PID of the worker out to this file

The resque-scheduler worker requires resque to function. The demo
resque-scheduler.php worker allows you to supply a `RESQUE_PHP` environment
variable with the path to Resque.php. If not supplied and resque is not already
loaded, resque-scheduler will attempt to load it from your include path
(`require_once 'Resque/Resque.php';'`)

It's easy to start the resque-scheduler worker using resque-scheduler.php:
    $ RESQUE_PHP=../resque/lib/Resque/Resque.php php resque-scheduler.php

## Event/Hook System

php-resque-scheduler uses the same event system used by php-resque and exposes
the following events.

### afterSchedule

Called after a job has been added to the schedule. Arguments passed are the
timestamp, queue of the job, the class name of the job, and the job's arguments.

### beforeDelayedEnqueue

Called immediately after a job has been pulled off the delayed queue and right
before the job is added to the queue in resque. Arguments passed are the queue
of the job, the class name of the job, and the job's arguments.

## Contributors ##

* chrisboulton
* rayward
* atorres757
* tonypiper
* biinari
* cballou