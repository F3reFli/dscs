<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Commands';
$page->description = 'Complete reference of every dscs subcommand: synopsis, arguments, output and exit codes.';
?>
<p>Usage guide with examples: <?= $c->link('guide/cli') ?>. This page follows the exact definition of the commands
(<code>src/dscs/cli/parser.py</code>). The built-in help (<code>--help</code>) is in English; other messages are in French.</p>

<h2>Synopsis</h2>

<?= $c->code('text', <<<'TXT'
dscs [-h] [-V] <command> [<args>]

dscs [FOLDER]                interface (shortcut for "dscs tui")
dscs tui [FOLDER]
dscs init [--force]
dscs put PATH [PATH ...] [-w WEBHOOK]...
dscs ls
dscs get ID [-o FOLDER] [--flat]
dscs rm ID [-y]
dscs gc
dscs webhooks [list | rm WEBHOOK [-y] [--repair]]
dscs bench [-s MB] [-w WEBHOOK]... [-p N] [--download] [-y]
dscs sync
dscs device [show | init | add | join | list | revoke | recover | passphrase]
dscs key [list | rotate | recovery]
dscs help                    same as dscs --help
TXT) ?>

<p>If the first argument is neither a command nor an option, dscs treats it as a folder and opens the interface.</p>

<h3>Global options</h3>
<?= $c->table(['Option', 'Effect'], [
    ['<code>-h</code>, <code>--help</code>', 'General help, or help for a command (<code>dscs get --help</code>)'],
    ['<code>-V</code>, <code>--version</code>', 'Prints <code>dscs &lt;version&gt;</code>'],
]) ?>

<h2><code>tui</code></h2>
<?= $c->code('text', 'dscs tui [FOLDER]') ?>
<?= $c->table(['Argument', 'Description'], [
    ['<code>FOLDER</code>', 'Starting folder of the tree. Default: current folder. <code>~</code> is expanded.'],
]) ?>
<p>Error if it is not a folder: <code>Pas un dossier : … (voir : dscs --help)</code> (“not a folder”), code <code>1</code>.
Keys: <?= $c->link('guide/interface') ?>.</p>

<h2><code>init</code></h2>
<?= $c->code('text', 'dscs init [--force]') ?>
<?= $c->table(['Option', 'Description'], [
    ['<code>--force</code>', 'Rewrite an existing config. The existing key is always kept.'],
]) ?>
<p>Interactive: asks for one or more webhook URLs (empty line to finish), writes <code>config.toml</code>, creates the key if it
does not exist. Without <code>--force</code> and with an existing config: message and code <code>1</code>.</p>

<h2><code>put</code></h2>
<?= $c->code('text', 'dscs put PATH [PATH ...] [-w WEBHOOK]...') ?>
<?= $c->table(['Argument / option', 'Description'], [
    ['<code>PATH</code>', 'Files and/or folders. A folder is uploaded recursively under its own name.'],
    ['<code>-w</code>, <code>--webhook WEBHOOK</code>', 'Only use this webhook (ID or name, case-insensitive). Repeatable. Default: every webhook of the vault.'],
]) ?>
<p>Output: one line per file, <code>✓ [ID] path  (size)</code> or <code>✗ name : reason</code>.
<code>Rien à envoyer.</code> (“nothing to upload”) and code <code>1</code> if the selection is empty. Code <code>1</code> if
at least one file failed.</p>

<h2><code>ls</code></h2>
<?= $c->code('text', 'dscs ls') ?>
<p>Lists complete files: <code>ID</code>, <code>Taille</code> (size), <code>Envoyé le</code> (uploaded on, dd/mm/yyyy hh:mm),
<code>Chemin</code> (path), sorted by folder then name, followed by the total. <code>Aucun fichier stocké.</code> (“no file
stored”) if the index is empty. A warning is added at the end if interrupted uploads remain. Offline. Code <code>0</code>.</p>

<h2><code>get</code></h2>
<?= $c->code('text', 'dscs get ID [-o FOLDER] [--flat]') ?>
<?= $c->table(['Argument / option', 'Description'], [
    ['<code>ID</code>', 'Identifier given by <code>dscs ls</code>'],
    ['<code>-o</code>, <code>--out FOLDER</code>', 'Destination. Default: <code>download_dir</code> from the config. The remote folder is recreated inside it.'],
    ['<code>--flat</code>', 'Do not recreate the remote folder: the file lands directly in the destination.'],
]) ?>
<p>Success: <code>✓ /path/to/file  (intégrité vérifiée)</code> (“integrity verified”). Unknown ID:
<code>Aucun fichier avec l'ID N (voir : dscs ls)</code>, code <code>1</code>.</p>

<h2><code>rm</code></h2>
<?= $c->code('text', 'dscs rm ID [-y]') ?>
<?= $c->table(['Argument / option', 'Description'], [
    ['<code>ID</code>', 'Identifier given by <code>dscs ls</code>'],
    ['<code>-y</code>, <code>--yes</code>', 'Do not ask for confirmation'],
]) ?>
<p>Asks <code>Supprimer chemin (taille) de Discord ? [o/N]</code> (“delete … from Discord?”); accepts <code>o</code>,
<code>oui</code>, <code>y</code>, <code>yes</code>. Refusal or unknown ID: code <code>1</code>. Success: <code>✓ supprimé</code>.</p>

<h2><code>gc</code></h2>
<?= $c->code('text', 'dscs gc') ?>
<p>Deletes from Discord the chunks of incomplete files (interrupted uploads, partial deletions). Requires being the only open
dscs instance.</p>
<?= $c->table(['Output', 'Code'], [
    ['<code>Rien à nettoyer.</code> (nothing to clean)', '<code>0</code>'],
    ['<code>✓ N envoi(s) interrompu(s) nettoyé(s)</code> (N interrupted uploads cleaned)', '<code>0</code>'],
    ['… followed by <code>⚠ N restant(s) : Discord injoignable ou webhook absent de la config.</code> (N remaining)', '<code>1</code>'],
]) ?>

<h2><code>webhooks</code></h2>
<?= $c->code('text', 'dscs webhooks') ?>
<p>For each webhook of the vault: name and ID, server, channel, number of chunks stored, a
<code>https://discord.com/channels/…</code> link. Asks Discord (name, server and channel IDs) and updates the local cache.
Readable names come from <code>config.toml</code> (<?= $c->link('reference/config', null, 'servers-and-channels') ?>).
Code <code>1</code> if the vault has no webhook.</p>

<?= $c->code('text', 'dscs webhooks rm WEBHOOK [-y] [--repair]') ?>
<p>Removes a webhook (ID or name) from the vault, on every device, from its cache and from <code>config.toml</code>.
Files with chunks on it are deleted from the vault (list shown before confirmation, unless <code>-y</code>), and so are its
messages on Discord if it still exists. If it holds the vault's entry point, that is recreated on another webhook; with
other devices, this needs <code>--repair</code>, then <code>dscs device join</code> on each of them. Refused for the vault's
last webhook.</p>

<h2><code>bench</code></h2>
<?= $c->code('text', 'dscs bench [-s MB] [-w WEBHOOK]... [-p N] [--download] [-y]') ?>
<p>Measures the upload rate to Discord: sends random data (100 MB by default) the same way as <code>dscs put</code>,
chunks spread over the webhooks, then <strong>deletes every test message</strong>, even after an error or an
interruption. Nothing enters the vault and no key is used. A confirmation is asked (real traffic to Discord).</p>
<?= $c->table(['Option', 'Effect'], [
    ['<code>-s MB</code>', 'Amount sent, in MB (1 to 4096)'],
    ['<code>-w WEBHOOK</code>', 'Only use this webhook (name or ID, repeatable); default: all'],
    ['<code>-p N</code>', 'Simultaneous uploads per webhook (1 to 8; default 1, as <code>dscs put</code>)'],
    ['<code>--download</code>', 'Also measure downloading: the data is read back before being deleted'],
    ['<code>-y</code>', 'Do not ask for confirmation (required outside a terminal)'],
]) ?>
<p>The table gives, per webhook, the channel, the chunks sent, the rate and the throttling imposed by Discord
(<code>429</code> responses and waits). To know whether one more channel is worth it, compare the total of
<code>dscs bench</code> with that of <code>dscs bench -w &lt;one webhook&gt;</code>: if the total stops rising as you add
channels, your connection is saturated. Code <code>1</code> if some test messages could not be deleted.</p>

<h2><code>device</code></h2>
<p>Devices of the vault. Step-by-step guide: <?= $c->link('guide/appareils') ?>.</p>
<?= $c->table(['Command', 'Effect'], [
    ['<code>dscs device</code> / <code>device show</code>', 'Name, verification code, public key and protection of this device (no passphrase needed)'],
    ['<code>device init [--name NAME]</code>', 'New device: creates its identity (passphrase offered) and shows its public key and code'],
    ['<code>device add PUBLIC_KEY [--name NAME] [--code CODE]</code>', 'On a member: adds the device after checking the code (asked, or <code>--code</code>), then shows the invitation and this device\'s code'],
    ['<code>device invite</code>', 'Shows the vault invitation (superblock address, no key at all), after <code>device add</code> or <code>sync --repair</code>'],
    ['<code>device join [INVITATION] [--code CODE]</code>', 'On the new device: joins the vault after checking the code of the device that added it. On a device that is already a member: follows the vault\'s new address after a repair. Without argument, the invitation is asked (hidden input)'],
    ['<code>device list</code>', 'Vault members (name, code, date added; recovery devices flagged), after synchronising if Discord is reachable'],
    ['<code>device revoke DEVICE [-y]</code>', 'Removes a member (name, code or ID) and moves to a new key generation'],
    ['<code>device recover [KIT] [--name NAME] [-y]</code>', 'Joins the vault with a recovery kit; <code>-y</code> skips confirming the member list'],
    ['<code>device passphrase [--remove]</code>', 'Adds, changes or removes this device\'s passphrase'],
]) ?>

<h2><code>key</code></h2>
<?= $c->table(['Command', 'Effect'], [
    ['<code>dscs key</code> / <code>key list</code>', 'Vault key generations, the current one marked <code>*</code>, and the number of files encrypted with each (local)'],
    ['<code>key rotate</code>', 'New generation for the next uploads, published for every member'],
    ['<code>key recovery [-o FILE]</code>', 'Creates a recovery device and prints its kit, or writes it to <code>FILE</code> (<code>600</code> permissions)'],
]) ?>

<h2><code>sync</code></h2>
<?= $c->code('text', 'dscs sync [--repair]') ?>
<p>Receives what the other devices published (keyring, files, deletions) and publishes this device's state.
Synchronisation is also done automatically by every network command. Code <code>1</code> if this device has no vault key.</p>
<?= $c->table(['Option', 'Effect'], [
    ['<code>--repair</code>', 'If the superblock cannot be found (webhook moved to another channel, message deleted), recreates it even if other devices use the vault; they follow with <code>dscs device join</code>. Not needed with a single device: it is automatic.'],
]) ?>

<h2>Exit codes</h2>
<?= $c->table(['Code', 'Meaning'], [
    ['<code>0</code>', 'Success'],
    ['<code>1</code>', 'Expected error (<code>Erreur : …</code> on standard error) or negative result (a file failed, unknown ID, refusal, incomplete cleanup)'],
    ['<code>2</code>', 'Invalid arguments'],
    ['<code>130</code>', 'Interrupted (' . $c->kbd('Ctrl', 'C') . ')'],
]) ?>

<h2>Environment variables</h2>
<?= $c->table(['Variable', 'Effect'], [
    ['<code>DSCS_HOME</code>', 'Single folder for config, identity, keyring, index and lock'],
    ['<code>DSCS_PASSPHRASE</code>', 'This device\'s passphrase, for scripts (otherwise asked)'],
    ['<code>XDG_CONFIG_HOME</code>', 'Base of <code>dscs/config.toml</code>, <code>dscs/device.json</code>, <code>dscs/keyring.sealed</code> (default <code>~/.config</code>)'],
    ['<code>XDG_DATA_HOME</code>', 'Base of <code>dscs/index.db</code> (default <code>~/.local/share</code>)'],
    ['<code>HTTPS_PROXY</code>, <code>HTTP_PROXY</code>, <code>NO_PROXY</code>', 'Network proxy'],
]) ?>
