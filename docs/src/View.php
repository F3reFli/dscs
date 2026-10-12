<?php

declare(strict_types=1);

namespace Dscs\Docs;

/**
 * Rendu des templates PHP. Dans un template : $v (cette vue), $site (config) et les variables passées.
 */
final class View
{
    /**
     * @param array<string, mixed> $site
     */
    public function __construct(
        private readonly string $templates,
        private readonly string $public,
        public readonly array $site,
        public readonly string $lang,
        public readonly I18n $i18n,
    ) {
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $file = $this->templates . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Template introuvable : $template");
        }
        ob_start();
        try {
            (function (string $__file, array $__vars): void {
                $v = $this;
                $site = $this->site;
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $vars);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $vars */
    public function partial(string $name, array $vars = []): string
    {
        return $this->render('partials/' . $name, $vars);
    }

    public function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Texte d'interface traduit (non échappé). */
    public function t(string $key, array $params = []): string
    {
        return $this->i18n->t($key, $params);
    }

    /** URL d'une page, dans la langue courante ou une autre. */
    public function url(string $slug, ?string $lang = null): string
    {
        return '/' . ($lang ?? $this->lang) . '/' . ($slug === 'index' ? '' : $slug);
    }

    /** URL d'un fichier de public/assets avec cache-busting par date de modification. */
    public function asset(string $path): string
    {
        $file = $this->public . '/assets/' . ltrim($path, '/');
        $version = is_file($file) ? (string) filemtime($file) : '0';
        return '/assets/' . ltrim($path, '/') . '?v=' . $version;
    }
}
