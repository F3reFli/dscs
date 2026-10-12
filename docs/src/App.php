<?php

declare(strict_types=1);

namespace Dscs\Docs;

/**
 * Front controller : /<langue>/<slug> -> content/<langue>/<slug>.php rendu dans templates/layout.php.
 *
 * Une URL sans langue (/guide/cli) redirige vers la langue préférée : choix mémorisé (cookie posé à
 * chaque page vue), sinon Accept-Language du navigateur, sinon la langue par défaut.
 */
final class App
{
    private const COOKIE = 'dscs_docs_lang';

    /** @var array<string, mixed> */
    private array $site;
    /** @var array<string, string> code => nom */
    private array $languages;
    private string $defaultLang;

    public function __construct(private readonly string $root)
    {
        $this->site = require $root . '/config/site.php';
        $this->site['version'] ??= self::readVersion(dirname($root) . '/pyproject.toml');
        $this->languages = $this->site['languages'] ?? ['fr' => 'Français'];
        $this->defaultLang = (string) array_key_first($this->languages);
    }

    public function handle(string $uri): void
    {
        $path = trim(rawurldecode((string) parse_url($uri, PHP_URL_PATH)), '/');
        [$lang, $rest] = array_pad(explode('/', $path, 2), 2, '');

        if (!isset($this->languages[$lang])) {
            header('Location: /' . $this->preferredLanguage() . '/' . $path, true, 302);
            return;
        }
        setcookie(self::COOKIE, $lang, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);

        $i18n = new I18n($lang, $this->root . '/lang', $this->defaultLang);
        $nav = new Navigation($this->site['nav'], $lang, $this->defaultLang);
        $view = new View($this->root . '/templates', $this->root . '/public', $this->site, $lang, $i18n);
        $content = new Content($view, $nav, $i18n, $this->root . '/content', $this->defaultLang);
        $pages = new PageLoader($this->root . '/content', $lang, $this->defaultLang, $content, $i18n);

        if ($rest === 'search.json') {
            $this->searchIndex($nav, $pages, $view);
            return;
        }

        $scssError = null;
        if (!empty($this->site['dev'])) {
            $scssError = Scss::compileIfStale($this->root . '/scss', $this->root . '/public/assets/css/app.css');
        }

        $slug = $rest === '' ? 'index' : $rest;
        $page = $pages->load($slug);
        if ($page === null) {
            http_response_code(404);
            $page = $pages->notFound('/' . $path);
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $view->render('layout', [
            'page'      => $page,
            'nav'       => $nav,
            'languages' => $this->languages,
            'scssError' => $scssError,
        ]);
    }

    private function preferredLanguage(): string
    {
        $cookie = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (isset($this->languages[$cookie])) {
            return $cookie;
        }
        foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
            $code = strtolower(substr(trim($part), 0, 2));
            if (isset($this->languages[$code])) {
                return $code;
            }
        }
        return $this->defaultLang;
    }

    /** Index de recherche plein texte de la langue courante, consommé par public/assets/js/app.js. */
    private function searchIndex(Navigation $nav, PageLoader $pages, View $view): void
    {
        $entries = [];
        foreach ($nav->flat() as $slug => $item) {
            $page = $pages->load($slug);
            if ($page === null) {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($page->html))));
            $entries[] = [
                'url'      => $view->url($slug),
                'title'    => $page->title ?: $item['title'],
                'section'  => $item['section'],
                'headings' => array_map(static fn (array $h): array => [
                    'id'   => $h['id'],
                    'text' => $h['text'],
                ], $page->toc),
                'text'     => mb_substr($text, 0, 20000),
            ];
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function readVersion(string $pyproject): string
    {
        if (is_file($pyproject) && preg_match('/^version\s*=\s*"([^"]+)"/m', (string) file_get_contents($pyproject), $m)) {
            return $m[1];
        }
        return '?';
    }
}
