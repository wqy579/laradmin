<?php

// Custom server.php for Laravel development server
$publicPath = __DIR__;

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Always pass to Laravel for routing
require_once $publicPath.'/index.php';
