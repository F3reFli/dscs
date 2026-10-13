<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Roadmap';
$page->description = 'Planned improvements, by order of value, with what they solve and design leads.';
?>
<p>None of the following exists yet. The order reflects the estimated value.</p>

<?= $c->callout('tip', '<p>Already done since version 0.2: vault shared between devices, encrypted index synchronisation on Discord, passphrase, rotation and revocation, recovery kit (' . $c->link('guide/appareils') . ').</p>', 'Done') ?>

<h2>1. Faster uploads</h2>

<p><strong>Done in 0.4</strong>: the chunks of every file of an upload go through a single queue, and all selected webhooks
work at the same time, even with many small files.</p>
<p><strong>Left</strong>: with many small files, Discord limits the number of messages per channel long before bandwidth
matters (see grouping below); configurable memory cap; lighter synchronisation (index directly in the superblock when it
is small).</p>

<h2>2. Grouping small files</h2>

<p>Put several small files in one chunk: 20 files would become 1 message instead of 20. New <code>CHUNK_FORMAT</code>; a
shared chunk is only deleted once all its files are.</p>

<h2>3. Bot-based recovery</h2>

<p><strong>Problem</strong>: a webhook only manages its own messages. A webhook deleted in Discord makes its chunks
unreachable.</p>
<p><strong>Lead</strong>: with a bot token (optional), list the remembered channels (<code>webhooks.channel_id</code>) and read
or delete any message.</p>

<h2>4. Resuming uploads</h2>

<p>Resume a large interrupted upload at the first missing chunk, if the local file has not changed.</p>

<h2>5. Remote operations</h2>

<p>Rename and move files (a simple update of the shared index), empty folders, search and filters in the Remote tab,
downloading a whole folder.</p>

<h2>6. Compression</h2>

<p>Compress before encrypting (zstd), only when useful. New <code>CHUNK_FORMAT</code>. Caution: compression can leak
information through the size; to be kept optional.</p>

<h2>Contributing</h2>

<p>Before tackling one of these items, read <?= $c->link('concepts/architecture') ?> and <?= $c->link('dev/contribuer') ?>: most
of them translate into a Vault method, a command, an index migration and their tests.</p>
