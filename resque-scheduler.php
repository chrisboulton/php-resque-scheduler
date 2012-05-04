<?php

// Look for an environment variable with 
$RESQUE_PHP = getenv('RESQUE_PHP');
if (!empty($RESQUE_PHP)) {
	require_once $RESQUE_PHP;
}
// Otherwise, if we have no Resque then assume it is in the include path
else if (!class_exists('Resque')) {
	require_once 'Resque/Resque.php';
}

// Load resque-scheduler
require_once dirname(__FILE__) . '/lib/ResqueScheduler.php';
require_once dirname(__FILE__) . '/lib/ResqueScheduler/Worker.php';

$REDIS_BACKEND = getenv('REDIS_BACKEND');
if(!empty($REDIS_BACKEND)) {
	Resque::setBackend($REDIS_BACKEND);
}

// Set log level for resque-scheduler
$logLevel = 0;
$LOGGING = getenv('LOGGING');
$VERBOSE = getenv('VERBOSE');
$VVERBOSE = getenv('VVERBOSE');
if(!empty($LOGGING) || !empty($VERBOSE)) {
	$logLevel = ResqueScheduler_Worker::LOG_NORMAL;
}
else if(!empty($VVERBOSE)) {
	$logLevel = ResqueScheduler_Worker::LOG_VERBOSE;
}

// Check for jobs every $interval seconds
$interval = 5;
$INTERVAL = getenv('INTERVAL');
if(!empty($INTERVAL)) {
	$interval = $INTERVAL;
}

// Load the user's application if one exists
$APP_INCLUDE = getenv('APP_INCLUDE');
if($APP_INCLUDE) {
	if(!file_exists($APP_INCLUDE)) {
		die('APP_INCLUDE ('.$APP_INCLUDE.") does not exist.\n");
	}

	require_once $APP_INCLUDE;
}

$worker = new ResqueScheduler_Worker();
$worker->logLevel = $logLevel;

$PIDFILE = getenv('PIDFILE');
if ($PIDFILE) {
	file_put_contents($PIDFILE, getmypid()) or
		die('Could not write PID information to ' . $PIDFILE);
}

fwrite(STDOUT, "*** Starting scheduler worker\n");
$worker->work($interval);