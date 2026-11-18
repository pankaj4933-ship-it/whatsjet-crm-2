<?php

// Fast 404 for missing static media assets before booting Composer or Laravel
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
if (
    str_starts_with($requestPath, '/media-storage/') ||
    preg_match('/\.(?:jpe?g|png|gif|ico|webp|svg|mp3|ogg|wav|mp4|webm|woff2?|ttf|eot)$/i', $requestPath)
) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo '404 Not Found';
    exit;
}

putenv('LC_ALL=en');
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
