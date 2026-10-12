<?php

declare(strict_types=1);

/*
 * Configuration du site de documentation.
 *
 * `languages` : langues disponibles ; la première est la langue par défaut (et de secours quand
 * une page n'est pas encore traduite). Chaque langue a son dossier content/<code>/ et lang/<code>.php.
 *
 * `nav` définit l'ordre de lecture (sidebar, pages précédente/suivante, recherche).
 * Chaque clé est un slug = chemin du fichier dans content/<langue>/ sans « .php », identique dans
 * toutes les langues. Les titres sont donnés par langue.
 */
return [
    'title'   => 'DSCS',
    'tagline' => 'Discord Server Cloud Storage',
    // null : lu dans ../pyproject.toml
    'version' => null,
    // true : recompile scss/ à chaque requête si un fichier a changé (nécessite composer install)
    'dev'     => true,

    'languages' => [
        'fr' => 'Français',
        'en' => 'English',
    ],

    'nav' => [
        [
            'title' => ['fr' => 'Démarrer', 'en' => 'Getting started'],
            'pages' => [
                'index'              => ['fr' => 'Accueil', 'en' => 'Home'],
                'guide/installation' => ['fr' => 'Installation', 'en' => 'Installation'],
                'guide/premiers-pas' => ['fr' => 'Premiers pas', 'en' => 'First steps'],
            ],
        ],
        [
            'title' => ['fr' => 'Utiliser', 'en' => 'Usage'],
            'pages' => [
                'guide/interface'       => ['fr' => 'Interface (TUI)', 'en' => 'Interface (TUI)'],
                'guide/cli'             => ['fr' => 'Ligne de commande', 'en' => 'Command line'],
                'guide/configuration'   => ['fr' => 'Configuration', 'en' => 'Configuration'],
                'guide/appareils'       => ['fr' => 'Appareils et partage', 'en' => 'Devices and sharing'],
                'guide/sauvegarde'      => ['fr' => 'Sauvegarde et restauration', 'en' => 'Backup and restore'],
                'guide/depannage'       => ['fr' => 'Dépannage', 'en' => 'Troubleshooting'],
                'guide/desinstallation' => ['fr' => 'Désinstallation', 'en' => 'Uninstalling'],
            ],
        ],
        [
            'title' => ['fr' => 'Comprendre', 'en' => 'Concepts'],
            'pages' => [
                'concepts/architecture' => ['fr' => 'Architecture', 'en' => 'Architecture'],
                'concepts/chiffrement'  => ['fr' => 'Chiffrement', 'en' => 'Encryption'],
                'concepts/index'        => ['fr' => 'Index SQLite', 'en' => 'SQLite index'],
                'concepts/synchronisation' => ['fr' => 'Synchronisation', 'en' => 'Synchronisation'],
                'concepts/discord'      => ['fr' => 'Discord et webhooks', 'en' => 'Discord and webhooks'],
                'concepts/transferts'   => ['fr' => 'Transferts', 'en' => 'Transfers'],
            ],
        ],
        [
            'title' => ['fr' => 'Référence', 'en' => 'Reference'],
            'pages' => [
                'reference/cli'     => ['fr' => 'Commandes', 'en' => 'Commands'],
                'reference/config'  => ['fr' => 'config.toml', 'en' => 'config.toml'],
                'reference/erreurs' => ['fr' => 'Erreurs', 'en' => 'Errors'],
                'reference/api'     => ['fr' => 'API Python (Vault)', 'en' => 'Python API (Vault)'],
            ],
        ],
        [
            'title' => ['fr' => 'Contribuer', 'en' => 'Contributing'],
            'pages' => [
                'dev/contribuer'    => ['fr' => 'Mise en place', 'en' => 'Getting set up'],
                'dev/tests'         => ['fr' => 'Tests', 'en' => 'Tests'],
                'dev/documentation' => ['fr' => 'Écrire la documentation', 'en' => 'Writing the docs'],
                'dev/roadmap'       => ['fr' => 'Feuille de route', 'en' => 'Roadmap'],
            ],
        ],
    ],
];
