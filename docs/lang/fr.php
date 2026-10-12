<?php

declare(strict_types=1);

/*
 * Textes de l'interface du site (hors contenu des pages). Les clés « js.* » sont transmises à app.js.
 * Les valeurs sont du texte brut, sauf mention « HTML ». {nom} = paramètre.
 */
return [
    'skip_link'          => 'Aller au contenu',
    'menu'               => 'Menu',
    'nav.label'          => 'Sommaire',
    'search.label'       => 'Rechercher dans la documentation',
    'search.placeholder' => 'Rechercher…  /',
    'theme.toggle'       => 'Changer de thème',

    'settings.title'    => 'Paramètres',
    'settings.language' => 'Langue',
    'settings.theme'    => 'Thème',
    'theme.auto'        => 'Auto',
    'theme.light'       => 'Clair',
    'theme.dark'        => 'Sombre',

    'toc.title'   => 'Sur cette page',
    'pager.label' => 'Pages voisines',
    'pager.prev'  => 'Précédent',
    'pager.next'  => 'Suivant',

    'draft.title'    => 'Page en cours de rédaction',
    'draft.body'     => 'Le contenu ci-dessous est incomplet.',
    'fallback.title' => 'Page non traduite',
    'fallback.body'  => 'Cette page n\'existe pas encore en français : la version {language} est affichée.',
    'scss.error'     => 'Erreur de compilation SCSS (le CSS précédent est servi)',

    // HTML
    'footer' => '{title} {version} — documentation locale. Source de vérité technique : <code>ARCHITECTURE.md</code> à la racine du dépôt.',

    'notfound.title'       => 'Page introuvable',
    'notfound.description' => 'Cette page n\'existe pas (ou plus).',
    // HTML ({path} est déjà échappé)
    'notfound.body'        => 'Aucune page à l\'adresse <code>{path}</code>. Utilise la recherche ou le menu.',

    'callout.note'    => 'Note',
    'callout.tip'     => 'Astuce',
    'callout.warning' => 'Attention',
    'callout.danger'  => 'Danger',
    'link.broken'     => 'Page inexistante',
    'heading.anchor'  => 'Lien vers cette section',

    'js.copy'        => 'Copier',
    'js.copied'      => 'Copié ✓',
    'js.copy_failed' => 'Impossible',
    'js.no_results'  => 'Aucun résultat',
];
