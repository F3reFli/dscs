<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Tests';
$page->description = 'Layout of the pytest suite, the fake Discord server, the fixtures, and how to write a test.';
?>
<p>The suite runs entirely locally, without ever contacting Discord: a fake server reproduces its behaviour, flaws
included. Each test gets its own temporary folders.</p>

<h2>Running</h2>

<?= $c->code('bash', <<<'SH'
make test                                   # everything
make test-fast                              # without the interface scenario
.venv/bin/pytest tests/unit                 # one folder
.venv/bin/pytest -k gc                      # by keyword
.venv/bin/pytest tests/integration/test_transfers.py::test_roundtrip -x -vv
SH) ?>

<h2>Layout</h2>

<?= $c->table(['Folder', 'What is tested'], [
    ['<code>tests/unit/</code>', 'Utilities, crypto (round trip, tampering, AAD), sealing, device identity and passphrase, keyring, KeyStore (migration of the former key), config, index (schema, migrations), lock, offline CLI'],
    ['<code>tests/integration/</code>', 'End to end against the fake server: transfers, tampering, cancellation, gc, rotation, missing key, chosen webhooks, destination; several devices (pairing, sharing, revocation, concurrency, forged keyring, rollback, recovery); moved webhooks and superblock repair; pairing and repair through the CLI'],
    ['<code>tests/tui/</code>', 'Full keyboard-driven scenario with Textual\'s <code>App.run_test()</code>: upload with webhook choice, multiple selection, destination (<code>tui</code> marker)'],
    ['<code>tests/fakes/</code>', 'The fake Discord server'],
]) ?>

<h2>The fake Discord server</h2>

<p><code>tests/fakes/fake_discord.py</code> is a small aiohttp application that mimics the webhook API, only nastier:</p>

<?= $c->table(['Simulated behaviour', 'What it checks'], [
    ['One upload or read request out of 7 gets a <code>429</code>', 'Rate limit handling'],
    ['Attachment &gt; 10 MiB rejected (<code>413</code>)', 'That chunks respect the limit'],
    ['The link returned on upload is <strong>already expired</strong>', 'That dscs always reads the message again for a fresh link'],
    ['Messages and attachments stored in memory', 'That content is encrypted, names random, notifications suppressed'],
    ['<code>control["deny_deletes"]</code>: deletions refused (<code>403</code>)', 'Recovery of a failed cleanup by <code>dscs gc</code>'],
    ['<code>PATCH</code> of a message (attachment replaced)', 'Publishing the superblock'],
    ['Webhook info with server and channel', 'The Remote tab and <code>dscs webhooks</code>'],
    ['<code>control["channels"][id] = "…"</code>: webhook moved to another channel (its former messages become invisible to it, <code>404</code>)', 'Superblock repair and the channel-change warning'],
]) ?>

<p>It also exposes counters (<code>stats</code>: uploads, 429s, expired links used, re-reads…) that the tests check.</p>

<h2>Fixtures (<code>tests/conftest.py</code>)</h2>

<?= $c->table(['Fixture', 'Provides'], [
    ['<code>discord</code>', 'The fake server started on a free port, with two webhook URLs; access to <code>store</code>, <code>stats</code>, <code>control</code>'],
    ['<code>paths</code>', 'A <code>Paths</code> inside the test\'s temporary folder'],
    ['<code>vault</code>', 'A Vault initialised (config + key) on the fake server, downloads into a temporary folder'],
    ['<code>sample_tree</code>', 'A test tree: empty file, text, 25 MB (3 chunks), exactly 9 MiB (1 chunk), subfolders, hidden file'],
]) ?>

<p><code>asyncio_mode = "auto"</code>: an <code>async def</code> test function runs without a decorator.</p>

<h2>Writing a test</h2>

<?= $c->code('python', <<<'PY'
async def test_empty_file_roundtrip(vault, discord, tmp_path):
    src = tmp_path / "empty.txt"
    src.write_bytes(b"")
    async with vault.connected():
        [item] = vault.plan_upload([src]).items
        rec = await vault.upload(item)
        assert rec.chunks == 1
        out = await vault.download(rec, tmp_path / "out")
    assert out.read_bytes() == b""
    assert len(discord.store) == 1
PY, 'tests/integration/test_transfers.py') ?>

<ul>
  <li>Go through the Vault whenever possible: it is the API the interfaces use.</li>
  <li>Check the effects on the “Discord” side through <code>discord.store</code> and on the index side through <code>vault.index</code>.</li>
  <li>For a new Discord behaviour, add it to the fake server rather than mocking the HTTP client.</li>
</ul>
