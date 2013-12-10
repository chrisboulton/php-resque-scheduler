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

## Requirements ##

* PHP 5.3+
* Redis 2.2+
* PHP-Resque 1.2+
* Optional but Recommended: Composer

## Getting Started ##

The easiest way to work with php-resque-scheduler is when it's installed as a
Composer package inside your project. Composer isn't strictly
required, but makes life a lot easier.

If you're not familiar with Composer, please see <http://getcomposer.org/>.

1. Add php-resque-scheduler to your application's composer.json.

    ```json
    {
        ...
        "require": {
			"chrisboulton/php-resque-scheduler": "dev-master",
            "php": ">=5.3.0"
        },
        ...
    }
    ```

2. Run `composer install`.

3. If you haven't already, add the Composer autoload to your project's
   initialization file. (example)

    ```php
    require 'vendor/autoload.php';
    ```

## Delayed Jobs

To quote the documentation for the Ruby resque-scheduler:

> Delayed jobs are one-off jobs that you want to be put into a queue at some
point in the future. The classic example is sending an email:

```php
$in = 3600;
$args = array('id' => $user->id);
ResqueScheduler::enqueueIn($in, 'email', 'SendFollowUpEmail', $args);
```

The above will store the job for 1 hour in the delayed queue, and then pull the
job off and submit it to the `email` queue in Resque for processing as soon as
a worker is available.

Instead of passing a relative time in seconds, you can also supply a timestamp
as either a DateTime object or integer containing a UNIX timestamp to the
`enqueueAt` method:

```php
$time = 1332067214;
ResqueScheduler::enqueueAt($time, 'email', 'SendFollowUpEmail', $args);

$datetime = new DateTime('2012-03-18 13:21:49');
ResqueScheduler::enqueueAt(datetime, 'email', 'SendFollowUpEmail', $args);
```

**Note:** resque-scheduler does not guarantee a job will fire at the time supplied.
At the time supplied, resque-scheduler will take the job out of the delayed
queue and push it to the appropriate queue in Resque. Your next available Resque
worker will pick the job up. To keep processing as quick as possible, keep your
queues as empty as possible.

## Worker

Like resque, resque-scheduler includes a worker that runs in the background. This
worker is responsible for pulling items off the schedule/delayed queue and adding
them to the queue for resque. This means that for delayed or scheduled jobs to be
executed, the worker needs to be running.

A basic "up-and-running" resque-scheduler file that sets up a running worker
environment is included in the bin/ directory.

To start a worker, it's very similar to the Ruby version:
```sh
$ LOGGING=1 php bin/resque-scheduler
```

It accepts many of the same environment variables as php-resque:

* `REDIS_BACKEND` - Redis server to connect to
* `LOGGING` - Enable logging to STDOUT
* `VERBOSE` - Enable verbose logging
* `VVERBOSE` - Enable very verbose logging
* `INTERVAL` - Sleep for this long before checking scheduled/delayed queues
* `APP_INCLUDE` - Include this file when starting (to launch your app)
* `PIDFILE` - Write the PID of the worker out to this file

The resque-scheduler worker requires resque to function. The demo
resque-scheduler worker uses the Composer autoloader to load Resque.php.

It's easy to start the resque-scheduler worker using the included demo:
    $ bin/resque-scheduler

## Event/Hook System

php-resque-scheduler uses the same event system used by php-resque and exposes
the following additional events:

### afterSchedule

Called after a job has been added to the schedule. Arguments passed are the
timestamp, queue of the job, the class name of the job, and the job's arguments.

### beforeDelayedEnqueue

Called immediately after a job has been pulled off the delayed queue and right
before the job is added to the queue in resque. Arguments passed are the queue
of the job, the class name of the job, and the job's arguments.

## Contributors ##

* [chrisboulton](//github.com/chrisboulton)
* [rayward](//github.com/rayward)
* [atorres757](//github.com/atorres757)
* [tonypiper](//github.com/tonypiper)
* [biinari](//github.com/biinari)
* [cballou](//github.com/cballou)
* [danhunsaker](//github.com/danhunsaker)
* [evertharmeling](//github.com/evertharmeling)
* [stevelacey](//github.com/stevelacey)
