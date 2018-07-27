<?php

namespace ResqueScheduler\Exceptions;

use Resque\ResqueException;
/**
* Exception thrown whenever an invalid timestamp has been passed to a job.
*
* @license		http://www.opensource.org/licenses/mit-license.php
*/
class InvalidTimestampException extends ResqueException
{

}