<?php

declare(strict_types=1);

namespace Dscs\Docs;

/**
 * Ordre de lecture défini dans config/site.php, avec les titres dans la langue courante :
 * sidebar, fil d'Ariane, précédent/suivant.
 */
final class Navigation
{
    /** @var list<array{title: string, pages: array<string, string>}> */
    public readonly array $sections;
    /** @var array<string, array{title: string, section: string}> */
    private array $flat = [];

    /**
     * @param list<array{title: string|array<string, string>, pages: array<string, string|array<string, string>>}> $config
     */
    public function __construct(array $config, string $lang, string $fallback)
    {
        $sections = [];
        foreach ($config as $section) {
            $sectionTitle = self::pick($section['title'], $lang, $fallback);
            $pages = [];
            foreach ($section['pages'] as $slug => $titles) {
                $pages[$slug] = self::pick($titles, $lang, $fallback);
                $this->flat[$slug] = ['title' => $pages[$slug], 'section' => $sectionTitle];
            }
            $sections[] = ['title' => $sectionTitle, 'pages' => $pages];
        }
        $this->sections = $sections;
    }

    /** @return array<string, array{title: string, section: string}> */
    public function flat(): array
    {
        return $this->flat;
    }

    public function has(string $slug): bool
    {
        return isset($this->flat[$slug]);
    }

    public function title(string $slug): ?string
    {
        return $this->flat[$slug]['title'] ?? null;
    }

    public function section(string $slug): ?string
    {
        return $this->flat[$slug]['section'] ?? null;
    }

    /** @return array{slug: string, title: string}|null */
    public function previous(string $slug): ?array
    {
        return $this->neighbour($slug, -1);
    }

    /** @return array{slug: string, title: string}|null */
    public function next(string $slug): ?array
    {
        return $this->neighbour($slug, 1);
    }

    /** @return array{slug: string, title: string}|null */
    private function neighbour(string $slug, int $offset): ?array
    {
        $slugs = array_keys($this->flat);
        $i = array_search($slug, $slugs, true);
        if ($i === false || !isset($slugs[$i + $offset])) {
            return null;
        }
        $target = $slugs[$i + $offset];
        return ['slug' => $target, 'title' => $this->flat[$target]['title']];
    }

    /** @param string|array<string, string> $value */
    private static function pick(string|array $value, string $lang, string $fallback): string
    {
        if (is_string($value)) {
            return $value;
        }
        return $value[$lang] ?? $value[$fallback] ?? (string) reset($value);
    }
}
