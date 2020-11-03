<?php

declare(strict_types=1);

$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('Resque\\', __DIR__);

$build = __DIR__ . '/../';

$logger = new Monolog\Logger('test');
$logger->pushHandler(new Monolog\Handler\StreamHandler($build . '/test.log'));

$settings = new Resque\Test\Settings();
$settings->setLogger($logger);
$settings->fromEnvironment();
$settings->setBuildDir($build);
$settings->checkBuildDir();
$settings->dumpConfig();
$settings->catchSignals();
$settings->startRedis(); // registers shutdown function

Resque\Test::setSettings($settings);
