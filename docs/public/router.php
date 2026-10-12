<?php

declare(strict_types=1);

/*
 * Routeur pour le serveur intégré de PHP :
 *   php -S localhost:8080 -t docs/public docs/public/router.php
 * Les fichiers statiques existants sont servis tels quels, tout le reste passe par index.php.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false;
}
require __DIR__ . '/index.php';
