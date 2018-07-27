<?php

if (!defined('ROOT')) {
	define('ROOT', realpath(dirname(__FILE__)) . '/');
}

require_once ROOT . 'lib/ResqueScheduler.php';
require_once ROOT . 'lib/Worker.php';
require_once ROOT . 'lib/Exceptions/InvalidTimestampException.php';

$resque = null; //instantiate your resque including the client here

// Set log level for resque-scheduler
$logLevel = 0;
$LOGGING = getenv('LOGGING');
$VERBOSE = getenv('VERBOSE');
if(!empty($LOGGING)) {
	$logLevel = ResqueScheduler\Worker::LOG_NORMAL;
}
else if(!empty($VERBOSE)) {
	$logLevel = ResqueScheduler\Worker::LOG_VERBOSE;
}

// Check for jobs every $interval seconds
$interval = 1;
$INTERVAL = getenv('INTERVAL');
if(!empty($INTERVAL)) {
	$interval = $INTERVAL;
}

$worker = new ResqueScheduler\Worker($logLevel, $interval, $resque);

$PIDFILE = getenv('PIDFILE');
if ($PIDFILE) {
	file_put_contents($PIDFILE, getmypid()) or
		die('Could not write PID information to ' . $PIDFILE);
}

fwrite(STDOUT, "*** Starting scheduler worker\n");
$worker->work();
