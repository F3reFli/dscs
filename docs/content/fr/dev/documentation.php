<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Écrire la documentation';
$page->description = 'Comment fonctionne ce site, comment ajouter une page dans les deux langues, et les règles de rédaction et de style.';
?>
<p>Ce site est un petit projet PHP indépendant, dans <code>docs/</code>. Pas de framework, pas de base de données, aucune
ressource chargée depuis Internet : il fonctionne hors ligne.</p>

<h2>Lancer le site</h2>

<?= $c->code('bash', <<<'SH'
make docs           # http://localhost:8080
# ou, depuis la racine du dépôt
php -S localhost:8080 -t docs/public docs/public/router.php
SH) ?>

<p>PHP 8.1 ou plus suffit pour afficher le site : le CSS compilé est versionné. Pour modifier le SCSS, installe le
compilateur (scssphp) :</p>

<?= $c->code('bash', <<<'SH'
cd docs && composer install
php bin/build-css.php            # --minify pour compresser
SH) ?>

<p>En mode <code>dev</code> (<code>docs/config/site.php</code>), le CSS est recompilé à chaque requête si un fichier
<code>.scss</code> a changé ; une erreur de compilation s'affiche en haut de la page.</p>

<h2>Fonctionnement</h2>

<?= $c->table(['Élément', 'Rôle'], [
    ['<code>public/router.php</code>', 'Sert les fichiers statiques, envoie le reste à <code>index.php</code>'],
    ['<code>src/App.php</code>', 'Lit la langue et la page dans l\'URL, charge la page, rend le layout ; sert aussi <code>/&lt;langue&gt;/search.json</code>'],
    ['<code>src/PageLoader.php</code>', 'Exécute <code>content/&lt;langue&gt;/&lt;slug&gt;.php</code> (ou la version de la langue par défaut) avec seulement <code>$page</code> et <code>$c</code> en portée'],
    ['<code>src/I18n.php</code>', 'Textes de l\'interface dans la langue courante (<code>lang/&lt;langue&gt;.php</code>)'],
    ['<code>src/Toc.php</code>', 'Ajoute un id et une ancre à chaque <code>&lt;h2&gt;</code> / <code>&lt;h3&gt;</code>, construit la table des matières'],
    ['<code>src/Navigation.php</code>', 'Ordre de lecture : menu, page précédente / suivante, recherche ; titres dans la langue courante'],
    ['<code>src/Content.php</code>', 'Les helpers <code>$c</code>'],
    ['<code>templates/</code>', '<code>layout.php</code> et ses partials (la sidebar contient le panneau Paramètres)'],
    ['<code>config/site.php</code>', 'Titre, mode dev, langues, navigation. La version est lue dans <code>pyproject.toml</code>.'],
]) ?>

<h2>Langues</h2>

<p>Chaque URL commence par la langue : <code>/fr/guide/cli</code>, <code>/en/guide/cli</code>. Le panneau <strong>Paramètres</strong>
en bas de la barre latérale bascule vers la même page dans l'autre langue. Le choix est mémorisé (cookie) : une URL sans langue
(<code>/guide/cli</code>) redirige vers la dernière langue utilisée, sinon vers la langue du navigateur, sinon vers le français.</p>

<?= $c->table(['Élément', 'Où'], [
    ['Langues disponibles', '<code>languages</code> dans <code>config/site.php</code> (la première est la langue par défaut et de secours)'],
    ['Contenu des pages', '<code>content/fr/…</code> et <code>content/en/…</code>, <strong>même slug</strong> dans les deux langues'],
    ['Titres du menu', '<code>nav</code> dans <code>config/site.php</code>, un titre par langue'],
    ['Textes de l\'interface', '<code>lang/fr.php</code>, <code>lang/en.php</code> (les clés <code>js.*</code> sont transmises à <code>app.js</code>)'],
    ['Recherche', 'un index par langue : <code>/fr/search.json</code>, <code>/en/search.json</code>'],
]) ?>

<p>Une page absente dans une langue est affichée dans la langue par défaut, avec un bandeau « page non traduite » : rien ne
casse, mais chaque page doit exister dans les deux langues.</p>

<h2>Ajouter une page</h2>

<?= $c->steps([
    '<p>Crée <code>docs/content/fr/&lt;section&gt;/&lt;nom&gt;.php</code> et sa traduction <code>docs/content/en/&lt;section&gt;/&lt;nom&gt;.php</code> (slug en minuscules, chiffres et tirets).</p>',
    '<p>Ajoute-la dans <code>docs/config/site.php</code>, à sa place dans la section, avec son titre dans chaque langue : sinon elle n\'apparaît ni dans le menu ni dans la recherche.</p>',
    '<p>Ouvre-la dans les deux langues, vérifie la table des matières et les liens.</p>',
]) ?>

<?= $c->code('php', <<<'PHP'
<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Titre de la page';
$page->description = 'Une phrase, affichée sous le titre et dans la recherche.';
?>
<p>Introduction…</p>

<h2>Première section</h2>
<?= $c->code('bash', 'dscs ls') ?>
<?= $c->callout('tip', '<p>Une astuce.</p>') ?>
PHP, 'docs/content/fr/guide/exemple.php') ?>

<p>Le <code>&lt;h1&gt;</code> vient de <code>$page->title</code> : le contenu commence aux <code>&lt;h2&gt;</code>.
<code>$page->draft = true</code> affiche un bandeau « en cours de rédaction ». Les ancres sont générées à partir des titres,
elles diffèrent donc d'une langue à l'autre : dans un lien avec ancre, utilise l'ancre de la page cible <em>dans la même langue</em>.</p>

<h2>Helpers <code>$c</code></h2>

<?= $c->table(['Helper', 'Rendu'], [
    ['<code>$c-&gt;code(\'bash\', $code, \'titre\')</code>', 'Bloc de code avec bouton copier ; les commentaires <code>#</code> (bash, toml, python) et <code>--</code> (sql) sont grisés'],
    ['<code>$c-&gt;callout(\'note|tip|warning|danger\', $html, \'titre\')</code>', 'Encadré coloré ; titre par défaut traduit'],
    ['<code>$c-&gt;kbd(\'Ctrl\', \'C\')</code>', $c->kbd('Ctrl', 'C')],
    ['<code>$c-&gt;link(\'guide/cli\', \'libellé\', \'ancre\')</code>', 'Lien interne dans la langue courante ; en rouge ondulé si la page n\'existe pas'],
    ['<code>$c-&gt;path(\'~/.config/dscs/device.json\')</code>', $c->path('~/.config/dscs/device.json')],
    ['<code>$c-&gt;table($entêtes, $lignes)</code>', 'Tableau (cellules en HTML)'],
    ['<code>$c-&gt;steps([$html, …])</code>', 'Étapes numérotées'],
    ['<code>$c-&gt;cards([[\'slug\' =&gt; …, \'text\' =&gt; …], …])</code>', 'Grille de liens'],
    ['<code>$c-&gt;badge(\'0.2\', \'accent\')</code>', $c->badge('0.2', 'accent')],
    ['<code>$c-&gt;e($texte)</code>', 'Échappement HTML'],
]) ?>

<p>Pour un bloc de code sur plusieurs lignes, utilise un <em>nowdoc</em> PHP (<code>&lt;&lt;&lt;'SH'</code> … <code>SH</code>) : le contenu
est pris tel quel, sans interpréter les <code>$</code>.</p>

<h3>Schémas</h3>

<p>Les schémas sont des SVG écrits directement dans la page, dans un <code>&lt;figure class="diagram"&gt;</code>. Pour qu'ils
suivent le thème clair / sombre, n'écris aucune couleur en dur : utilise les classes <code>diagram__box</code>,
<code>diagram__box--accent</code>, <code>diagram__label</code>, <code>diagram__sub</code>, <code>diagram__msg</code>,
<code>diagram__line</code> (<code>--return</code>, <code>--accent</code>), <code>diagram__lifeline</code>, <code>diagram__loop</code>
et <code>diagram__arrowhead</code>. Exemples : <?= $c->link('concepts/architecture') ?>, <?= $c->link('concepts/transferts') ?>.</p>

<h2>Règles de rédaction</h2>

<ul>
  <li><strong>Le code fait foi</strong> : ne documente que ce qui existe ; cite les messages d'erreur à l'identique (ils sont en
    français : la version anglaise en donne le sens) ; lance les commandes pour copier leur vraie sortie.</li>
  <li>Tutoiement en français, phrases courtes. Une page = un sujet ; renvoie vers les autres pages avec <code>$c-&gt;link()</code>.</li>
  <li>Garde les deux langues synchronisées : une modification dans l'une est reportée dans l'autre.</li>
  <li>Mets en avant les avertissements qui protègent les données : CGU de Discord, clé et index à sauvegarder, webhooks à
    ne jamais supprimer dans Discord.</li>
  <li><code>ARCHITECTURE.md</code> reste la référence technique ; cette documentation l'explique et la rend navigable.</li>
</ul>

<h2>SCSS</h2>

<ul>
  <li>Le compilateur PHP ne gère pas les modules Sass : <strong><code>@import</code> uniquement</strong>, pas de <code>@use</code>,
    <code>@forward</code> ni <code>math.div</code>.</li>
  <li>Toutes les couleurs sont des variables CSS (<code>var(--accent)</code>, <code>var(--text-muted)</code>…) définies dans
    <code>scss/abstracts/_tokens.scss</code> pour les deux thèmes. Jamais de couleur en dur dans un composant.</li>
  <li>Un composant = un fichier <code>scss/components/_nom.scss</code>, importé dans <code>app.scss</code>, nommé en BEM léger
    (<code>.bloc</code>, <code>.bloc__element</code>, <code>.bloc--variante</code>, états <code>.is-*</code>).</li>
  <li>Après modification, recompile et versionne <code>public/assets/css/app.css</code>.</li>
</ul>

<h2>Vérifier avant de publier</h2>

<?= $c->code('bash', <<<'SH'
# syntaxe de tous les fichiers PHP
find docs -path docs/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
# CSS à jour
php docs/bin/build-css.php
SH) ?>

<p>Puis parcours les pages modifiées dans le navigateur, dans les deux langues, les deux thèmes et en largeur mobile.</p>
