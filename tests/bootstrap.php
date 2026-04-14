<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;

$basePath = dirname(__DIR__);

// DB de tests: archivo separado, se recrea en cada test class.
$_ENV['DB_PATH'] = 'database/test.db';
$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_DEBUG'] = 'true';
$_ENV['APP_TIMEZONE'] = 'America/Santiago';
$_ENV['SESSION_LIFETIME_MINUTES'] = '480';

Config::load($basePath);
