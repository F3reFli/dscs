<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Command line';
$page->description = 'Upload, list, download, delete and clean up without opening the interface.';
?>
<p>Every operation of the interface also exists as a command, handy for scripts and scheduled tasks.
The full option reference is in <?= $c->link('reference/cli') ?>.</p>

<h2>Uploading: <code>dscs put</code></h2>

<?= $c->code('bash', <<<'SH'
dscs put [file]                        # a file -> remote root
dscs put [folder]                      # a folder -> remote folder of the same name
dscs put [file] [folder] [file]        # several paths at once
SH) ?>

<p>Folders are walked recursively and keep their structure under their own name (not their full path):
<code>[path]/[folder]/[subfolder]/[file]</code> is stored in <code>[folder]/[subfolder]</code>. Each file shows a progress bar (in a
terminal), then its result:</p>

<?= $c->code('text', <<<'TXT'
  ✓ [14] [folder]/[subfolder]/[file]  (3.2 Mo)
  ✗ [file] : [Errno 13] Permission denied: '[path]/[folder]/[file]'
TXT) ?>

<p>A failure does not stop the other files; the exit code is then <code>1</code>. If a file changes while it is being
uploaded, it is refused (<code>… a été modifié pendant l'envoi : recommence</code>, “was modified during the upload: try
again”) and its chunks are removed from Discord.</p>

<h3>Choosing webhooks</h3>

<p>By default, chunks are spread over every webhook of the vault. <code>-w</code> (or <code>--webhook</code>, repeatable)
only uses some of them, designated by ID or by name (case-insensitive):</p>

<?= $c->code('bash', <<<'SH'
dscs put -w [webhook] [folder]                # only the webhook named [webhook]
dscs put -w [webhook-id] -w [webhook] [file]  # two webhooks, by ID and by name
SH) ?>

<p>An unknown name is refused before anything is uploaded, with the list of the vault's webhooks:
<code>Webhook inconnu : « … ». Webhooks du coffre : …</code> (“unknown webhook”). <code>dscs webhooks</code> gives their
names and IDs.</p>

<h2>Listing: <code>dscs ls</code></h2>

<?= $c->code('text', <<<'TXT'
$ dscs ls
   ID      Taille  Envoyé le         Chemin
    3      1.2 Mo  12/10/2026 09:58  [file]
   14      3.2 Mo  12/10/2026 10:05  [folder]/[subfolder]/[file]

2 fichier(s), 4.4 Mo
TXT) ?>

<p><code>ls</code> only reads the local index: it works offline. If interrupted uploads remain, a line
<code>⚠ N envoi(s) interrompu(s) laissent des morceaux sur Discord : lance « dscs gc ».</code> (“N interrupted uploads leave
chunks on Discord: run dscs gc”) is added at the end.</p>

<h2>Downloading: <code>dscs get</code></h2>

<?= $c->code('bash', <<<'SH'
dscs get [id]                     # -> download_dir/[folder]/[subfolder]/[file]
dscs get [id] -o [destination]    # -> [destination]/[folder]/[subfolder]/[file]
SH) ?>

<p>The remote folder is recreated under the destination; <code>--flat</code> puts the file directly in the destination
(<code>dscs get [id] -o [destination] --flat</code> → <code>[destination]/[file]</code>). An existing file is never overwritten: the new one becomes
<code>[name] (1).[ext]</code>. The line <code>✓ /full/path  (intégrité vérifiée)</code> confirms that the SHA-256 matches the original.</p>

<h2>Webhooks: <code>dscs webhooks</code></h2>

<p>Lists the vault's webhooks with their server, their channel, the number of chunks they hold and a link that opens the
channel in Discord. The information is asked from Discord again on every call.</p>

<?= $c->code('text', <<<'TXT'
$ dscs webhooks
[webhook]  ([webhook-id])
  Serveur : [server] ([server-id])
  Salon   : [channel-id]
  Morceaux stockés : 42
  Ouvrir  : [channel-link]

Discord ne donne pas aux webhooks le nom de leur serveur ni de leur salon : tu peux les nommer
dans config.toml, sections [servers] et [channels] ("identifiant" = "nom").
TXT) ?>

<p>The lines read: server (<em>Serveur</em>), channel (<em>Salon</em>), chunks stored, open link. See
<?= $c->link('guide/configuration', null, 'server-and-channel-names') ?> to give them readable names.</p>

<h2>Deleting: <code>dscs rm</code></h2>

<?= $c->code('bash', <<<'SH'
dscs rm [id]        # Supprimer [folder]/[subfolder]/[file] (3.2 Mo) de Discord ? [o/N]
dscs rm [id] -y     # no confirmation (scripts)
SH) ?>

<p>The confirmation accepts <code>o</code>, <code>oui</code>, <code>y</code> or <code>yes</code>; any other answer cancels
(exit code <code>1</code>).</p>

<h2>Cleaning up: <code>dscs gc</code></h2>

<p>An upload interrupted abruptly (crash, power cut, <?= $c->kbd('Ctrl', 'C') ?> at the wrong moment, network down during
the cleanup) leaves chunks on Discord. The index remembers them: <code>dscs gc</code> deletes them.</p>

<?= $c->code('text', <<<'TXT'
$ dscs gc
  ✓ 1 envoi(s) interrompu(s) nettoyé(s)
TXT) ?>

<ul>
  <li><code>Rien à nettoyer.</code> (“nothing to clean”): everything is clean.</li>
  <li><code>⚠ N restant(s) : Discord injoignable ou webhook absent de la config.</code> (“N remaining: Discord unreachable
    or webhook missing from the config”): try again later, or put the missing webhook back in the configuration.
    Exit code <code>1</code>.</li>
</ul>

<?= $c->callout('note', '<p><code>dscs gc</code> refuses to run while another dscs instance is open (interface or command), so it never mistakes an upload in progress for an interrupted one. Close it first.</p>') ?>

<h2>Devices, keys and synchronisation</h2>

<p><code>dscs device</code>, <code>dscs key</code> and <code>dscs sync</code> handle sharing the vault between devices, key
generations and the recovery kit: see <?= $c->link('guide/appareils') ?>. Every command that contacts Discord synchronises
the vault automatically (receiving at the start, publishing at the end).</p>

<h2>Interface: <code>dscs</code> / <code>dscs tui</code></h2>

<p>Without a command, <code>dscs</code> opens the interface on the current folder, and <code>dscs FOLDER</code> on that folder.
If your folder is named like a command (<code>ls</code>, <code>put</code>, <code>gc</code>…), write <code>dscs tui ls</code>
or <code>dscs ./ls</code>.</p>

<h2>In a script</h2>

<?= $c->code('bash', <<<'SH'
#!/usr/bin/env bash
# Nightly upload of a folder, keeping track of failures
set -u
if ! dscs put [folder] >> ~/dscs.log 2>&1; then
  echo "$(date): at least one file failed" >> ~/dscs.log
fi
dscs gc >> ~/dscs.log 2>&1
SH) ?>

<p>Outside a terminal, the progress bar is not shown: only the result lines are written.</p>

<h3>Exit codes</h3>

<?= $c->table(['Code', 'Meaning'], [
    ['<code>0</code>', 'Success'],
    ['<code>1</code>', 'Error (message <code>Erreur : …</code> on standard error), at least one file failed, unknown ID, confirmation refused, incomplete cleanup'],
    ['<code>2</code>', 'Invalid arguments (argparse message)'],
    ['<code>130</code>', 'Interrupted with ' . $c->kbd('Ctrl', 'C') . ' (<code>Interrompu.</code>)'],
]) ?>
