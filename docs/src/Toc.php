<?php

declare(strict_types=1);

namespace Dscs\Docs;

/**
 * Donne un id à chaque <h2>/<h3> (s'il n'en a pas), ajoute une ancre, et construit la table des matières.
 */
final class Toc
{
    /**
     * @return array{0: string, 1: list<array{level: int, id: string, text: string}>}
     */
    public static function build(string $html, string $anchorLabel = 'Lien vers cette section'): array
    {
        $toc = [];
        $used = [];
        $html = (string) preg_replace_callback(
            '#<h([23])([^>]*)>(.*?)</h\1>#is',
            static function (array $m) use (&$toc, &$used, $anchorLabel): string {
                [, $level, $attrs, $inner] = $m;
                if (preg_match('/\sid="([^"]+)"/', $attrs, $idMatch)) {
                    $id = $idMatch[1];
                } else {
                    $base = self::slugify(strip_tags($inner)) ?: 'section';
                    $id = $base;
                    for ($i = 2; isset($used[$id]); $i++) {
                        $id = $base . '-' . $i;
                    }
                    $attrs .= ' id="' . $id . '"';
                }
                $used[$id] = true;
                $toc[] = [
                    'level' => (int) $level,
                    'id'    => $id,
                    'text'  => trim(html_entity_decode(strip_tags($inner), ENT_QUOTES, 'UTF-8')),
                ];
                return sprintf(
                    '<h%1$s%2$s>%3$s<a class="heading-anchor" href="#%4$s" aria-label="%5$s">#</a></h%1$s>',
                    $level,
                    $attrs,
                    $inner,
                    $id,
                    htmlspecialchars($anchorLabel, ENT_QUOTES, 'UTF-8'),
                );
            },
            $html,
        );
        return [$html, $toc];
    }

    public static function slugify(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = strtr($text, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u',
            'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
            'À' => 'a', 'Â' => 'a', 'Ç' => 'c', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Î' => 'i', 'Ô' => 'o',
            'Ù' => 'u', 'Û' => 'u', 'Œ' => 'oe',
        ]);
        $text = strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }
}
