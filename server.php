<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * @author   Taylor Otwell <taylor@laravel.com>
 */
$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)
);

// This file allows us to emulate Apache's "mod_rewrite" functionality from the
// built-in PHP web server. This provides a convenient way to test a Laravel
// application without having installed a "real" web server software here.
if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false;
}

// Fast 404 for missing media storage and static assets to prevent booting Laravel
if (
    str_starts_with($uri, '/media-storage/') ||
    preg_match('/\.(?:jpe?g|png|gif|ico|webp|svg|mp3|ogg|wav|mp4|webm|woff2?|ttf|eot)$/i', $uri)
) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo '404 Not Found';
    exit;
}

require_once __DIR__.'/public/index.php';
