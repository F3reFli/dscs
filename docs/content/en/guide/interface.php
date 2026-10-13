<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Interface (TUI)';
$page->description = 'The keyboard interface: tabs, keys, confirmation and transfer screen.';
?>
<h2>Launching</h2>

<?= $c->code('bash', <<<'SH'
dscs                  # on the current folder
dscs [folder]         # on another folder
dscs tui ls           # on a folder named like a command (ls, put, gc…)
SH) ?>

<p>The interface has a header (title, then <code>→ webhook names</code> once Discord has answered), two tabs, and a
shortcut bar at the bottom. Its labels are in French: the tabs are <strong>Local</strong> and <strong>Distant</strong> (remote).</p>

<p>At start-up, a banner plays the <code>dscs -h</code> animation (skull, padlock, DSCS) for about a second and a half,
then folds away. It blocks nothing: the tree answers the keyboard straight away and synchronisation starts at the same
time. It is skipped if the terminal is too small, or if the <code>DSCS_NO_ANIMATION</code> variable is set.</p>

<?= $c->callout('note', '<p>If the index still holds interrupted uploads, a notification says so at start-up: <code>N envoi(s) interrompu(s) : lance « dscs gc » pour nettoyer</code> (“N interrupted upload(s): run dscs gc to clean up”). See ' . $c->link('guide/cli', null, 'cleaning-up-dscs-gc') . '.</p>') ?>

<h2>Global keys</h2>

<?= $c->table(['Key', 'Action'], [
    [$c->kbd('Tab'), 'Switch between the Local and Remote tabs; inside a window, move to the next field'],
    [$c->kbd('1') . ' / ' . $c->kbd('2'), 'Go straight to the Local / Remote tab'],
    [$c->kbd('q'), 'Quit'],
]) ?>

<h2>Local tab</h2>

<p>A file tree starting at the launch folder, folders first. Each file's size is shown in grey; marked items are prefixed
with a <code>●</code>.</p>

<h3>Moving around</h3>

<?= $c->table(['Key', 'Action'], [
    [$c->kbd('j') . ' ' . $c->kbd('k') . ' / ' . $c->kbd('↓') . ' ' . $c->kbd('↑'), 'Next / previous line'],
    [$c->kbd('l') . ' / ' . $c->kbd('→'), 'Expand a folder; if already expanded, step into it'],
    [$c->kbd('h') . ' / ' . $c->kbd('←'), 'Collapse the folder; otherwise go up to the parent in the tree; at the root, go up one level on disk'],
    [$c->kbd('g') . ' / ' . $c->kbd('G'), 'Top / bottom'],
    [$c->kbd('-'), 'Make the parent folder the root of the tree'],
    [$c->kbd('~'), 'Make your home folder the root of the tree'],
    [$c->kbd('.'), 'Show / hide hidden files (hidden by default, configurable with <code>show_hidden</code>)'],
]) ?>

<h3>Marking and uploading</h3>

<?= $c->table(['Key', 'Action'], [
    [$c->kbd('Space'), 'Mark / unmark the item under the cursor, then move down one line. A marked folder = all its content, recursively.'],
    [$c->kbd('u'), 'Upload the selection; with no marks, the item under the cursor (file or folder)'],
    [$c->kbd('Enter'), 'On a file: upload it, or upload the selection if there are marks. On a folder: expand / collapse it.'],
]) ?>

<p>What gets uploaded:</p>
<ul>
  <li>a <strong>file</strong> selected on its own goes to the remote root;</li>
  <li>a <strong>folder</strong> keeps its structure: <code>project/src/main.py</code> goes into the remote folder
    <code>project/src</code>;</li>
  <li>symbolic links <em>inside</em> a folder are skipped; a file selected twice is uploaded only once.</li>
</ul>

<p>After an upload, marks are cleared and the Remote tab is refreshed.</p>

<h2>Remote tab</h2>

<h3>Webhooks: server and channel</h3>

<p>At the top of the tab, a panel lists the vault's webhooks: their <strong>name</strong>, the Discord
<strong>server</strong> and <strong>channel</strong> they post to, the number of <strong>chunks</strong> they hold, and an
<code>ouvrir ↗</code> (“open”) link to the channel (clickable in terminals that support links).</p>

<?= $c->callout('note', '<p>Discord does not give webhooks the <em>name</em> of their server or channel, only their IDs. The panel therefore shows the IDs (greyed out), unless you gave them a readable name in <code>config.toml</code>: see ' . $c->link('guide/configuration', null, 'server-and-channel-names') . '. The information is cached: it stays visible offline.</p>') ?>

<h3>Files</h3>

<p>The list of stored files, sorted by folder then name, with the columns <strong>Nom</strong> (name),
<strong>Dossier</strong> (folder, <code>—</code> for the root), <strong>Webhook</strong> (the webhook(s) holding its chunks),
<strong>Taille</strong> (size), <strong>Morceaux</strong> (chunks) and <strong>Envoyé le</strong> (uploaded on). This list
comes from the local index: showing it does not contact Discord.</p>

<?= $c->table(['Key', 'Action'], [
    [$c->kbd('j') . ' ' . $c->kbd('k'), 'Next / previous line'],
    [$c->kbd('g') . ' / ' . $c->kbd('G'), 'Top / bottom'],
    [$c->kbd('Space'), 'Mark / unmark the file under the cursor, then move down one line'],
    [$c->kbd('Enter') . ' / ' . $c->kbd('l') . ' / ' . $c->kbd('d'), 'Download the selection (or the file under the cursor): opens the destination picker'],
    [$c->kbd('x'), 'Delete the selection (or the file under the cursor) from Discord — cannot be undone'],
    [$c->kbd('o'), 'Open in Discord the channel holding the file\'s chunks'],
    [$c->kbd('r'), 'Refresh the list'],
]) ?>

<h3>Multiple selection</h3>

<p>As in the Local tab, <?= $c->kbd('Space') ?> marks files (a <code>●</code> appears in front of their name). As soon as
there are marks, <?= $c->kbd('Enter') ?> and <?= $c->kbd('x') ?> act on <strong>every marked file</strong> instead of the
file under the cursor:</p>
<ul>
  <li><strong>download</strong>: a single window to choose the destination of the whole selection, then an overall
    progress with one line per file;</li>
  <li><strong>deletion</strong>: a single confirmation (number of files, size, messages deleted), then a file-by-file
    progress. <?= $c->kbd('c') ?> stops the deletion: the remaining files are kept.</li>
</ul>
<p>A failure on one file does not stop the next ones. Marks are cleared once the operation is over.</p>

<h2>Upload screen: choosing webhooks</h2>

<p>Nothing goes over the network without confirmation. The upload screen sums up the selection (the files, at most 14
then <code>… et N autre(s)</code>, the total and the number of encrypted chunks) and lists the vault's webhooks, with
their server and channel. <strong>Tick the ones that should receive the chunks</strong>: all are ticked by default.</p>

<?= $c->table(['Key', 'Action'], [
    [$c->kbd('j') . ' ' . $c->kbd('k') . ' / ' . $c->kbd('↓') . ' ' . $c->kbd('↑'), 'Move in the webhook list'],
    [$c->kbd('g') . ' / ' . $c->kbd('G'), 'First / last webhook'],
    [$c->kbd('Space'), 'Tick / untick the highlighted webhook'],
    [$c->kbd('a'), 'Tick all / untick all'],
    [$c->kbd('y') . ' / ' . $c->kbd('o'), 'Upload'],
    [$c->kbd('n') . ' / ' . $c->kbd('Esc'), 'Cancel'],
    [$c->kbd('l') . ' / ' . $c->kbd('h'), 'Move to the buttons, then from one button to the other (' . $c->kbd('Enter') . ' presses it)'],
]) ?>

<p>A file's chunks are spread across the ticked webhooks, one upload stream per webhook: ticking several (in different
channels) speeds up the upload. At least one must be ticked. The choice is kept for the following uploads, until the
interface is closed.</p>

<h2>Download screen: choosing the destination</h2>

<p>The window is the same for one file or for a selection: the destination applies to every file.
The <strong>destination folder</strong> field is prefilled with <code>download_dir</code> (or the last folder chosen).
You can:</p>
<ul>
  <li><strong>type</strong> a path: <code>~</code> is accepted, and a relative path starts from the folder open in the
    Local tab;</li>
  <li><strong>pick</strong> a folder in the tree below, with the keyboard: <?= $c->kbd('↓') ?> or <?= $c->kbd('Tab') ?> to
    get there, then <?= $c->kbd('j') ?> <?= $c->kbd('k') ?> <?= $c->kbd('h') ?> <?= $c->kbd('l') ?> as in the Local tab.
    The field follows the cursor, and <?= $c->kbd('Enter') ?> downloads into the highlighted folder. The tree starts at
    your home folder; <?= $c->kbd('h') ?> on its root goes up one level (towards <code>/mnt</code>, for example).</li>
</ul>

<p>If the file is stored in a remote folder (<code>[folder]/</code>…), the <strong>recreate the remote folder</strong>
checkbox decides whether it lands in <code>[destination]/[folder]/</code> (ticked, the default) or directly in the
destination. The last line shows the final path; a folder that does not exist yet is created.</p>

<?= $c->table(['Key', 'Action'], [
    [$c->kbd('Enter') . ' (in the field)', 'Download'],
    [$c->kbd('↓') . ' / ' . $c->kbd('Tab'), 'Move from the field to the tree, the checkbox, the buttons (letters are typed into the field)'],
    [$c->kbd('j') . ' ' . $c->kbd('k') . ' (tree)', 'Next / previous folder; the destination follows'],
    [$c->kbd('l') . ' / ' . $c->kbd('h') . ' (tree)', 'Open / enter a folder; collapse / go up to the parent'],
    [$c->kbd('g') . ' / ' . $c->kbd('G') . ' (tree)', 'Top / bottom'],
    [$c->kbd('Enter') . ' (tree)', 'Download into the highlighted folder'],
    [$c->kbd('j') . ' ' . $c->kbd('k') . ' / ' . $c->kbd('h') . ' ' . $c->kbd('l'), 'On the checkbox or the buttons: next / previous item'],
    [$c->kbd('Space'), 'Tick / untick the “remote folder” checkbox'],
    [$c->kbd('Esc'), 'Cancel'],
]) ?>

<p>The chosen folder is kept for the following downloads, until the interface is closed. An existing file is never
overwritten: the new one becomes <code>name (1).ext</code>.</p>

<h2>Deletion confirmation</h2>

<p>Before a deletion, a window recalls the file(s) and the number of messages deleted. <?= $c->kbd('y') ?> or
<?= $c->kbd('o') ?> confirms, <?= $c->kbd('n') ?> or <?= $c->kbd('Esc') ?> cancels; the “No” button (<em>Non</em>) has the
focus by default, since the operation cannot be undone. <?= $c->kbd('h') ?> and <?= $c->kbd('l') ?> move from one button
to the other.</p>

<h2>Transfer screen</h2>

<p>It shows the current file (<code>[2/5] [file]</code>), a progress bar with estimated time left, and a log:
a green <code>✓</code> for a successful file, a red <code>✗</code> with the reason for a failure. A failure does not stop
the following files.</p>

<?= $c->table(['Key', 'Action'], [
    [$c->kbd('c'), 'Cancel the current transfer'],
    [$c->kbd('j') . ' ' . $c->kbd('k') . ' / ' . $c->kbd('g') . ' ' . $c->kbd('G'), 'Scroll the log / go to its start or end'],
    [$c->kbd('Esc') . ' / ' . $c->kbd('q'), 'Close the screen (only once the transfer is finished)'],
]) ?>

<h3>What cancelling does</h3>

<ul>
  <li><strong>Upload</strong>: the chunks already sent for the file <em>in progress</em> are deleted from Discord, and nothing
    appears in the index for it. Files completed before the cancellation stay stored.</li>
  <li><strong>Download</strong>: the partial file (<code>.part</code>) is deleted, nothing is written.</li>
</ul>

<p>If the cleanup fails (network down at the wrong moment), the upload is kept as interrupted and <code>dscs gc</code> will
finish the job later.</p>
