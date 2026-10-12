<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'SQLite index';
$page->description = 'The map of your files: its schema, its guarantees, its migrations and its lock.';
?>
<p>A webhook can read a message back if it knows its ID, but it cannot <em>list</em> a channel. The index
(<code>~/.local/share/dscs/index.db</code>) is therefore the only way to know which messages make up which file.
It is an ordinary SQLite database, which you can inspect with <code>sqlite3</code>.</p>

<p>Each device has its local copy; the shareable part (complete files, deletions) is published encrypted on Discord and
merged between devices: see <?= $c->link('concepts/synchronisation') ?>. Losing <code>index.db</code> therefore loses
nothing: it is rebuilt at the next synchronisation.</p>

<h2>Schema (version 4)</h2>

<h3><code>files</code> — one uploaded file</h3>
<?= $c->table(['Column', 'Type', 'Role'], [
    ['<code>id</code>', 'INTEGER', 'Identifier shown by <code>dscs ls</code> and used by <code>get</code> / <code>rm</code>'],
    ['<code>uid</code>', 'TEXT unique', 'Random identifier (128 bits) bound to each chunk by the encryption'],
    ['<code>name</code>', 'TEXT', 'File name'],
    ['<code>folder</code>', 'TEXT', 'Remote folder, <code>\'\'</code> for the root'],
    ['<code>size</code>', 'INTEGER', 'Plaintext size, in bytes'],
    ['<code>sha256</code>', 'TEXT', 'Fingerprint of the plaintext file, filled in at the end of the upload'],
    ['<code>chunks</code>', 'INTEGER', 'Number of expected chunks'],
    ['<code>format</code>', 'INTEGER', 'Chunk format (see ' . $c->link('concepts/chiffrement', null, 'format-evolution') . ')'],
    ['<code>created</code>', 'REAL', 'Upload date (Unix timestamp)'],
    ['<code>key_id</code>', 'TEXT', 'Fingerprint of the key generation that encrypted the file'],
    ['<code>complete</code>', 'INTEGER', '<code>1</code>: whole and visible file; <code>0</code>: upload in progress, interrupted, or partial deletion'],
]) ?>

<h3><code>chunks</code> — one chunk = one Discord message</h3>
<?= $c->table(['Column', 'Role'], [
    ['<code>file_id</code>', 'Owning file (deleting the file deletes its chunk rows)'],
    ['<code>idx</code>', 'Position of the chunk in the file (0, 1, 2…)'],
    ['<code>webhook_id</code>', 'Webhook that posted the message (only it can read it back and delete it)'],
    ['<code>message_id</code>', 'ID of the Discord message'],
    ['<code>size</code>', 'Encrypted size (chunk + 28 bytes)'],
]) ?>

<h3><code>webhooks</code>, <code>tombstones</code> and <code>meta</code></h3>
<ul>
  <li><code>webhooks(id, channel_id, guild_id, name)</code>: the webhooks already used, the channel and server they post to,
    and their name (cache of what Discord says, for the Remote tab and <code>dscs webhooks</code> offline). <strong>Never a
    token</strong>: the full URL stays in <code>config.toml</code>. The channel is remembered for a future bot-based recovery.</li>
  <li><code>tombstones(uid, deleted)</code>: deleted files, so that the deletion reaches the other devices and a merge never
    brings them back.</li>
  <li><code>meta(key, value)</code>: synchronisation state (<code>sync_seq</code>, latest known snapshot;
    <code>keyring_pushed</code>, latest published keyring).</li>
</ul>

<h2>Guarantees</h2>

<ul>
  <li><strong>A file only appears once complete.</strong> It is created with <code>complete = 0</code>; <code>ls</code>, the
    Remote tab and <code>get</code> ignore it until the upload has finished.</li>
  <li><strong>Each chunk is recorded as soon as it is on Discord</strong>, without waiting for the end. If the process dies,
    the index knows exactly which messages to delete.</li>
  <li><strong>A partial deletion forgets nothing</strong>: each deleted chunk leaves the index immediately; if some remain, the
    file goes back to <code>complete = 0</code> and <code>dscs gc</code> will resume.</li>
  <li><strong>No secrets</strong>: neither key nor token. The index can be backed up without special care (it does reveal your
    file names, though).</li>
  <li><strong>Crash resistant</strong>: SQLite WAL mode, every write is durable immediately.</li>
</ul>

<h2>Migrations</h2>

<p>The schema version is stored in <code>PRAGMA user_version</code>. When opening, dscs applies the missing migrations in
order, each in its own transaction.</p>

<?= $c->table(['Situation', 'Behaviour'], [
    ['New database', 'Schema created, then straight to version 4'],
    ['Database from dscs 0.1 (no version, with a <code>files</code> table)', 'Conversion: each chunk\'s full URL is replaced by the webhook ID, then <code>VACUUM</code> rewrites the file to physically erase the old tokens'],
    ['Version 1 → 2', 'Adds <code>files.key_id</code>: the former single key\'s fingerprint is given to every file'],
    ['Version 2 → 3', 'Adds the <code>tombstones</code> table (synchronisation)'],
    ['Version 3 → 4', 'Adds <code>webhooks.guild_id</code> and <code>webhooks.name</code> (server and channel in the Remote tab)'],
    ['Up-to-date database', 'Nothing'],
    ['Database newer than dscs', 'Refused: <code>Index en version N, cette version de dscs ne connaît que la M : mets dscs à jour.</code> (“update dscs”)'],
]) ?>

<p>How to add a migration: <?= $c->link('dev/contribuer', null, 'adding-an-index-migration') ?>.</p>

<h2>Locking between processes</h2>

<p>Several dscs can run at the same time (the interface open and a <code>dscs put</code> in another terminal): SQLite handles
concurrent access. Each instance holds a <strong>shared lock</strong> on <code>index.lock</code>, next to the index.</p>

<p><code>dscs gc</code> additionally requests an <strong>exclusive lock</strong>, without waiting: it fails if another instance
is open. Without this precaution, it would mistake another instance's upload in progress (<code>complete = 0</code>) for an
interrupted upload, and delete its chunks.</p>

<p>The lock uses <code>flock</code>, available on Linux and macOS. On Windows there is no lock: do not run
<code>dscs gc</code> during an upload.</p>

<h2>Inspecting the index</h2>

<?= $c->code('sql', <<<'SQL'
-- sqlite3 ~/.local/share/dscs/index.db
SELECT id, folder, name, size, chunks FROM files WHERE complete = 1;
SELECT idx, webhook_id, message_id FROM chunks WHERE file_id = 3 ORDER BY idx;
SELECT id, name FROM files WHERE complete = 0;   -- interrupted uploads
SQL) ?>

<?= $c->callout('warning', '<p>Do not edit the index by hand while dscs is running, and keep a copy before any change.</p>') ?>
