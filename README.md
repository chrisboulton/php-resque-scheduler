php-resque-scheduler: PHP Resque Scheduler
==========================================

php-resque-scheduler is an object-oriented namespaced port of [chrisboulton/php-resque-scheduler](https://github.com/chrisboulton/php-resque-scheduler),
which adds support for scheduling items in the future to Resque.

The namespaced PHP port of has been designed to work with the namespaced PHP port of resque,
[innogames/php-resque](https://github.com/innogames/php-resque).

php-resque-scheduler supports delayed jobs, which is the ability to push a job to the queue and have it run at a certain
timestamp, or in a number of seconds.

The php-resque-scheduler is compatible with any redis client that a suitable subset of Redis commands.
This implementation has been tested with Predis.

## Docker Setup
To use the docker setup it's recommended to have `docker-compose` installed.\
Also `make` when you want to use makefile with short and useful commands.

Disclaimer: This setup is has only limited support. It was tested under Ubuntu 22.04.1 LTS.

## Delayed Jobs

To quote the documentation for the Ruby resque-scheduler:

> Delayed jobs are one-off jobs that you want to be put into a queue at some
point in the future.

```php
use ResqueScheduler\ResqueScheduler;

$scheduler = new ResqueScheduler($your_client);
$in = 3600;
$args = [];
$scheduler->enqueueIn($in, 'yourQueue', YourClass::class, $args);
```

The above will store the job for 1 hour in the delayed queue, and then pull the
job off and submit it to the `yourQueue` queue in Resque for processing as soon as
a worker is available.

Instead of passing a relative time in seconds, you can also supply a timestamp
as either a DateTime object or integer containing a UNIX timestamp to the
`enqueueAt` method:

```php
use ResqueScheduler\ResqueScheduler;

$scheduler = new ResqueScheduler($your_client);
$time = 1332067214;
$args = []
$scheduler->enqueueAt($time, 'yourQueue', YourClass::class, $args);

$datetime = new DateTime('2012-03-18 13:21:49');
$scheduler->enqueueAt($datetime, 'yourQueue', YourClass::class, $args);
```

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

A template resque-scheduler.php file is included that needs you to provide an instance of your configured redis client.
It accepts many of the same environment variables as php-resque:

* `LOGGING` - Enable logging to STDOUT
* `VERBOSE` - Enable verbose logging
* `INTERVAL` - Sleep for this long before checking scheduled/delayed queues
* `PIDFILE` - Write the PID of the worker out to this file

The worker is also capable of handling common unix signals, so it can be run as a daemon process easily:
* `SIGTERM`, `SIGINT`, `SIGQUIT` - Clean shutdown of the scheduler
* `SIGUSR1` - Pause processing
* `SIGCONT` - Continue processing
* `SIGPIPE` - Trigger a reconnect to the redis database (e.g. useful when if the connection should be lost)

## Contributors ##

See [php-resque-scheduler](https://github.com/chrisboulton/php-resque-scheduler) for the original contributors list.
Additional contributors:
Justus Graf
