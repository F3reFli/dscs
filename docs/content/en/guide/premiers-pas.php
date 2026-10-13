<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'First steps';
$page->description = 'A complete tour: upload a folder, find it, download it, delete it.';
?>
<p>This tutorial assumes <code>dscs init</code> is done (<?= $c->link('guide/installation') ?>). We will upload a small
test folder, get it back, then delete it, first in the interface, then on the command line.</p>

<h2>Preparing a test folder</h2>

<?= $c->code('bash', <<<'SH'
mkdir -p ~/dscs-test/photos
echo "hello" > ~/dscs-test/notes.txt
head -c 20M /dev/urandom > ~/dscs-test/photos/big.bin   # 20 MB -> 3 chunks
SH) ?>

<h2>In the interface</h2>

<h3>1. Open</h3>

<?= $c->code('bash', 'dscs ~/dscs-test') ?>

<p>The <strong>Local</strong> tab shows the tree of <code>~/dscs-test</code>. The subtitle at the top shows the name of
your webhooks once Discord has answered (<code>→ [webhook]</code>).</p>

<h3>2. Mark and upload</h3>

<ul>
  <li>Move down with <?= $c->kbd('j') ?> to <code>photos</code> and mark it with <?= $c->kbd('Space') ?>: a
    <code>●</code> appears in front of it. Marking a folder marks all of its content.</li>
  <li>Mark <code>notes.txt</code> too.</li>
  <li>Press <?= $c->kbd('u') ?>: the upload screen lists the files, their size, the number of encrypted chunks and the
    vault's webhooks, all ticked. <?= $c->kbd('Space') ?> unticks the ones you do not want to use.</li>
  <li><?= $c->kbd('y') ?> to upload, <?= $c->kbd('n') ?> to cancel.</li>
</ul>

<p>The transfer screen shows the progress, the estimated time left and one line per file
(<code>✓ big.bin  (20.0 Mo, 3 morceau(x))</code>, i.e. 20.0 MB, 3 chunks). When it is done, <?= $c->kbd('Esc') ?> closes it.
During the upload, <?= $c->kbd('c') ?> cancels: the file in progress is removed from Discord.</p>

<h3>3. Find</h3>

<p><?= $c->kbd('Tab') ?> switches to the <strong>Remote</strong> tab (<em>Distant</em>): your two files are there, with their
remote folder (<code>photos</code> for <code>big.bin</code>, <code>—</code> for <code>notes.txt</code> uploaded alone),
their size, their number of chunks and the upload date.</p>

<h3>4. Download</h3>

<p>At the top of the Remote tab, the webhook panel shows which Discord server and channel hold your chunks;
<?= $c->kbd('o') ?> opens that channel.</p>

<p>Move to <code>big.bin</code> and press <?= $c->kbd('Enter') ?>: a window offers the destination folder
(<code>~/Downloads/dscs</code> by default). Keep it, type another path or pick a folder in the tree (<?= $c->kbd('Tab') ?>),
then <?= $c->kbd('Enter') ?>. The file lands in <code>~/Downloads/dscs/photos/big.bin</code>: the remote folder
<code>photos/</code> is recreated, unless you untick the matching checkbox. The message
<code>Terminé · intégrité vérifiée (SHA-256)</code> (“done, integrity verified”) confirms it is identical to the original.</p>

<h3>5. Delete</h3>

<p><?= $c->kbd('x') ?> on a line of the Remote tab, then <?= $c->kbd('y') ?>: the matching messages are deleted from
Discord. The “No” button is selected by default, since the operation cannot be undone.</p>

<p><?= $c->kbd('q') ?> quits the interface.</p>

<h2>The same on the command line</h2>

<?= $c->code('bash', <<<'SH'
dscs put ~/dscs-test/photos ~/dscs-test/notes.txt
SH) ?>

<?= $c->code('text', <<<'TXT'
  ✓ [1] photos/big.bin  (20.0 Mo)
  ✓ [2] notes.txt  (6 o)
TXT) ?>

<p>The number in brackets is the file's <strong>ID</strong>, used by the other commands. Sizes are shown in French units
(<code>o</code> = bytes, <code>Ko</code>, <code>Mo</code>, <code>Go</code>).</p>

<?= $c->code('bash', <<<'SH'
dscs ls                       # list with IDs
dscs get 1 -o [folder]        # -> [folder]/photos/big.bin
dscs rm 1                     # asks for confirmation (-y to skip it)
SH) ?>

<?= $c->code('text', <<<'TXT'
   ID      Taille  Envoyé le         Chemin
    2         6 o  12/10/2026 10:42  notes.txt
    1     20.0 Mo  12/10/2026 10:42  photos/big.bin

2 fichier(s), 20.0 Mo
TXT) ?>

<p>The columns are ID, size (<em>Taille</em>), upload date (<em>Envoyé le</em>) and path (<em>Chemin</em>).
All options: <?= $c->link('guide/cli') ?> and <?= $c->link('reference/cli') ?>.</p>

<h2>What next</h2>

<ul>
  <li>Back up your key and index: <?= $c->link('guide/sauvegarde') ?>.</li>
  <li>Add webhooks to speed up uploads: <?= $c->link('guide/configuration') ?>.</li>
  <li>Understand what Discord sees: <?= $c->link('concepts/chiffrement') ?>.</li>
</ul>
