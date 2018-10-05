<?php

if (!defined('ROOT')) {
	define('ROOT', realpath(dirname(__FILE__)) . '/');
}

//expects autoloading to be set up
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use ResqueScheduler\Worker;

/** @var Resque $resque */
$resque = null; //instantiate your resque including the client here
$log = ROOT . "logs/resque_scheduler_worker.log";
$logger = new Logger('resque-scheduler');
$logger->pushHandler(new StreamHandler($log, Logger::DEBUG)); //set desired log level here
$resque->setLogger($logger);

// Set log level for resque-scheduler
$logLevel = 0;
$LOGGING = getenv('LOGGING');
$VERBOSE = getenv('VERBOSE');
if(!empty($LOGGING)) {
	$logLevel = Worker::LOG_NORMAL;
}
else if(!empty($VERBOSE)) {
	$logLevel = Worker::LOG_VERBOSE;
}

// Check for jobs every $interval seconds
$interval = 1;
$INTERVAL = getenv('INTERVAL');
if(!empty($INTERVAL)) {
	$interval = $INTERVAL;
}

$worker = new Worker($logLevel, $interval, $resque);
//don't forget to set your logger here!

$PIDFILE = getenv('PIDFILE');
if ($PIDFILE) {
	file_put_contents($PIDFILE, getmypid()) or
		die('Could not write PID information to ' . $PIDFILE);
}

fwrite(STDOUT, "*** Starting scheduler worker\n");
$worker->work();
