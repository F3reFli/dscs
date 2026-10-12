<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Architecture';
$page->description = 'How the dscs code is split into layers, and the rules that connect them.';
?>
<p>The <code>src/dscs/</code> package is organised in layers: each layer only depends on the layers below it. The interface
(text UI or command line) only displays and reads input; all the logic goes through the <code>services</code> layer, first
of all through a single object, the <strong>Vault</strong>. The reference document is <code>ARCHITECTURE.md</code> at the root of the repository (in French).</p>

<h2>The layers</h2>

<figure class="diagram">
  <svg viewBox="0 0 720 470" role="img" aria-labelledby="arch-title">
    <title id="arch-title">Layers of the dscs package, from the interfaces down to the foundations</title>
    <defs>
      <marker id="arch-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
        <path class="diagram__arrowhead" d="M0 0L10 5L0 10z"/>
      </marker>
    </defs>

    <text class="diagram__tier" x="20" y="49">Interfaces</text>
    <text class="diagram__tier" x="20" y="145">Application</text>
    <text class="diagram__tier" x="20" y="241">Orchestration</text>
    <text class="diagram__tier" x="20" y="337">Building blocks</text>
    <text class="diagram__tier" x="20" y="429">Foundations</text>

    <rect class="diagram__box" x="150" y="16" width="200" height="56" rx="8"/>
    <text class="diagram__label" x="250" y="40" text-anchor="middle">cli/</text>
    <text class="diagram__sub" x="250" y="58" text-anchor="middle">argparse, commands</text>
    <rect class="diagram__box" x="390" y="16" width="200" height="56" rx="8"/>
    <text class="diagram__label" x="490" y="40" text-anchor="middle">tui/</text>
    <text class="diagram__sub" x="490" y="58" text-anchor="middle">Textual: tabs, screens</text>

    <rect class="diagram__box diagram__box--accent" x="150" y="112" width="440" height="56" rx="8"/>
    <text class="diagram__label" x="370" y="136" text-anchor="middle">services/ — Vault</text>
    <text class="diagram__sub" x="370" y="154" text-anchor="middle">the only API of the interfaces · lifecycle</text>

    <rect class="diagram__box" x="150" y="208" width="210" height="56" rx="8"/>
    <text class="diagram__label" x="255" y="232" text-anchor="middle">transfer/</text>
    <text class="diagram__sub" x="255" y="250" text-anchor="middle">upload · download · purge</text>
    <rect class="diagram__box" x="380" y="208" width="210" height="56" rx="8"/>
    <text class="diagram__label" x="485" y="232" text-anchor="middle">sync/</text>
    <text class="diagram__sub" x="485" y="250" text-anchor="middle">pull · push · pairing</text>

    <rect class="diagram__box" x="150" y="304" width="133" height="56" rx="8"/>
    <text class="diagram__label" x="216.5" y="328" text-anchor="middle">crypto/</text>
    <text class="diagram__sub" x="216.5" y="346" text-anchor="middle">AES-256-GCM</text>
    <rect class="diagram__box" x="303.5" y="304" width="133" height="56" rx="8"/>
    <text class="diagram__label" x="370" y="328" text-anchor="middle">index/</text>
    <text class="diagram__sub" x="370" y="346" text-anchor="middle">SQLite, lock</text>
    <rect class="diagram__box" x="457" y="304" width="133" height="56" rx="8"/>
    <text class="diagram__label" x="523.5" y="328" text-anchor="middle">discord/</text>
    <text class="diagram__sub" x="523.5" y="346" text-anchor="middle">HTTP, webhooks</text>

    <rect class="diagram__box" x="150" y="400" width="440" height="52" rx="8"/>
    <text class="diagram__label" x="370" y="422" text-anchor="middle">config/ · models · errors · utils/</text>
    <text class="diagram__sub" x="370" y="440" text-anchor="middle">no upward dependency</text>

    <path class="diagram__line" d="M250 72V110" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M490 72V110" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M255 168V206" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M485 168V206" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M216.5 264V302" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M300 264L355 302" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M440 264L385 302" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M523.5 264V302" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M216.5 360V398" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M370 360V398" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M523.5 360V398" marker-end="url(#arch-arrow)"/>
  </svg>
  <figcaption>An arrow means “imports”. Nothing goes upward: a building block knows neither the Vault nor the interface.</figcaption>
</figure>

<h2>Dependency rules</h2>

<?= $c->table(['Module', 'May import', 'Must never import'], [
    ['<code>errors</code>, <code>models</code>, <code>utils</code>', 'Nothing internal (except <code>models</code> and <code>utils</code> → <code>errors</code>)', '—'],
    ['<code>config</code>', '<code>errors</code>, <code>models</code>', '<code>crypto</code>, <code>index</code>, <code>discord</code>…'],
    ['<code>crypto</code>, <code>index</code>, <code>discord</code>', '<code>config</code>, <code>errors</code>, <code>models</code>, <code>utils</code>', 'Each other, <code>transfer</code>, <code>services</code>'],
    ['<code>transfer</code>, <code>sync</code>', 'The building blocks and the foundations', '<code>services</code>, <code>cli</code>, <code>tui</code>, nor each other'],
    ['<code>services</code>', 'Everything below', '<code>cli</code>, <code>tui</code>'],
    ['<code>cli</code>, <code>tui</code>', '<code>services</code>, <code>models</code>, <code>errors</code>, <code>utils</code>, <code>config.Paths</code>', '<code>transfer</code>, <code>index</code>, <code>crypto</code>, <code>discord</code> directly'],
]) ?>

<p>The only accepted exception: <code>cli/commands.py</code> uses <code>discord.is_official_url</code> to warn, during
<code>dscs init</code>, about a URL that is not on a Discord domain.</p>

<p>Why these rules: each building block can be tested on its own (crypto without network, the index without Discord), and a
new interface (a GUI, a local web service…) would only need to talk to the Vault.</p>

<h2>Role of each module</h2>

<h3>Foundations</h3>
<?= $c->table(['Module', 'Content'], [
    ['<code>errors.py</code>', '<code>DSCSError</code> and its subclasses. Every <code>DSCSError</code> is an expected error, shown as is (' . $c->link('reference/erreurs') . ').'],
    ['<code>models.py</code>', '<code>WebhookRef</code> (ID + secret URL), <code>StoredFile</code>, <code>Chunk</code>, <code>UploadItem</code>, <code>UploadPlan</code>, <code>chunk_count()</code>.'],
    ['<code>config/paths.py</code>', '<code>Paths</code>: file locations, resolved <strong>at run time</strong> (never at import time).'],
    ['<code>config/settings.py</code>', 'Validated reading of <code>config.toml</code> (<code>Settings</code>) and writing of the initial template with <code>600</code> permissions.'],
    ['<code>utils/</code>', '<code>human()</code> (readable sizes), <code>expand()</code> (selection → files), <code>unique_path()</code>, <code>safe_join()</code>.'],
]) ?>

<h3>Building blocks</h3>
<?= $c->table(['Module', 'Content'], [
    ['<code>crypto/keys.py</code>', 'Vault keys: generation, fingerprint, reading the former dscs 0.2 single key.'],
    ['<code>crypto/cipher.py</code>', 'AES-256-GCM encryption of chunks, AAD, <code>CHUNK_FORMAT</code> (' . $c->link('concepts/chiffrement') . ').'],
    ['<code>crypto/sealing.py</code>', 'Authenticated sealing X25519 + HKDF + AES-GCM, to hand a keyring to a device.'],
    ['<code>crypto/device.py</code>', 'Device identity: key pair, name, verification code, passphrase (scrypt).'],
    ['<code>crypto/keyring.py</code>', 'Keyring: key generations, members, revocations, webhooks, vault address; merge.'],
    ['<code>index/db.py</code>', 'SQLite connection (WAL, writes durable immediately), <code>transaction()</code>.'],
    ['<code>index/migrations.py</code>', 'Versioned schema, migration from dscs 0.1 (' . $c->link('concepts/index') . ').'],
    ['<code>index/repository.py</code>', '<code>Index</code>: the only code that writes SQL (migrations aside).'],
    ['<code>index/lock.py</code>', '<code>ProcessLock</code>: shared / exclusive lock between dscs processes.'],
    ['<code>discord/http.py</code>', 'HTTP session, <code>request()</code> handling 429, 5xx and dropped connections (' . $c->link('concepts/discord') . ').'],
    ['<code>discord/webhook.py</code>', '<code>WebhookClient</code>: post, read back, delete its own messages.'],
    ['<code>discord/pool.py</code>', '<code>WebhookPool</code>: the webhooks of the config, looked up by ID.'],
]) ?>

<h3>Orchestration and application</h3>
<?= $c->table(['Module', 'Content'], [
    ['<code>transfer/plan.py</code>', 'Selection → list of files with remote folder and size.'],
    ['<code>transfer/upload.py</code>', 'Uploading a file: one producer, one worker per webhook (' . $c->link('concepts/transferts') . ').'],
    ['<code>transfer/download.py</code>', 'Downloading with prefetch, decryption, SHA-256 check.'],
    ['<code>transfer/purge.py</code>', 'Failure-tolerant deletion (<code>purge_file</code>) and user-requested deletion (<code>delete_file</code>).'],
    ['<code>sync/</code>', 'Superblock, encrypted index snapshot, synchronisation engine (pull / push, trust), invitations and kits (' . $c->link('concepts/synchronisation') . ').'],
    ['<code>services/vault.py</code>', '<code>Vault</code>: assembles config, keyring, index, lock, HTTP session and synchronisation; adding and removing devices.'],
    ['<code>services/keystore.py</code>', '<code>KeyStore</code>: device identity and local keyring, passphrase, migration of the former key.'],
    ['<code>services/bench.py</code>', 'Rate measurement (<code>dscs bench</code>): random data, deleted afterwards, outside the vault.'],
    ['<code>services/devices.py</code>', 'Joining a vault: by invitation (<code>join_vault</code>) or by recovery kit (<code>recover_vault</code>).'],
    ['<code>services/setup.py</code>', '<code>initialize()</code> and <code>probe_webhook()</code>, used by <code>dscs init</code>.'],
]) ?>

<h3>Interfaces</h3>
<?= $c->table(['Module', 'Content'], [
    ['<code>cli/parser.py</code>', 'argparse subcommands.'],
    ['<code>cli/commands.py</code>', 'One <code>cmd_*</code> function per command, returning the exit code.'],
    ['<code>cli/progress.py</code>', 'Text progress bar (disabled outside a terminal).'],
    ['<code>cli/prompts.py</code>', 'Input: confirmations, passphrases, secret text (hidden input).'],
    ['<code>tui/app.py</code>, <code>app.tcss</code>', 'Textual application and its styles.'],
    ['<code>tui/widgets/</code>', '<code>VimTree</code> (Local tab), <code>RemoteTable</code> (Remote tab, multiple selection), <code>WebhookPanel</code> (server and channel).'],
    ['<code>tui/screens/</code>', '<code>UploadScreen</code> (webhook choice), <code>DownloadScreen</code> (destination), <code>ConfirmScreen</code>, <code>TransferScreen</code>.'],
]) ?>

<h2>The Vault, single entry point</h2>

<?= $c->code('python', <<<'PY'
with Vault.open() as vault:              # config + key + index + shared lock, no network
    vault.files()                        # local read
    plan = vault.plan_upload([Path("photos")])
    async with vault.connected():        # HTTP session + webhooks
        rec = await vault.upload(plan.items[0], on_progress=print)
        await vault.download(rec)
        await vault.delete(rec)
PY) ?>

<p>The lifecycle has two stages:</p>
<ul>
  <li><strong><code>Vault.open()</code></strong> reads the config, opens the keyring (with the passphrase if the device is
    protected), takes a shared lock and opens (and migrates if needed) the index. No network connection: <code>dscs ls</code>
    works offline.</li>
  <li><strong><code>vault.connected()</code></strong> opens the HTTP session, receives what the other devices published, and
    publishes local changes on exit. The interface opens it when mounted and closes it on exit.</li>
</ul>

<p>Method details are in <?= $c->link('reference/api') ?>.</p>

<h2>Principles</h2>

<ul>
  <li><strong>No global state computed at import time</strong>: paths go through <code>Paths</code>, which makes every test
    independent (each one has its own temporary folders).</li>
  <li><strong>Secrets stay contained</strong>: no key in clear on disk or on Discord (sealed keyring); webhook tokens are only in
    the keyring and <code>config.toml</code>, never in the index, and error messages mask them (<code>***</code>).</li>
  <li><strong>The index is always consistent</strong>: each chunk is recorded as soon as it is on Discord, a file is only
    visible once complete, and a deletion that fails leaves a trace that <code>dscs gc</code> picks up.</li>
  <li><strong>An expected error is a <code>DSCSError</code></strong> with a readable message; any other exception type is a bug.</li>
  <li><strong>Evolves without breaking</strong>: versioned index schema, chunk format versioned per file.</li>
</ul>
