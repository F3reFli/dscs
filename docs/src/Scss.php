<?php

declare(strict_types=1);

namespace Dscs\Docs;

use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\OutputStyle;

/**
 * Compilation scss/app.scss -> public/assets/css/app.css avec scssphp.
 *
 * scssphp ne gère pas le système de modules : utiliser @import (pas @use / @forward / sass:math).
 */
final class Scss
{
    public static function available(): bool
    {
        return class_exists(Compiler::class);
    }

    public static function compile(string $scssDir, string $cssFile, bool $minify = false): void
    {
        if (!self::available()) {
            throw new \RuntimeException('scssphp absent : lance « composer install » dans docs/.');
        }
        $compiler = new Compiler();
        $compiler->setImportPaths($scssDir);
        $compiler->setOutputStyle($minify ? OutputStyle::COMPRESSED : OutputStyle::EXPANDED);
        $entry = $scssDir . '/app.scss';
        $result = $compiler->compileString((string) file_get_contents($entry), $entry);
        if (!is_dir(dirname($cssFile))) {
            mkdir(dirname($cssFile), 0775, true);
        }
        $header = "/* Généré depuis docs/scss — ne pas modifier à la main. */\n";
        file_put_contents($cssFile, $header . $result->getCss() . "\n");
    }

    /**
     * Recompile si un .scss est plus récent que le CSS. Renvoie le message d'erreur éventuel
     * (affiché en haut de la page en mode dev), ou null.
     */
    public static function compileIfStale(string $scssDir, string $cssFile): ?string
    {
        if (!self::available()) {
            return null; // pas de scssphp : on sert le CSS déjà compilé
        }
        $cssTime = is_file($cssFile) ? filemtime($cssFile) : 0;
        $stale = false;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scssDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'scss' && $file->getMTime() > $cssTime) {
                $stale = true;
                break;
            }
        }
        if (!$stale) {
            return null;
        }
        try {
            self::compile($scssDir, $cssFile);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return null;
    }
}
