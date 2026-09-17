<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Load Swoole stub only for environments WITHOUT the Swoole extension
if (! extension_loaded('swoole') && ! class_exists('Swoole\\Table')) {
    require __DIR__.'/../stubs/SwooleTable.php';
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
