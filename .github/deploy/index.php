<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// The domain points to /home/comuonal/public_html. The Laravel application
// itself lives outside the public directory and is switched atomically.
$applicationPath = dirname(__DIR__).'/comunitarios_app/current';

if (! is_file($applicationPath.'/vendor/autoload.php')) {
    http_response_code(503);
    exit('El sitio se encuentra temporalmente en mantenimiento.');
}

if (file_exists($maintenance = $applicationPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $applicationPath.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $applicationPath.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
