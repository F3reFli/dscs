<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'config.toml';
$page->description = 'Every key of the configuration file: type, default value, constraints and errors.';
?>
<p>Location: <code>~/.config/dscs/config.toml</code> (or <code>$XDG_CONFIG_HOME/dscs/</code>, or <code>$DSCS_HOME/</code>).
<a href="https://toml.io/en/">TOML</a> format. Unknown keys are ignored. Reading and validation:
<code>src/dscs/config/settings.py</code>.</p>

<h2>Keys</h2>

<h3><code>webhooks</code></h3>
<?= $c->table(['', ''], [
    ['Type', 'list of strings, or a single string'],
    ['Required', 'yes, at least one URL'],
    ['Format', '<code>https://discord.com/api/webhooks/&lt;ID&gt;/&lt;token&gt;</code> (also accepted: <code>discordapp.com</code>, a version prefix <code>/api/v10/</code>, a trailing slash)'],
    ['Duplicates', 'Two entries with the same webhook ID: only the first one is kept'],
]) ?>
<p>Errors: <code>Aucun webhook dans …</code> (no webhook in the file); <code>URL de webhook invalide : …</code> (invalid
webhook URL, token masked).</p>

<h3><code>chunk_size_mb</code></h3>
<?= $c->table(['', ''], [
    ['Type', 'number (integer or decimal), in MiB'],
    ['Default', '<code>9.0</code>'],
    ['Bounds', 'strictly greater than 0, at most <code>499</code>'],
    ['Effect', 'Size of each chunk before encryption (+ 28 bytes after). Only affects later uploads.'],
]) ?>
<p>Errors: <code>chunk_size_mb doit être un nombre</code> (must be a number); <code>chunk_size_mb doit être entre 0 et 499
(actuellement X)</code> (must be between 0 and 499, currently X). Before raising it:
<?= $c->link('guide/configuration', null, 'chunk-size') ?>.</p>

<h3><code>download_dir</code></h3>
<?= $c->table(['', ''], [
    ['Type', 'string (path)'],
    ['Default', '<code>"~/Downloads/dscs"</code>'],
    ['Effect', 'Destination of downloads from the interface and of <code>dscs get</code> without <code>-o</code>. <code>~</code> is expanded; created when needed.'],
]) ?>

<h3><code>show_hidden</code></h3>
<?= $c->table(['', ''], [
    ['Type', 'boolean'],
    ['Default', '<code>false</code>'],
    ['Effect', 'Show files and folders starting with <code>.</code> when the interface starts'],
]) ?>

<h3><code>[servers]</code> and <code>[channels]</code></h3>
<?= $c->table(['', ''], [
    ['Type', 'tables: <code>"id" = "name"</code>'],
    ['Default', 'empty'],
    ['Effect', 'Readable names of Discord servers and channels, shown in the Remote tab, the upload screen and <code>dscs webhooks</code>. Discord does not give these names to webhooks: without them, the IDs are shown.'],
]) ?>
<p>Error: <code>[servers] doit être une table : "identifiant" = "nom"</code> (must be a table; same for <code>[channels]</code>).</p>

<h2>Complete example</h2>

<?= $c->code('toml', <<<'TOML'
webhooks = [
  "[webhooks-link]",
  "[webhooks-link]",
  "[webhooks-link]",
]
chunk_size_mb = 9.0
download_dir = "~/Downloads/dscs"
show_hidden = true

[servers]
"[server-id]" = "[server-name]"

[channels]
"[channel-id]" = "[channel-name]"
"[channel-id]" = "[channel-name]"
TOML) ?>

<h2>Reading errors</h2>
<?= $c->table(['Message', 'Cause'], [
    ['<code>Aucune config trouvée. Lance d\'abord : dscs init</code>', 'File missing (“no config found, run dscs init first”)'],
    ['<code>… : TOML invalide (…)</code>', 'TOML syntax error (the detail gives the line)'],
]) ?>

<h2>File permissions</h2>
<p><code>dscs init</code> creates the file with <code>600</code> permissions and restores them when it rewrites it. If you create
it yourself, remember <code>chmod 600 ~/.config/dscs/config.toml</code>: it holds the webhook tokens.</p>
