<?php

declare(strict_types=1);

namespace Dscs\Docs;

/**
 * Textes de l'interface du site dans la langue courante (lang/<code>.php), avec repli sur la
 * langue par défaut pour toute clé manquante.
 */
final class I18n
{
    /** @var array<string, string> */
    private array $strings;

    public function __construct(public readonly string $lang, string $dir, string $fallback)
    {
        $this->strings = array_merge(self::load("$dir/$fallback.php"), self::load("$dir/$lang.php"));
    }

    /** @param array<string, string|int> $params remplace {nom} dans le texte */
    public function t(string $key, array $params = []): string
    {
        $text = $this->strings[$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /** @return array<string, string> clés « js.* » sans le préfixe, pour app.js */
    public function forJs(): array
    {
        $out = [];
        foreach ($this->strings as $key => $value) {
            if (str_starts_with($key, 'js.')) {
                $out[substr($key, 3)] = $value;
            }
        }
        return $out;
    }

    /** @return array<string, string> */
    private static function load(string $file): array
    {
        return is_file($file) ? require $file : [];
    }
}
