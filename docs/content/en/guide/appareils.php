<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Devices and sharing';
$page->description = 'Use the same vault on several devices, or share it with a trusted person, without ever sending a key.';
?>
<p>A DSCS vault can be used from several devices: your PC, your laptop, or the device of a trusted person. They all see
the same files, and everything stays end-to-end encrypted: <strong>no key ever travels in clear</strong>, neither on
Discord nor between devices.</p>

<h2>How it works</h2>

<ul>
  <li><strong>Each device has its own key</strong> (an X25519 key pair). The private key never leaves the device; it can
    be protected by a passphrase.</li>
  <li><strong>The vault has its vault keys</strong>, which encrypt the files. They are kept in a <em>keyring</em>, itself
    <em>sealed</em> for each member device: only that device can open it.</li>
  <li><strong>Adding a device</strong> only sends public keys (not secret). A verification code, compared on both
    screens, ensures nobody slipped their own key in.</li>
  <li><strong>The index is synchronised</strong> through Discord, encrypted: a file uploaded from one device shows up on
    the others. See <?= $c->link('concepts/synchronisation') ?>.</li>
</ul>

<?= $c->callout('warning', '<p>A member device can read everything, and it knows the webhooks (so it can also delete messages). Only share a vault with your own devices or people you fully trust.</p>') ?>

<h2>This device</h2>

<?= $c->code('bash', <<<'SH'
dscs device                 # name, verification code, public key, protection
dscs device passphrase      # add or change the passphrase
dscs device passphrase --remove
SH) ?>

<p>With a passphrase, dscs asks for it at every launch (interface included). For a script, the
<code>DSCS_PASSPHRASE</code> variable provides it; use it carefully (other programs of your session can see it).</p>

<h2>Adding a device</h2>

<p>Three steps, on two devices. Keep both screens in front of you (or be on the phone with the other person).</p>

<?= $c->steps([
    '<p><strong>On the new device</strong> (with dscs installed): create its identity.</p>' . $c->code('bash', 'dscs device init') . '<p>It shows its <strong>public key</strong> (<code>dscs-pub-…</code>) and its <strong>code</strong> (20 digits). Send the public key to the member device by any means: it is not secret.</p>',
    '<p><strong>On a device that is already a member</strong>: add it.</p>' . $c->code('bash', 'dscs device add dscs-pub-… --name laptop') . '<p>dscs shows the code of the received key: <strong>check that it is identical</strong> to the new device\'s before confirming. It then gives an <strong>invitation</strong> (<code>dscs-inv1-…</code>) and its own code.</p>',
    '<p><strong>On the new device</strong>: join the vault with the invitation.</p>' . $c->code('bash', 'dscs device join dscs-inv1-…') . '<p>dscs shows the name and code of the device that added it: <strong>check that it is identical</strong>, then confirm. The keyring, the webhooks and the file list arrive; there is nothing to configure.</p>',
]) ?>

<?= $c->callout('note', <<<'HTML'
<p>The invitation contains no key: without being a member, nothing can be read with it. It does contain the URL of a
webhook, which allows posting and deleting messages: send it through a private channel.</p>
HTML) ?>

<p>For scripts, <code>--code</code> replaces the interactive confirmation both ways:
<code>dscs device add KEY --code 1234-…</code>, <code>dscs device join INVITATION --code 5678-…</code>. A different code
blocks the operation.</p>

<h2>Listing members</h2>

<?= $c->code('text', <<<'TXT'
$ dscs device list
Coffre 3209f41d9111ac84 · 2 appareil(s) membre(s)

pc-fixe  (cet appareil)
  Code   : 3381-7309-6931-3796-0561
  Ajouté : 12/10/2026 13:41
portable
  Code   : 5194-9876-1085-3989-0479
  Ajouté : 12/10/2026 13:41
TXT) ?>

<p>The output is in French: vault, member devices, “this device”, code, added on.</p>

<h2>Synchronising</h2>

<p>Synchronisation is automatic: every command that contacts Discord first fetches what the other devices published,
and publishes what it changed at the end. The interface does the same when it opens and after each transfer. To force a
full exchange:</p>

<?= $c->code('bash', 'dscs sync') ?>

<h2>If the vault changes address</h2>

<p>Devices find each other through the <strong>superblock</strong>, a message posted by a webhook. If that webhook is moved
to another channel in Discord (or the message deleted), the superblock can no longer be found (details:
<?= $c->link('guide/depannage') ?>). The simplest fix is to move the webhook back to its former channel. Otherwise:</p>

<?= $c->code('bash', <<<'SH'
# on ONE member device (automatic if it is the vault's only device)
dscs sync --repair        # recreates the superblock in the webhook's current channel
dscs device invite        # shows the invitation, with the new address

# on each of the other devices
dscs device join <invitation>
SH) ?>

<p>A device that is already a member has no code to compare: it only accepts keyrings sealed by members it already knows,
and keeps the address they contain. A forged invitation therefore changes nothing. Remember to create a new recovery kit
afterwards: the old one points to the old address.</p>

<h2>Removing a device</h2>

<p>Lost or stolen device, or a person who must no longer have access:</p>

<?= $c->code('bash', <<<'SH'
dscs device revoke laptop          # name, code or ID (see dscs device list)
SH) ?>

<p>The device is removed from the keyring and the vault moves to a <strong>new key generation</strong> it will never
receive: it cannot read anything uploaded afterwards. A device cannot remove itself: do it from another member.</p>

<?= $c->callout('warning', <<<'HTML'
<p>A revocation cannot take back what the device could already read: files uploaded before (and their keys), and the
webhook URLs. It can therefore still delete messages. If that is a risk, create new channels and webhooks from then on.</p>
HTML) ?>

<h2>Changing keys</h2>

<?= $c->code('bash', <<<'SH'
dscs key            # key generations, and files encrypted with each
dscs key rotate     # new generation for the next uploads, published for every member
SH) ?>

<p>Old keys stay in the keyring: files already uploaded remain readable.</p>

<h2>Recovery kit</h2>

<p>If you lose <em>all</em> your devices, nobody can open the keyring any more. The recovery kit prevents that: it is the
private key of a “recovery” device, member of the vault, plus the vault's address.</p>

<?= $c->code('bash', <<<'SH'
dscs key recovery -o dscs-kit.txt     # creates the kit (file readable by you only); without -o it is printed
SH) ?>

<?= $c->callout('danger', '<p>The kit gives access to the <strong>whole</strong> vault. Print it and keep it in a safe place, or on an encrypted USB stick. Never on Discord, in an email or in a cloud.</p>') ?>

<p>To recover the vault on a new machine:</p>

<?= $c->code('bash', <<<'SH'
dscs device recover            # paste the kit (hidden input), recognise the member list
SH) ?>

<p>The new machine becomes a member; the other devices accept it at their next synchronisation. If the kit may have been
seen, create a new one and remove the old recovery device (<code>dscs device list</code>, then
<code>dscs device revoke "secours …"</code>).</p>

<h2>Coming from dscs 0.2</h2>

<p>On first launch, the former single key (<code>~/.config/dscs/key</code>) becomes the first generation of this device's
keyring, then the plaintext file is erased. Your files stay readable. Then add a passphrase
(<code>dscs device passphrase</code>) and create a recovery kit.</p>
