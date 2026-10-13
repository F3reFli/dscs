<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Backup and restore';
$page->description = 'What protects access to your vault, how never to lose it, and how to get everything back on another machine.';
?>
<p>Files live on Discord, encrypted. To read them, you need a vault key, kept in the <strong>keyring</strong>, itself
opened by the <strong>device key</strong>. Losing access to the keyring on all your devices means losing the vault. Three
protections, from the simplest to the strongest:</p>

<?= $c->table(['Protection', 'Covers', 'How'], [
    ['<strong>Recovery kit</strong>', 'Loss of all your devices', '<code>dscs key recovery</code>, printed or offline'],
    ['<strong>A second member device</strong>', 'Loss or failure of one device', '<code>dscs device add</code> (' . $c->link('guide/appareils') . ')'],
    ['<strong>Copy of the configuration folder</strong>', 'Disk failure on this device', 'copy of <code>~/.config/dscs/</code>'],
]) ?>

<?= $c->callout('warning', '<p>Make at least one recovery kit as soon as your vault holds something important. Without it, and without another member device, a disk failure makes everything unreadable, forever.</p>', 'Do this now') ?>

<h2>What is on this device</h2>

<?= $c->table(['File', 'Role', 'If you lose it', 'Secret?'], [
    [$c->path('~/.config/dscs/device.json'), 'This device\'s private key (encrypted with the passphrase if you set one)', 'The local keyring becomes unreadable: join the vault again (another device or recovery kit)', 'Yes'],
    [$c->path('~/.config/dscs/keyring.sealed'), 'The vault keyring (vault keys, members, webhooks), sealed for this device', 'No loss: it is also on Discord, sealed for each member', 'Sealed'],
    [$c->path('~/.config/dscs/config.toml'), 'Settings, webhooks added by hand, readable names', 'Recreated at the next join; settings to redo', 'Yes (tokens)'],
    [$c->path('~/.local/share/dscs/index.db'), 'Local copy of the index', 'No loss: the shared index is on Discord, encrypted', 'No'],
]) ?>

<p>The index and the keyring are published on Discord (encrypted, sealed): what is truly irreplaceable is the access of
<em>at least one</em> device, or the recovery kit.</p>

<h2>The recovery kit</h2>

<?= $c->code('bash', <<<'SH'
dscs key recovery -o [file]
SH) ?>

<p>The kit is the private key of a “recovery” device, member of the vault, plus the vault's address. It does not change
when you rotate keys: one kit is enough, as long as you do not revoke it.</p>

<?= $c->callout('danger', '<p>The kit gives access to the <strong>whole</strong> vault. Print it and keep it in a safe place, or on an encrypted USB stick. Never on Discord, in an email, a cloud or next to your computer.</p>') ?>

<h2>Restoring on another machine</h2>

<h3>With another member device</h3>
<p>The simplest case: <?= $c->link('guide/appareils', null, 'adding-a-device') ?> (<code>dscs device init</code> on the new
machine, <code>dscs device add</code> on the old one, then <code>dscs device join</code>).</p>

<h3>With the recovery kit</h3>
<?= $c->steps([
    '<p>Install dscs (' . $c->link('guide/installation') . '). Do not run <code>dscs init</code>: it would create a new vault.</p>',
    '<p>Start the recovery and paste the kit (hidden input):</p>' . $c->code('bash', 'dscs device recover'),
    '<p>Check the member list shown (you must recognise your devices), confirm, and choose a passphrase for this machine.</p>',
    '<p><code>dscs ls</code> should list your files. If the kit may have been seen, create a new one and remove the old recovery device.</p>',
]) ?>

<h3>With a copy of the configuration folder</h3>
<p>Put <code>device.json</code>, <code>keyring.sealed</code> and <code>config.toml</code> back in <code>~/.config/dscs/</code>
(<code>600</code> permissions), then run <code>dscs sync</code>: the index comes back from Discord. If
<code>device.json</code> was protected, its passphrase is asked.</p>

<h2>Forgotten passphrase</h2>

<p>It cannot be recovered. On this machine, delete <code>device.json</code> and <code>keyring.sealed</code>, then join the
vault again (another device or recovery kit), and remove the machine's old identity from another member
(<code>dscs device revoke</code>).</p>

<h2>If the worst happens</h2>

<?= $c->table(['Lost', 'Consequence', 'What to do'], [
    ['All devices and the kit', 'The keyring no longer opens: the chunks are noise for everyone.', 'Nothing can decrypt them. Delete the channels to clean up.'],
    ['One device', 'The others carry on normally.', 'Remove it (<code>dscs device revoke</code>) from another member.'],
    ['A webhook (deleted in Discord)', 'Its chunks can no longer be read or deleted through a webhook.', 'Files that depend on it are lost to dscs. Never delete a webhook that has been used.'],
]) ?>

<h2>Coming from dscs 0.2 or dstore</h2>

<ul>
  <li><strong>dscs 0.2</strong>: on first launch, the former plaintext key (<code>~/.config/dscs/key</code>) becomes the first
    generation of the keyring, then the file is erased. Still keep your copy of that key until you have made a recovery kit.</li>
  <li><strong>dstore</strong> (former name): rename <code>~/.config/dstore</code> to <code>~/.config/dscs</code> and
    <code>~/.local/share/dstore</code> to <code>~/.local/share/dscs</code> before any launch.</li>
</ul>
