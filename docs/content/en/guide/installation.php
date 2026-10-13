<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Installation';
$page->description = 'Install dscs, create a Discord webhook and generate your encryption key.';
?>
<h2>Requirements</h2>

<ul>
  <li><strong>Python 3.11 or newer</strong> (<code>python3 --version</code>).</li>
  <li><strong>A Discord server</strong> where you have the <em>Manage Webhooks</em> permission. Ideally a dedicated private
    server, with one or more channels reserved for storage.</li>
  <li>Linux or macOS. On Windows, dscs works but without the lock between processes
    (see <?= $c->link('concepts/index', null, 'locking-between-processes') ?>).</li>
</ul>

<p>The Python dependencies (<code>textual</code>, <code>aiohttp</code>, <code>cryptography</code>) are installed automatically.</p>

<h2>Installing dscs</h2>

<p>From the project folder (the one containing <code>pyproject.toml</code>):</p>

<?= $c->code('bash', <<<'SH'
pipx install .            # recommended: dscs isolated in its own environment
# or
pip install --user .
SH) ?>

<p>On distributions that protect the system Python (Debian, Ubuntu…), <code>pip install --user</code> is refused
(<code>externally-managed-environment</code>): use <code>pipx</code> (<code>sudo apt install pipx</code>).</p>

<p>Check:</p>

<?= $c->code('bash', <<<'SH'
dscs --version
# dscs 0.4.1
SH) ?>

<?= $c->callout('tip', '<p>To work on the code itself, use <code>make install</code> instead: see ' . $c->link('dev/contribuer') . '.</p>') ?>

<h2>Creating a webhook</h2>

<p>A webhook is a secret address that lets you post messages to a specific channel, without a bot or an account.
It is how dscs uploads, reads back and deletes its chunks.</p>

<?= $c->steps([
    '<p>In Discord, open the <strong>channel settings</strong> of the channel that will hold the storage (gear icon next to its name).</p>',
    '<p>Go to <strong>Integrations</strong> → <strong>Webhooks</strong> → <strong>New Webhook</strong>.</p>',
    '<p>Give it a meaningful name (for example “Storage”): this is the name dscs displays.</p>',
    '<p>Click <strong>Copy Webhook URL</strong>. It looks like <code>https://discord.com/api/webhooks/123456789/AbCd…</code>.</p>',
]) ?>

<?= $c->callout('warning', <<<'HTML'
<p>Anyone who has this URL can post in the channel <em>and delete the webhook's messages</em>, that is, your chunks.
Never share it. dscs only stores it in <code>config.toml</code>, readable by you alone.</p>
HTML) ?>

<h3>Several webhooks for more speed</h3>

<p>dscs uploads the chunks of a file in parallel, one stream per webhook. Since Discord's rate limits apply per webhook
and per channel, the most effective setup is <strong>one webhook per channel</strong>: for example create three channels
<code>#storage-1</code> to <code>#storage-3</code>, one webhook in each, and give all three URLs to <code>dscs init</code>.</p>

<h2>Configuring: <code>dscs init</code></h2>

<?= $c->code('text', <<<'TXT'
$ dscs init
Discord : Paramètres du salon → Intégrations → Webhooks → Nouveau webhook → Copier l'URL

URL du webhook : https://discord.com/api/webhooks/123456789/AbCd...
  ✓ webhook « Storage »
URL du webhook (Entrée pour terminer) :

La clé privée de cet appareil protège toutes les clés du coffre.
La protéger par une phrase de passe ? (recommandé) [O/n]
Nouvelle phrase de passe (8 caractères minimum) :
Confirme-la :

Config    : /home/you/.config/dscs/config.toml
Appareil  : pc-fixe  (code 3381-7309-6931-3796-0561)
Trousseau : /home/you/.config/dscs/keyring.sealed  (nouveau coffre, clé générée sur cet appareil)
Index     : /home/you/.local/share/dscs/index.db

⚠ Sans cet appareil (ou un autre appareil membre du coffre), tout ce qui est stocké est illisible.
  Crée dès ton premier envoi un kit de secours, à garder hors ligne : dscs key recovery
TXT) ?>

<p>The prompt <code>URL du webhook</code> asks for a webhook URL. dscs then offers to protect this device with a
passphrase, and the final lines show the configuration, this device (<em>Appareil</em>, with its code), the keyring
(<em>Trousseau</em>) and the index, and remind you to create a recovery kit. For each URL, dscs:</p>
<ul>
  <li>rejects anything that is not shaped like a webhook URL (<code>✗ URL de webhook invalide : …</code>, “invalid webhook URL”);</li>
  <li>asks for confirmation if the address is not on <code>discord.com</code> or <code>discordapp.com</code>;</li>
  <li>asks Discord for the webhook's name. If Discord can't be reached, the URL is kept anyway
    (<code>⚠ webhook injoignable pour l'instant (gardé quand même)</code>).</li>
</ul>

<p>Press <?= $c->kbd('Enter') ?> on an empty line to finish (<code>Entrée pour terminer</code>). dscs then creates:</p>
<ul>
  <li>the <strong>identity of this device</strong> (its key pair), protected by a passphrase if you choose so: strongly
    recommended, it will be asked at every use;</li>
  <li>the <strong>keyring</strong> of a new vault, with a vault key generated on the spot;</li>
  <li>the configuration.</li>
</ul>

<?= $c->callout('note', '<p>The vault already exists on another of your devices? <strong>Do not run <code>dscs init</code></strong>: join it with <code>dscs device init</code> then <code>dscs device join</code> (' . $c->link('guide/appareils', null, 'adding-a-device') . ').</p>') ?>

<h3>Running <code>init</code> again</h3>

<p>If a configuration already exists, <code>dscs init</code> stops (“config already present: edit it, or run again with --force”):</p>

<?= $c->code('text', <<<'TXT'
Config déjà présente : /home/you/.config/dscs/config.toml
(édite-la, ou relance avec --force)
TXT) ?>

<p><code>dscs init --force</code> rewrites <code>config.toml</code> but <strong>never replaces an existing keyring</strong>
(<code>Trousseau existant conservé : …</code>, “existing keyring kept”). To simply add a webhook, edit the file instead:
see <?= $c->link('guide/configuration') ?>.</p>

<h2>Files created</h2>

<?= $c->table(['File', 'Content', 'Permissions'], [
    [$c->path('~/.config/dscs/device.json'), 'Identity of this device: private key (encrypted if passphrase), name', '<code>600</code>'],
    [$c->path('~/.config/dscs/keyring.sealed'), 'Vault keyring (keys, members, webhooks), sealed for this device', '<code>600</code>'],
    [$c->path('~/.config/dscs/config.toml'), 'Settings, webhooks added by hand', '<code>600</code>'],
    [$c->path('~/.local/share/dscs/index.db'), 'Local copy of the index, created on first run', '<code>600</code>'],
]) ?>

<p>The locations follow <code>XDG_CONFIG_HOME</code> and <code>XDG_DATA_HOME</code> when they are set.
<code>DSCS_HOME=/some/folder</code> puts everything in a single folder. Details: <?= $c->link('guide/configuration') ?>.</p>

<?= $c->callout('warning', '<p>From your first upload, create a recovery kit and keep it offline (<code>dscs key recovery</code>): without it, losing this device means losing the vault. See ' . $c->link('guide/sauvegarde') . '.</p>', 'Before storing anything important') ?>

<h2>Coming from dstore or an earlier version</h2>

<ul>
  <li><strong>dstore</strong> (former name): rename <code>~/.config/dstore</code> to <code>~/.config/dscs</code> and
    <code>~/.local/share/dstore</code> to <code>~/.local/share/dscs</code> <em>before</em> running <code>dscs init</code>,
    otherwise a new key will be created.</li>
  <li><strong>dscs 0.2</strong>: nothing to do. On first launch, the former plaintext key (<code>key</code>) becomes the
    first generation of this device's keyring, then the file is erased (<?= $c->link('guide/appareils', null, 'coming-from-dscs-0-2') ?>).</li>
  <li><strong>dscs 0.1</strong>: nothing to do. The index is converted automatically on first run
    (see <?= $c->link('concepts/index', null, 'migrations') ?>).</li>
</ul>

<h2>Uninstalling</h2>

<p>To remove everything cleanly (data on Discord, webhooks, program, key and index): <?= $c->link('guide/desinstallation') ?>.</p>
