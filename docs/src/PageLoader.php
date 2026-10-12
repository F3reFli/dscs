<?php

declare(strict_types=1);

namespace Dscs\Docs;

/**
 * Charge content/<langue>/<slug>.php (ou, à défaut, la version de la langue par défaut) dans un scope
 * isolé où seuls $page et $c sont définis.
 */
final class PageLoader
{
    public function __construct(
        private readonly string $dir,
        private readonly string $lang,
        private readonly string $fallback,
        private readonly Content $c,
        private readonly I18n $i18n,
    ) {
    }

    public static function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)*$#', $slug);
    }

    public function load(string $slug): ?Page
    {
        if (!self::isValidSlug($slug)) {
            return null;
        }
        foreach (array_unique([$this->lang, $this->fallback]) as $lang) {
            $file = "{$this->dir}/$lang/$slug.php";
            if (is_file($file)) {
                $page = new Page($slug);
                $page->lang = $lang;
                $page->html = self::capture($file, $page, $this->c);
                [$page->html, $page->toc] = Toc::build($page->html, $this->i18n->t('heading.anchor'));
                return $page;
            }
        }
        return null;
    }

    public function notFound(string $path): Page
    {
        $page = new Page('404');
        $page->lang = $this->lang;
        $page->title = $this->i18n->t('notfound.title');
        $page->description = $this->i18n->t('notfound.description');
        $page->html = '<p>' . $this->i18n->t('notfound.body', ['path' => htmlspecialchars($path, ENT_QUOTES, 'UTF-8')]) . '</p>';
        return $page;
    }

    private static function capture(string $file, Page $page, Content $c): string
    {
        ob_start();
        try {
            (static function (string $__file, Page $page, Content $c): void {
                require $__file;
            })($file, $page, $c);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
