<?php

declare(strict_types=1);

/*
 * Router script for PHP's built-in server, used by `manage.php serve`.
 *
 * Existing files under public/ are served as they are, except hidden files
 * such as .htaccess; every other request goes to the front controller,
 * public/index.php.
 */

$root = (string) realpath((string) $_SERVER['DOCUMENT_ROOT']);
$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
$file = realpath($root . $path);

if (
    $path !== '/'
    && !str_contains($path, '/.')
    && $file !== false
    && is_file($file)
    && str_starts_with($file, $root . DIRECTORY_SEPARATOR)
) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . DIRECTORY_SEPARATOR . 'index.php';

require $root . DIRECTORY_SEPARATOR . 'index.php';
