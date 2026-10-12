<?php

declare(strict_types=1);

/*
 * Chargement des classes. Fonctionne sans Composer : seul la compilation SCSS a besoin de vendor/.
 */
$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Dscs\\Docs\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
