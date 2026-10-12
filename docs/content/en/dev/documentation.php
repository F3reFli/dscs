<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Writing the docs';
$page->description = 'How this site works, how to add a page in both languages, and the writing and styling rules.';
?>
<p>This site is a small standalone PHP project, in <code>docs/</code>. No framework, no database, no resource loaded from the
Internet: it works offline.</p>

<h2>Running the site</h2>

<?= $c->code('bash', <<<'SH'
make docs           # http://localhost:8080
# or, from the root of the repository
php -S localhost:8080 -t docs/public docs/public/router.php
SH) ?>

<p>PHP 8.1 or newer is enough to serve the site: the compiled CSS is versioned. To change the SCSS, install the compiler
(scssphp):</p>

<?= $c->code('bash', <<<'SH'
cd docs && composer install
php bin/build-css.php            # --minify to compress
SH) ?>

<p>In <code>dev</code> mode (<code>docs/config/site.php</code>), the CSS is recompiled on each request if a <code>.scss</code> file
changed; a compilation error is shown at the top of the page.</p>

<h2>How it works</h2>

<?= $c->table(['Part', 'Role'], [
    ['<code>public/router.php</code>', 'Serves static files, sends everything else to <code>index.php</code>'],
    ['<code>src/App.php</code>', 'Reads the language and the page from the URL, loads the page, renders the layout; also serves <code>/&lt;lang&gt;/search.json</code>'],
    ['<code>src/PageLoader.php</code>', 'Runs <code>content/&lt;lang&gt;/&lt;slug&gt;.php</code> (or the default-language version) with only <code>$page</code> and <code>$c</code> in scope'],
    ['<code>src/I18n.php</code>', 'Interface strings of the current language (<code>lang/&lt;lang&gt;.php</code>)'],
    ['<code>src/Toc.php</code>', 'Adds an id and an anchor to every <code>&lt;h2&gt;</code> / <code>&lt;h3&gt;</code>, builds the table of contents'],
    ['<code>src/Navigation.php</code>', 'Reading order: menu, previous / next page, search; titles in the current language'],
    ['<code>src/Content.php</code>', 'The <code>$c</code> helpers'],
    ['<code>templates/</code>', '<code>layout.php</code> and its partials (the sidebar holds the Settings panel)'],
    ['<code>config/site.php</code>', 'Title, dev mode, languages, navigation. The version is read from <code>pyproject.toml</code>.'],
]) ?>

<h2>Languages</h2>

<p>Every URL starts with the language: <code>/fr/guide/cli</code>, <code>/en/guide/cli</code>. The <strong>Settings</strong> panel at the
bottom of the sidebar switches to the same page in the other language. The choice is remembered (cookie): a URL without a
language (<code>/guide/cli</code>) redirects to the last language used, otherwise to the browser's language, otherwise to
French.</p>

<?= $c->table(['Item', 'Where'], [
    ['Available languages', '<code>languages</code> in <code>config/site.php</code> (the first one is the default and fallback language)'],
    ['Page content', '<code>content/fr/…</code> and <code>content/en/…</code>, <strong>same slug</strong> in both languages'],
    ['Menu titles', '<code>nav</code> in <code>config/site.php</code>, one title per language'],
    ['Interface strings', '<code>lang/fr.php</code>, <code>lang/en.php</code> (<code>js.*</code> keys are passed to <code>app.js</code>)'],
    ['Search', 'one index per language: <code>/fr/search.json</code>, <code>/en/search.json</code>'],
]) ?>

<p>A page missing in a language is shown in the default language, with a “not translated yet” banner: nothing breaks, but
every page should exist in both languages.</p>

<h2>Adding a page</h2>

<?= $c->steps([
    '<p>Create <code>docs/content/fr/&lt;section&gt;/&lt;name&gt;.php</code> and its translation <code>docs/content/en/&lt;section&gt;/&lt;name&gt;.php</code> (slug in lowercase, digits and hyphens).</p>',
    '<p>Add it to <code>docs/config/site.php</code>, in its place in the section, with its title in each language: otherwise it appears neither in the menu nor in the search.</p>',
    '<p>Open it in both languages, check the table of contents and the links.</p>',
]) ?>

<?= $c->code('php', <<<'PHP'
<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Page title';
$page->description = 'One sentence, shown under the title and in the search.';
?>
<p>Introduction…</p>

<h2>First section</h2>
<?= $c->code('bash', 'dscs ls') ?>
<?= $c->callout('tip', '<p>A tip.</p>') ?>
PHP, 'docs/content/en/guide/example.php') ?>

<p>The <code>&lt;h1&gt;</code> comes from <code>$page->title</code>: content starts at the <code>&lt;h2&gt;</code> level.
<code>$page->draft = true</code> shows a “work in progress” banner. Anchors are generated from the headings, so they differ
between languages: in a link with an anchor, use the anchor of the target page <em>in the same language</em>.</p>

<h2><code>$c</code> helpers</h2>

<?= $c->table(['Helper', 'Output'], [
    ['<code>$c-&gt;code(\'bash\', $code, \'title\')</code>', 'Code block with a copy button; <code>#</code> comments (bash, toml, python) and <code>--</code> comments (sql) are greyed out'],
    ['<code>$c-&gt;callout(\'note|tip|warning|danger\', $html, \'title\')</code>', 'Coloured box; default title translated'],
    ['<code>$c-&gt;kbd(\'Ctrl\', \'C\')</code>', $c->kbd('Ctrl', 'C')],
    ['<code>$c-&gt;link(\'guide/cli\', \'label\', \'anchor\')</code>', 'Internal link in the current language; red wavy underline if the page does not exist'],
    ['<code>$c-&gt;path(\'~/.config/dscs/device.json\')</code>', $c->path('~/.config/dscs/device.json')],
    ['<code>$c-&gt;table($headers, $rows)</code>', 'Table (cells are HTML)'],
    ['<code>$c-&gt;steps([$html, …])</code>', 'Numbered steps'],
    ['<code>$c-&gt;cards([[\'slug\' =&gt; …, \'text\' =&gt; …], …])</code>', 'Grid of links'],
    ['<code>$c-&gt;badge(\'0.2\', \'accent\')</code>', $c->badge('0.2', 'accent')],
    ['<code>$c-&gt;e($text)</code>', 'HTML escaping'],
]) ?>

<p>For a multi-line code block, use a PHP <em>nowdoc</em> (<code>&lt;&lt;&lt;'SH'</code> … <code>SH</code>): the content is taken as
is, without interpreting <code>$</code>.</p>

<h3>Diagrams</h3>

<p>Diagrams are SVG written directly in the page, inside a <code>&lt;figure class="diagram"&gt;</code>. To follow the light / dark
theme, never hard-code a colour: use the classes <code>diagram__box</code>, <code>diagram__box--accent</code>,
<code>diagram__label</code>, <code>diagram__sub</code>, <code>diagram__msg</code>, <code>diagram__line</code> (<code>--return</code>,
<code>--accent</code>), <code>diagram__lifeline</code>, <code>diagram__loop</code> and <code>diagram__arrowhead</code>. Examples:
<?= $c->link('concepts/architecture') ?>, <?= $c->link('concepts/transferts') ?>.</p>

<h2>Writing rules</h2>

<ul>
  <li><strong>The code is the source of truth</strong>: only document what exists; quote error messages verbatim (they are in
    French, give their meaning in English); run commands to copy their real output.</li>
  <li>Short sentences, one topic per page; link to other pages with <code>$c-&gt;link()</code>. French pages use the informal
    “tu”.</li>
  <li>Keep both languages in sync: a change in one is made in the other.</li>
  <li>Highlight the warnings that protect data: Discord's terms, key and index to back up, webhooks never to delete in Discord.</li>
  <li><code>ARCHITECTURE.md</code> remains the technical reference; this documentation explains it and makes it navigable.</li>
</ul>

<h2>SCSS</h2>

<ul>
  <li>The PHP compiler does not support Sass modules: <strong><code>@import</code> only</strong>, no <code>@use</code>,
    <code>@forward</code> or <code>math.div</code>.</li>
  <li>All colours are CSS variables (<code>var(--accent)</code>, <code>var(--text-muted)</code>…) defined in
    <code>scss/abstracts/_tokens.scss</code> for both themes. Never a hard-coded colour in a component.</li>
  <li>One component = one <code>scss/components/_name.scss</code> file, imported in <code>app.scss</code>, light BEM naming
    (<code>.block</code>, <code>.block__element</code>, <code>.block--variant</code>, <code>.is-*</code> states).</li>
  <li>After a change, recompile and commit <code>public/assets/css/app.css</code>.</li>
</ul>

<h2>Checking before publishing</h2>

<?= $c->code('bash', <<<'SH'
# syntax of every PHP file
find docs -path docs/vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
# CSS up to date
php docs/bin/build-css.php
SH) ?>

<p>Then browse the changed pages, in both languages, both themes and at mobile width.</p>
