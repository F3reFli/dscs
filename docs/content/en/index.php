<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'DSCS';
$page->description = 'Discord Server Cloud Storage: your files, encrypted, stored on your own Discord server.';
?>
<p>
  DSCS splits your files into chunks, encrypts each chunk with AES-256-GCM and sends it as an attachment to a
  Discord channel, through one or more webhooks. A local index keeps the map of what has been sent. Everything is
  driven from the terminal: a vim-style keyboard interface, or plain commands.
</p>

<?= $c->callout('danger', <<<'HTML'
<p>Storing files on Discord is not a use its terms of service intend: the account or server may be banned and the
messages deleted. <strong>Never keep your only copy of anything on DSCS.</strong></p>
HTML, 'Read this first') ?>

<?= $c->callout('note', <<<'HTML'
<p>The <code>dscs</code> program itself speaks French: its prompts and messages are quoted verbatim in this documentation,
with their English meaning alongside.</p>
HTML) ?>

<h2>In three commands</h2>

<?= $c->code('bash', <<<'SH'
pipx install .        # from the project folder
dscs init             # paste the URL of one or more webhooks
dscs [folder]         # open the interface on this folder
SH) ?>

<p>Details are in <?= $c->link('guide/installation') ?>, then <?= $c->link('guide/premiers-pas') ?> walks you through
your first upload.</p>

<h2>What DSCS does</h2>

<ul>
  <li><strong>End-to-end encryption</strong>: Discord sees neither the content, nor the name, nor the extension of your files.
    Each chunk has a random name <code>xxxxxxxxxxxxxxxx.bin</code>.</li>
  <li><strong>Verified integrity</strong>: a modified, moved or swapped chunk is detected, and the rebuilt file is checked
    with SHA-256 before it is written.</li>
  <li><strong>Never overwrites</strong>: a download never replaces an existing file
    (<code>notes.md</code> becomes <code>notes (1).md</code>).</li>
  <li><strong>Network resilient</strong>: Discord rate limits (429), server errors and dropped connections are handled
    automatically, with retries.</li>
  <li><strong>Parallel uploads</strong>: with several webhooks, chunks are sent on all of them at the same time.</li>
  <li><strong>Nothing forgotten</strong>: an interrupted upload (crash, power cut) leaves a trace in the index and
    <code>dscs gc</code> cleans its chunks off Discord.</li>
  <li><strong>Several devices</strong>: the same vault on your PC, your laptop or with a trusted person, synchronised
    through Discord, without ever sending a key (<?= $c->link('guide/appareils') ?>).</li>
</ul>

<h2>Never lose access</h2>

<p>Each device has its own key, which opens the vault's keyring. If you lose access on <em>all</em> your devices,
everything stored becomes <strong>permanently unreadable</strong>. Two safeguards:</p>
<ul>
  <li>a <strong>recovery kit</strong>, kept offline: <code>dscs key recovery</code>;</li>
  <li>a <strong>second device</strong> in the vault: <code>dscs device add</code>.</li>
</ul>

<p>See <?= $c->link('guide/sauvegarde') ?>.</p>

<h2>Where to go next</h2>

<?= $c->cards([
    ['slug' => 'guide/installation', 'text' => 'Install dscs and create your first webhook.'],
    ['slug' => 'guide/premiers-pas', 'text' => 'Upload, find, download and delete a file.'],
    ['slug' => 'guide/interface', 'text' => 'Every key of the interface.'],
    ['slug' => 'guide/cli', 'text' => 'Use dscs without the interface, in scripts.'],
    ['slug' => 'guide/appareils', 'text' => 'The same vault on several devices.'],
    ['slug' => 'concepts/architecture', 'text' => 'How the code is organised, layer by layer.'],
    ['slug' => 'concepts/chiffrement', 'text' => 'What is protected, and what is not.'],
]) ?>
