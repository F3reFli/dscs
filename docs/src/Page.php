<?php

declare(strict_types=1);

namespace Dscs\Docs;

/**
 * Une page de contenu. Les fichiers de content/ remplissent title, description et draft ;
 * html, toc et lang sont calculés au chargement.
 */
final class Page
{
    public string $title = '';
    public string $description = '';
    /** true : affiche un bandeau « page en cours de rédaction » */
    public bool $draft = false;
    public string $html = '';
    /** @var list<array{level: int, id: string, text: string}> */
    public array $toc = [];
    /** Langue du fichier réellement chargé (différente de la langue demandée = page non traduite) */
    public string $lang = '';

    public function __construct(public readonly string $slug)
    {
    }
}
