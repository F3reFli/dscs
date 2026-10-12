<?php

declare(strict_types=1);

namespace Dscs\Docs;

/**
 * Composants disponibles dans les pages de content/ via la variable $c.
 *
 * Toutes les méthodes renvoient du HTML : à afficher avec <?= ... ?>.
 * Les paramètres nommés `$html` sont insérés tels quels ; tout le reste est échappé.
 */
final class Content
{
    public function __construct(
        private readonly View $view,
        private readonly Navigation $nav,
        private readonly I18n $i18n,
        private readonly string $contentDir,
        private readonly string $fallbackLang,
    ) {
    }

    /** Échappe du texte. */
    public function e(string $text): string
    {
        return $this->view->e($text);
    }

    /** Bloc de code avec bouton « copier » (ajouté par app.js). $lang : bash, python, toml, sql, php, text… */
    public function code(string $lang, string $code, ?string $title = null): string
    {
        $caption = $title !== null ? '<figcaption class="code__title">' . $this->e($title) . '</figcaption>' : '';
        return sprintf(
            '<figure class="code" data-lang="%1$s">%2$s<pre class="code__pre"><code class="language-%1$s">%3$s</code></pre></figure>',
            $this->e($lang),
            $caption,
            self::highlightComments($lang, $this->e(rtrim($code, "\n"))),
        );
    }

    /** Coloration minimale et locale : seulement les commentaires de fin de ligne. */
    private static function highlightComments(string $lang, string $escaped): string
    {
        $marker = match ($lang) {
            'bash', 'sh', 'toml', 'python', 'text' => '#',
            'sql' => '--',
            default => null,
        };
        if ($marker === null) {
            return $escaped;
        }
        // un commentaire commence en début de ligne ou après un espace (évite les # dans les URLs)
        return (string) preg_replace(
            '/(^|\s)(' . preg_quote($marker, '/') . '[^\n]*)$/m',
            '$1<span class="tok-comment">$2</span>',
            $escaped,
        );
    }

    /** Encadré. $type : note | tip | warning | danger. */
    public function callout(string $type, string $html, ?string $title = null): string
    {
        $type = in_array($type, ['note', 'tip', 'warning', 'danger'], true) ? $type : 'note';
        return sprintf(
            '<aside class="callout callout--%s" role="note"><p class="callout__title">%s</p><div class="callout__body">%s</div></aside>',
            $type,
            $this->e($title ?? $this->i18n->t('callout.' . $type)),
            $html,
        );
    }

    /** Touches clavier : $c->kbd('j') ou $c->kbd('Ctrl', 'C') (combinaison). */
    public function kbd(string ...$keys): string
    {
        return implode('<span class="kbd-plus">+</span>', array_map(fn (string $k): string => '<kbd>' . $this->e($k) . '</kbd>', $keys));
    }

    /**
     * Lien interne vers une page, dans la langue courante. Libellé par défaut : titre de la page dans la nav.
     * Un lien vers une page inexistante (ni traduite, ni dans la langue par défaut) est marqué (classe is-broken).
     */
    public function link(string $slug, ?string $label = null, string $anchor = ''): string
    {
        $exists = is_file("{$this->contentDir}/{$this->view->lang}/$slug.php")
            || is_file("{$this->contentDir}/{$this->fallbackLang}/$slug.php");
        $href = $this->view->url($slug) . ($anchor !== '' ? '#' . $anchor : '');
        return sprintf(
            '<a href="%s"%s>%s</a>',
            $this->e($href),
            $exists ? '' : ' class="is-broken" title="' . $this->e($this->i18n->t('link.broken')) . '"',
            $this->e($label ?? $this->nav->title($slug) ?? $slug),
        );
    }

    /** Chemin de fichier ou de dossier. */
    public function path(string $path): string
    {
        return '<code class="path">' . $this->e($path) . '</code>';
    }

    /**
     * Tableau. Les cellules sont du HTML (utiliser $c->e() pour du texte brut contenant < ou &).
     *
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): string
    {
        $head = implode('', array_map(static fn (string $h): string => "<th scope=\"col\">$h</th>", $headers));
        $body = implode('', array_map(
            static fn (array $row): string => '<tr>' . implode('', array_map(static fn (string $cell): string => "<td>$cell</td>", $row)) . '</tr>',
            $rows,
        ));
        return "<div class=\"table-wrap\"><table><thead><tr>$head</tr></thead><tbody>$body</tbody></table></div>";
    }

    /**
     * Étapes numérotées.
     *
     * @param list<string> $steps HTML de chaque étape
     */
    public function steps(array $steps): string
    {
        return '<ol class="steps">' . implode('', array_map(static fn (string $s): string => "<li>$s</li>", $steps)) . '</ol>';
    }

    /**
     * Grille de cartes liens (page d'accueil, sommaires).
     *
     * @param list<array{slug: string, title?: string, text: string}> $cards
     */
    public function cards(array $cards): string
    {
        $html = '';
        foreach ($cards as $card) {
            $html .= sprintf(
                '<a class="card" href="%s"><span class="card__title">%s</span><span class="card__text">%s</span></a>',
                $this->e($this->view->url($card['slug'])),
                $this->e($card['title'] ?? $this->nav->title($card['slug']) ?? $card['slug']),
                $this->e($card['text']),
            );
        }
        return '<div class="cards">' . $html . '</div>';
    }

    /** Badge court (ex. version, statut). $tone : default | accent | success | warning | danger. */
    public function badge(string $text, string $tone = 'default'): string
    {
        return '<span class="badge badge--' . $this->e($tone) . '">' . $this->e($text) . '</span>';
    }
}
