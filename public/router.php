<?php
declare(strict_types=1);
/**
 * Router script for PHP's built-in development server (php -S ... public/router.php), standing in
 * for the web server's rewrite rules. Existing files under /assets/ whose path matches a strict
 * whitelist are served directly by returning false; everything else goes to index.php.
 * Used for local runs and CI; a production web server routes requests to index.php itself.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (
    is_string($path) &&
    preg_match('#^/assets/[a-zA-Z0-9._/-]+$#D', $path) &&
    !str_contains($path, '..') &&
    is_file(__DIR__ . $path)
) {
    return false;
}
require __DIR__ . '/index.php';
