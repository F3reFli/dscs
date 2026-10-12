<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Encryption';
$page->description = 'Device and vault keys, chunk encryption, keyring sealing, and the limits of the model.';
?>
<p>Everything is encrypted on your device before it leaves. Discord only receives blobs indistinguishable from random
data, under random names, and no key ever travels there in clear.</p>

<h2>Three levels of keys</h2>

<?= $c->code('text', <<<'TXT'
device private key (X25519)               never sent; optional passphrase (scrypt)
   └── opens ─► keyring sealed for this device
                  └── holds ─► vault keys (AES-256), generation 1, 2, 3…
                                 └── encrypt ─► file chunks and index snapshots
TXT) ?>

<?= $c->table(['Key', 'Where', 'Role'], [
    ['Device private key', '<code>device.json</code> (encrypted with the passphrase if set)', 'Open the keyring; seal the keyring for other members'],
    ['Vault keys', 'In the keyring, never in clear on disk', 'Encrypt files; the current generation is used for new uploads'],
    ['Keyring', '<code>keyring.sealed</code> and, sealed for each member, on Discord', 'Hold the vault keys, members, webhooks, the vault address'],
]) ?>

<h2>Chunks</h2>

<?= $c->table(['Item', 'Value'], [
    ['Algorithm', 'AES-256-GCM (authenticated encryption), <code>cryptography</code> library'],
    ['Key', 'Current keyring generation; its fingerprint is recorded with the file'],
    ['Nonce', '12 random bytes, fresh for each chunk'],
    ['Associated data (AAD)', '<code>"&lt;uid&gt;:&lt;idx&gt;"</code>: random identifier of the file and position of the chunk'],
    ['Chunk format', '<code>nonce (12 B) ‖ ciphertext ‖ tag (16 B)</code> → 28 bytes more than the plaintext'],
    ['File integrity', 'SHA-256 of the plaintext file, checked after reassembly'],
]) ?>

<p>The AAD is authenticated: a chunk only decrypts at its place, in its file. Someone controlling the channel can neither
<strong>modify</strong> a chunk, <strong>move</strong> it, nor <strong>replace</strong> it with one from another file: the
download stops (<code>Morceau corrompu ou mauvaise clé</code>, “corrupted chunk or wrong key”) and nothing is written. Once
all chunks are gathered in a <code>.part</code> file, the SHA-256 is compared with the upload's before the file gets its
real name.</p>

<h2>Sealing keyrings</h2>

<p>To hand the keyring to a member device, dscs <em>seals</em> it for its public key, in the spirit of HPKE
(authenticated mode):</p>
<ul>
  <li>an ephemeral X25519 key is drawn; the encryption key is derived (HKDF-SHA256) from two Diffie-Hellman exchanges:
    <em>ephemeral × recipient</em> and <em>sender × recipient</em>;</li>
  <li>the keyring is encrypted with AES-256-GCM, with the vault ID in the context;</li>
  <li>only the recipient can open it, and it learns <strong>who</strong> sealed it: only the holder of the sender's private
    key could have produced it. dscs only accepts keyrings sealed by a known member
    (<?= $c->link('concepts/synchronisation', null, 'trust') ?>).</li>
</ul>

<h2>Verification codes</h2>

<p>Each device has a 20-digit code (about 66 bits), derived from its public key. When pairing, each of the two devices shows
the other's code: if they match, no key was substituted on the way. Forging a key that produces the same code would take
in the order of 2<sup>66</sup> attempts.</p>

<h2>Device passphrase</h2>

<p>Optionally, the device private key is encrypted (AES-256-GCM) with a key derived from your passphrase by scrypt (about
64 MiB of memory per attempt), which makes brute force expensive. Without a passphrase, it is only protected by the file
permissions (<code>600</code>).</p>

<h2>Fingerprints</h2>

<?= $c->table(['Fingerprint', 'Computation', 'Use'], [
    ['Vault key', '<code>sha256("dscs:key-fingerprint:v1:" ‖ key)</code>, 16 characters', 'Know which generation encrypted each file'],
    ['Device', '<code>sha256("dscs:device-id:v1:" ‖ public key)</code>, 16 characters', 'Identify members'],
]) ?>

<p>These fingerprints cannot be used to recover the keys (one-way hash of 256-bit random values).</p>

<h2>What Discord sees</h2>

<?= $c->table(['Visible', 'Hidden'], [
    ['The number of chunks and their size (hence the approximate size of each file)', 'The content'],
    ['The date and time of each upload', 'File names and extensions, the folder structure'],
    ['The names you gave the webhooks', 'Which chunk belongs to which file (the index is encrypted)'],
    ['The fingerprints of member devices', 'The keys, the keyring, the list of device names'],
]) ?>

<h2>Threat model</h2>

<h3>Protected</h3>
<ul>
  <li>Reading of the content, names and index by Discord, by the server's members, or by anyone who gets hold of the
    attachments.</li>
  <li>Tampering with, shuffling or rolling back data on Discord (detected, never written to your disk).</li>
  <li>Interception of a public key or an invitation: they allow reading nothing; a substituted key is caught by comparing
    codes.</li>
  <li>Theft of a powered-off device protected by a strong passphrase.</li>
</ul>

<h3>Not protected</h3>
<ul>
  <li><strong>Availability</strong>: Discord, a server administrator, or anyone holding a webhook URL (so any member device,
    even revoked) can delete the messages. Always keep another copy of your files.</li>
  <li><strong>A compromised device</strong> while in use: it has access to the keys. Remove it as soon as possible.</li>
  <li><strong>The past after a revocation</strong>: a removed device keeps what it could read (files already uploaded, old
    keys). Only later uploads escape it.</li>
  <li><strong>The metadata</strong> listed above (sizes, dates, rhythm).</li>
</ul>

<h2>Format evolution</h2>

<p>The chunk format described here is <strong>format 1</strong> (<code>CHUNK_FORMAT = 1</code>), recorded with each file. A
future format (compression, grouping small files) will bump that number: older files will remain readable, and a version of
dscs that is too old will refuse a format it does not know.</p>

<p>A random 96-bit nonce per chunk remains safe as long as a given key encrypts fewer than a few billion chunks:
<code>dscs key rotate</code> lets you switch generations long before.</p>
