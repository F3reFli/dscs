<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Synchronisation';
$page->description = 'How several devices share the same vault through Discord, without a server and without revealing anything.';
?>
<p>There is no DSCS server: the devices of a vault exchange everything through Discord, with the same webhooks used for
files. What they publish is encrypted or sealed; Discord only sees unreadable blobs.</p>

<h2>The superblock</h2>

<p>A webhook cannot list a channel. A known entry point is therefore needed: the <strong>superblock</strong>, a message
posted by the vault's first webhook, whose attachment (random name) is replaced at every publication. Its address
(webhook + message ID) is kept in the keyring, and given in invitations.</p>

<?= $c->table(['Superblock content', 'Readable by'], [
    ['The sealed keyrings, by recipient device then by the device that sealed them', 'Each recipient, for its own'],
    ['The pointer to the latest index snapshot: sequence number, key used, digest (HMAC), chunks', 'Anyone, but the snapshot is encrypted'],
]) ?>

<h2>The index snapshot</h2>

<p>The shared index holds the complete files (with the list of their chunks) and the <strong>tombstones</strong> of
deleted files. It is serialised deterministically, compressed, then encrypted with the current vault key; the sequence
number is part of the authenticated data. The snapshot is uploaded like ordinary chunks, and the old one is deleted once
the new one is published.</p>

<p>The digest (an HMAC computed with the key) lets a device see “nothing new” without downloading anything, without
revealing anything about the content.</p>

<h2>Receiving, publishing</h2>

<?= $c->table(['When', 'What happens'], [
    ['Start of every network command, opening the interface', '<strong>Receive</strong>: read the superblock, merge the keyrings sealed for this device, download the snapshot if it is newer than the known one'],
    ['End of a command that uploaded or deleted, end of a transfer in the interface, <code>dscs sync</code>', '<strong>Publish</strong>: receive first, then a new snapshot (sequence + 1) and/or the keyring re-sealed for each member'],
]) ?>

<p>Before publishing, the device reads the superblock again: if it changed in the meantime (another device just
published), it starts over with the up-to-date state. It never publishes over a remote state it could not read.</p>

<h2>Conflict-free merge</h2>

<ul>
  <li><strong>Files</strong>: union of the files (identified by their random <code>uid</code>) and of the tombstones; a
    tombstone always wins. Two devices uploading at the same time lose nothing.</li>
  <li><strong>Keyring</strong>: union of the keys, members and revocations (a revocation wins); the current key is the
    most recent one. The result does not depend on the order of merges.</li>
  <li>Uploads in progress or interrupted stay local: they are only shared once complete.</li>
</ul>

<h2>Trust</h2>

<p>A received keyring is only merged if it was <strong>sealed by a known member</strong>: sealing authenticates the
device that produced it. Trust spreads step by step: a device added by a known member becomes known in turn. A revoked
device can therefore neither re-add itself nor inject a key it knows; its blobs are ignored, then removed at the next
publication.</p>

<h3>Lost superblock</h3>

<p>A webhook only finds its messages in its current channel: if it is moved to another channel (or the message deleted),
the superblock can no longer be found. dscs then creates a new one, in the webhook's current channel,
<strong>automatically</strong> if this device is the only one using the vault (recovery devices do not count), and on request
(<code>dscs sync --repair</code>) otherwise, so that two devices do not each create one. The new address is dated: merging
keyrings always keeps the most recent one. The other devices receive it through an invitation (<code>dscs device
join</code>) and only accept it inside a keyring sealed by a member they know.</p>

<p>When joining a vault, the new device trusts nobody yet: comparing the code of the device that added it creates that
first trust (<?= $c->link('guide/appareils', null, 'adding-a-device') ?>).</p>

<h2>What Discord could try</h2>

<?= $c->table(['Attempt', 'Result'], [
    ['Read the superblock, snapshots, keyrings', 'Nothing usable: everything is encrypted or sealed'],
    ['Modify a blob', 'Detected (authenticated encryption): ignored or refused'],
    ['Put back an old superblock (rollback)', 'Ignored: a device only accepts a sequence greater than the one it knows'],
    ['Slip in a keyring sealed by an unknown device', 'Ignored, with a warning'],
    ['Delete the messages', 'Possible: DSCS does not protect availability. Keep other copies.'],
    ['Make the superblock disappear', 'dscs creates a new one; files stay readable as long as their messages exist'],
    ['Give a forged invitation to a member device', 'Ignored: only an address sealed by a known member counts'],
]) ?>

<h2>Cost</h2>

<p>A command that contacts Discord makes about 3 requests to receive, and about ten when it publishes. That is negligible
for large uploads, noticeable for a single small file: see the roadmap (<?= $c->link('dev/roadmap') ?>).</p>
