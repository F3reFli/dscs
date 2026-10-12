# Documentation DSCS (PHP + SCSS, locale)

Site de documentation servi en local par le serveur intégré de PHP. Aucun réseau, aucune base de données,
aucun framework : PHP ≥ 8.1, et [scssphp](https://scssphp.github.io/scssphp/) pour compiler le SCSS.

Bilingue **français / anglais** : chaque URL commence par la langue (`/fr/…`, `/en/…`) et le panneau
**Paramètres** en bas de la barre latérale change la langue (et le thème). Le choix est mémorisé par cookie ;
une URL sans langue redirige vers la dernière langue utilisée, sinon celle du navigateur, sinon le français.

## Lancer

```bash
make docs                 # depuis la racine du dépôt → http://localhost:8080
# ou
php -S localhost:8080 -t docs/public docs/public/router.php
```

Le site fonctionne sans Composer (le CSS compilé est versionné). Pour modifier le SCSS :

```bash
cd docs && composer install      # installe scssphp (ou : php tools/composer.phar install)
php bin/build-css.php            # compile une fois (--minify pour compresser)
```

En mode `dev` (`config/site.php`), le CSS est recompilé automatiquement à chaque requête si un `.scss`
a changé ; une erreur de compilation s'affiche en haut de la page.

## Structure

```
docs/
├── config/site.php        titre, mode dev, langues, navigation (ordre des pages, titres par langue)
├── content/
│   ├── fr/                une page = un fichier PHP (slug = chemin sans .php)
│   └── en/                mêmes slugs, traduits
├── lang/                  fr.php, en.php : textes de l'interface du site
├── templates/             layout.php + partials/ (header, sidebar + paramètres, toc, pager, footer)
├── src/                   moteur (namespace Dscs\Docs)
│   ├── App.php            front controller, routage par langue, /<langue>/search.json
│   ├── I18n.php           textes de l'interface dans la langue courante
│   ├── PageLoader.php     charge content/<langue>/<slug>.php (repli sur le français)
│   ├── Content.php        helpers $c disponibles dans les pages
│   ├── Toc.php            ids + ancres des h2/h3, table des matières
│   ├── Navigation.php     sidebar, précédent/suivant
│   ├── View.php           rendu des templates, échappement, assets
│   └── Scss.php           compilation scssphp
├── scss/                  sources → public/assets/css/app.css
│   ├── abstracts/         tokens (couleurs, espacements), mixins
│   ├── base/              reset + thème, typographie
│   ├── layout/            shell, topbar, sidebar, toc, pager, footer
│   └── components/        badge, callout, cards, code, diagram, kbd, search, settings, steps, table
├── public/                racine web : index.php, router.php, assets/
├── bin/build-css.php      compilation SCSS en ligne de commande
└── QUESTIONS.md           questions ouvertes du rédacteur sur le code
```

## Écrire une page

1. Créer `content/fr/<section>/<nom>.php` et sa traduction `content/en/<section>/<nom>.php` (même slug) :

   ```php
   <?php
   /** @var Dscs\Docs\Page $page  @var Dscs\Docs\Content $c */
   $page->title = 'Titre';
   $page->description = 'Une phrase affichée sous le titre et dans la recherche.';
   ?>
   <p>Texte…</p>
   <h2>Une section</h2>
   <?= $c->code('bash', 'dscs ls') ?>
   ```

2. L'ajouter dans `config/site.php` avec son titre dans chaque langue (sinon elle n'apparaît ni dans le menu ni
   dans la recherche). Une page absente dans une langue s'affiche en français avec un bandeau « non traduite ».

Le `<h1>` vient de `$page->title` : le contenu commence aux `<h2>`. Les `<h2>`/`<h3>` alimentent
automatiquement la table des matières.

## Helpers `$c`

| Helper | Rendu |
|---|---|
| `$c->code('bash', $code, 'titre')` | bloc de code + bouton copier |
| `$c->callout('note' \| 'tip' \| 'warning' \| 'danger', $html, 'titre')` | encadré |
| `$c->kbd('Ctrl', 'C')` | touches clavier |
| `$c->link('guide/cli', 'libellé', 'ancre')` | lien interne (marqué en rouge si la page n'existe pas) |
| `$c->path('~/.config/dscs/key')` | chemin de fichier |
| `$c->table($entêtes, $lignes)` | tableau (cellules en HTML) |
| `$c->steps([$html, …])` | étapes numérotées |
| `$c->cards([['slug' => …, 'text' => …], …])` | grille de liens |
| `$c->badge('0.2', 'accent')` | badge |
| `$c->e($texte)` | échappement HTML |

## SCSS

- scssphp ne gère pas les modules Sass : **`@import` uniquement**, pas de `@use`, `@forward`, `math.div`.
- Les couleurs sont des custom properties (`var(--accent)`…) définies dans `abstracts/_tokens.scss`
  pour les thèmes clair et sombre ; ne jamais écrire une couleur en dur dans un composant.
- Un composant = un partial dans `components/`, importé dans `app.scss`, classes en BEM léger
  (`.bloc`, `.bloc__element`, `.bloc--variante`, états `.is-*`).
