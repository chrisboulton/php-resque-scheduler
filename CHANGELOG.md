## 5.0 ##
* Require php resque version 5.0
* Add Docker- and Makefile to run the application

## 4.0 ##
* Introduced strict types
* Added test coverage
* Dropped support for php version < 8.0
* Upgraded dependencies

## 2.1 (2018-10-05) ##
Fixed the unix signal handling, extended example process to already set up logging.

## 2.0 (2018-09-21) ##
Reworked the scheduler to be object-oriented rather than static and to accept any (compatible) redis client.
Extended the Worker to react on unix signals, extended logging for production usage.

## 1.1 (2013-03-11) ##

**Note:** This release is compatible with php-resque 1.0 through 1.2.

* Add composer.json and submit to Packagist (rayward)
* Correct issues with documentation (Chuan Ma)
* Update declarations for methods called statically to actually be static methods (atorres757)
* Implement ResqueScheduler::removeDelayed and ResqueScheduler::removeDelayedJobFromTimestamp (tonypiper)
* Correct spelling for ResqueScheduler_InvalidTimestampException (biinari)
* Correct spelling of beforeDelayedEnqueue event (cballou)

## 1.0 (2011-03-27) ##

* Initial release
