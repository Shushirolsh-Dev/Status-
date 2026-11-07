<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && str_starts_with(realpath($file) ?: '', __DIR__)) {
    return false;
}
require __DIR__ . '/index.php';
