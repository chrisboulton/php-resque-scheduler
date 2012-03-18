<?php
require_once '/Users/chris/Work/php-resque/lib/Resque.php';
require_once './lib/ResqueScheduler.php';

$args = array(
	'time' => time(),
	'array' => array(
		'test' => 'test',
	),
);

ResqueScheduler::enqueueIn(5, 'Test', 'My_Job', $args);

ResqueScheduler::enqueueIn(300, 'Test', 'My_Job', $args);

exit;

$timestamp = strtotime('+2 days');
ResqueScheduler::enqueueAt(300, 'Test', 'My_Job', array(
	'123' => 'abc',
	0 => 5
));

$dt = new DateTime('+1 year');
ResqueScheduler::enqueueAt($dt, 'Test2', 'My_Job2', array(
	'123' => 'abc',
	0 => 5
));