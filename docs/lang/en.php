<?php

declare(strict_types=1);

/*
 * Site interface strings (not page content). « js.* » keys are passed to app.js.
 * Values are plain text unless marked « HTML ». {name} = parameter.
 */
return [
    'skip_link'          => 'Skip to content',
    'menu'               => 'Menu',
    'nav.label'          => 'Contents',
    'search.label'       => 'Search the documentation',
    'search.placeholder' => 'Search…  /',
    'theme.toggle'       => 'Toggle theme',

    'settings.title'    => 'Settings',
    'settings.language' => 'Language',
    'settings.theme'    => 'Theme',
    'theme.auto'        => 'Auto',
    'theme.light'       => 'Light',
    'theme.dark'        => 'Dark',

    'toc.title'   => 'On this page',
    'pager.label' => 'Neighbouring pages',
    'pager.prev'  => 'Previous',
    'pager.next'  => 'Next',

    'draft.title'    => 'Work in progress',
    'draft.body'     => 'The content below is incomplete.',
    'fallback.title' => 'Not translated yet',
    'fallback.body'  => 'This page is not available in English yet: showing the {language} version.',
    'scss.error'     => 'SCSS compilation error (serving the previous CSS)',

    // HTML
    'footer' => '{title} {version} — local documentation. Technical source of truth: <code>ARCHITECTURE.md</code> at the root of the repository.',

    'notfound.title'       => 'Page not found',
    'notfound.description' => 'This page does not exist (anymore).',
    // HTML ({path} is already escaped)
    'notfound.body'        => 'There is no page at <code>{path}</code>. Use the search or the menu.',

    'callout.note'    => 'Note',
    'callout.tip'     => 'Tip',
    'callout.warning' => 'Warning',
    'callout.danger'  => 'Danger',
    'link.broken'     => 'Missing page',
    'heading.anchor'  => 'Link to this section',

    'js.copy'        => 'Copy',
    'js.copied'      => 'Copied ✓',
    'js.copy_failed' => 'Failed',
    'js.no_results'  => 'No results',
];
