<?php

declare(strict_types=1);

/*
 * Compile docs/scss/app.scss -> docs/public/assets/css/app.css
 *
 *   php docs/bin/build-css.php            (lisible)
 *   php docs/bin/build-css.php --minify   (compressé)
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use Dscs\Docs\Scss;

$root = dirname(__DIR__);
$css = $root . '/public/assets/css/app.css';

try {
    Scss::compile($root . '/scss', $css, in_array('--minify', $argv, true));
} catch (Throwable $e) {
    fwrite(STDERR, "Erreur SCSS : {$e->getMessage()}\n");
    exit(1);
}
printf("✓ %s (%.1f Ko)\n", substr($css, strlen($root) + 1), filesize($css) / 1024);
