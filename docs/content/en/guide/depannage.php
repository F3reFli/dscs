<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Troubleshooting';
$page->description = 'The most common error messages, what they mean and how to get out of them.';
?>
<p>All the messages below are printed as is by the command line (prefixed with <code>Erreur :</code>) or by the interface
(in the transfer log or a notification). dscs speaks French: each heading quotes the exact message, followed by its meaning.
The complete list, sorted by type, is in <?= $c->link('reference/erreurs') ?>.</p>

<h2>At start-up</h2>

<h3><code>Aucune config trouvée. Lance d'abord : dscs init</code></h3>
<p><em>No config found. Run dscs init first.</em> There is no <code>config.toml</code> where expected. Run <code>dscs init</code>,
or check <code>DSCS_HOME</code> / <code>XDG_CONFIG_HOME</code> if you use them (<?= $c->link('guide/configuration', null, 'where-files-live') ?>).</p>

<h3><code>Phrase de passe incorrecte.</code></h3>
<p><em>Wrong passphrase.</em> This device's passphrase does not match. It cannot be recovered: if you forgot it, see
<?= $c->link('guide/sauvegarde', null, 'forgotten-passphrase') ?>.</p>

<h3><code>Cet appareil est protégé par une phrase de passe : lance dscs dans un terminal, ou définis DSCS_PASSPHRASE.</code></h3>
<p><em>This device is protected by a passphrase: run dscs in a terminal, or set DSCS_PASSPHRASE.</em> dscs needs the
passphrase but cannot ask for it (script, scheduled task). Run it in a terminal, or provide it through the
<code>DSCS_PASSPHRASE</code> variable.</p>

<h3><code>Clé invalide dans …</code></h3>
<p><em>Invalid key.</em> The former single key of dscs 0.2 (<code>key</code>), being moved into the keyring, does not hold
64 hexadecimal characters. Compare it with your backup copy.</p>

<h3><code>URL de webhook invalide : …</code></h3>
<p><em>Invalid webhook URL.</em> An entry of <code>webhooks</code> is not shaped like
<code>https://discord.com/api/webhooks/&lt;ID&gt;/&lt;token&gt;</code>. The token is masked (<code>***</code>) in the
message.</p>

<h3><code>chunk_size_mb doit être…</code> / <code>TOML invalide</code></h3>
<p><em>Bad chunk_size_mb value / invalid TOML.</em> An error in <code>config.toml</code>: the message says which one.
Accepted values: <?= $c->link('reference/config') ?>.</p>

<h3><code>Index en version N, cette version de dscs ne connaît que la M : mets dscs à jour.</code></h3>
<p><em>Index in version N, this dscs only knows version M: update dscs.</em> The index was opened by a newer dscs that
upgraded its format. Install the same (or a newer) version as the last one you used.</p>

<h3><code>Index illisible (…) : …</code></h3>
<p><em>Unreadable index.</em> The <code>index.db</code> file is corrupted or not a SQLite database. Restore a backup.</p>

<h2>Keys, devices and synchronisation</h2>

<h3><code>Aucune clé de coffre sur cet appareil. Crée un coffre avec « dscs init », ou rejoins un coffre existant avec « dscs device join ».</code></h3>
<p><em>No vault key on this device.</em> This device has no keyring: it can list and delete, but neither upload nor
download. If this is your existing vault, do not run <code>dscs init</code> (it would create a new one): join it
(<?= $c->link('guide/appareils', null, 'adding-a-device') ?>) or use your recovery kit.</p>

<h3><code>… a été chiffré avec une clé absente du trousseau de cet appareil (empreinte …).</code></h3>
<p><em>…was encrypted with a key missing from this device's keyring.</em> This file was encrypted with a key generation this
device does not have (marked ✗ in <code>dscs ls</code> and the Remote tab). Run <code>dscs sync</code>: if the key was just
created elsewhere, it will arrive. If the device was removed from the vault, it will never receive it.</p>

<h3><code>Cet appareil n'a plus accès à ce coffre : il en a été retiré depuis un autre appareil.</code></h3>
<p><em>This device no longer has access to this vault: it was removed from another device.</em> Another member revoked this
device (<code>dscs device revoke</code>). It receives nothing any more. To bring it back, give it a new identity (delete
<code>device.json</code> and <code>keyring.sealed</code>, then <code>dscs device init</code>) and add it again.</p>

<h3><code>Ce coffre n'a pas encore ajouté cet appareil…</code></h3>
<p><em>This vault has not added this device yet.</em> The invitation is fine, but no member has added this device's public
key yet. On a member device: <code>dscs device add &lt;public key&gt;</code> (the key is shown by <code>dscs device show</code>),
then try again.</p>

<h3><code>Aucun appareil confirmé : le coffre n'a pas été rejoint.</code> / <code>Le code donné ne correspond pas à cette clé publique</code></h3>
<p><em>No device confirmed / the code does not match this public key.</em> The verification codes do not match. Do not force
anything: copy the public key or invitation directly from the other screen. A different code may mean a key was replaced on
the way.</p>

<h3><code>L'index du coffre est chiffré avec une clé que cet appareil n'a pas encore reçue…</code></h3>
<p><em>The vault index is encrypted with a key this device has not received yet.</em> Another device just switched key
generation and its keyring has not arrived yet (or it was sealed by a device this one does not know yet). Try again after
the device that made the change has synchronised.</p>

<h3><code>Point d'entrée du coffre (superbloc) introuvable sur Discord…</code> / <code>Discord a répondu 404 : {"message": "Unknown Message", "code": 10008}</code></h3>
<p><em>Vault entry point (superblock) not found on Discord.</em> The <strong>superblock</strong>, the message that serves as
the vault's entry point (<?= $c->link('concepts/synchronisation') ?>), can no longer be reached. Most common cause: its
webhook was <strong>moved to another channel</strong> in Discord's settings. A webhook only finds its messages in its
current channel: those left in the former channel (the superblock, and the chunks of files uploaded before the move) become
invisible to it. Other cause: the message was deleted by hand.</p>
<?= $c->table(['Situation', 'What to do'], [
    ['The former channel still exists', 'Move the webhook back to that channel (Discord settings): everything is reachable again, superblock and older files.'],
    ['A single device uses the vault', 'Nothing: the next command (for example <code>dscs sync</code>) recreates the superblock in the current channel and says so in a warning.'],
    ['Several devices', 'On <strong>one</strong> of them: <code>dscs sync --repair</code>, then <code>dscs device invite</code>. On each of the others: <code>dscs device join &lt;invitation&gt;</code>.'],
]) ?>
<p>Files uploaded before the move stay in the former channel: they only become readable again if the webhook is moved
back. After a repair, create a new recovery kit (<code>dscs key recovery</code>): the old one points to the old address. To
avoid it: <?= $c->link('guide/configuration', 'do not move a webhook that has been used', 'never-delete-or-move-a-webhook-that-has-been-used') ?>.</p>

<h3><code>Cette invitation pointe vers une adresse du coffre qui n'existe plus…</code> / <code>Ce kit de secours pointe vers une adresse du coffre qui n'existe plus…</code></h3>
<p><em>This invitation / recovery kit points to a vault address that no longer exists.</em> The superblock has been
recreated elsewhere since (or its webhook moved). Ask for an up-to-date invitation (<code>dscs device invite</code> on a
member device), or use a kit created after the repair.</p>

<h3><code>Aucun trousseau d'un appareil de confiance à cette adresse : invitation périmée ou falsifiée.</code></h3>
<p><em>No keyring from a trusted device at this address: outdated or forged invitation.</em> A device that is already a
member received an invitation whose superblock holds nothing sealed by a member it knows: invitation of another recreated
vault, or an attempt to deceive it. Nothing was changed; ask a member device for the invitation.</p>

<h3><code>Synchronisation impossible : le coffre est modifié en continu ailleurs. Réessaie.</code></h3>
<p><em>Synchronisation impossible: the vault keeps changing elsewhere.</em> Several devices are publishing at the same time.
Try again in a few seconds: no data is lost.</p>

<h3><code>Ce kit de secours n'est plus valide…</code></h3>
<p><em>This recovery kit is no longer valid.</em> The kit's recovery device was removed from the vault, or the kit belongs
to another vault. Use a more recent kit, or a member device.</p>

<h3><code>Invitation invalide…</code> / <code>Clé publique d'appareil invalide…</code></h3>
<p><em>Invalid invitation / device public key.</em> The pasted text is incomplete or damaged. An invitation starts with
<code>dscs-inv1-</code>, a public key with <code>dscs-pub-</code>, a kit with <code>dscs-kit1-</code>: copy them in full.</p>

<h2>During a transfer</h2>

<h3><code>Le webhook N est inconnu (ni dans le trousseau du coffre, ni dans config.toml)</code></h3>
<p><em>Webhook N is unknown (neither in the vault's keyring nor in config.toml).</em> The file has chunks uploaded by a
webhook this device does not know. The vault's webhooks are remembered with its keys: removing them from
<code>config.toml</code> does not forget them. This message therefore means the URL was never given on this device (an
index coming from elsewhere, for example). Put it back in the <code>webhooks</code> list of <code>config.toml</code>:
nothing was changed, everything becomes reachable again.</p>

<h3><code>Webhook inconnu : « … ». Webhooks du coffre : …</code></h3>
<p><em>Unknown webhook.</em> The name or ID given to <code>dscs put -w</code> matches no webhook. The list shown gives the
valid names and IDs (also visible with <code>dscs webhooks</code>).</p>

<h3><code>La destination n'est pas un dossier : …</code></h3>
<p><em>The destination is not a folder.</em> The path chosen for a download exists and is a file. Choose a folder (it is
created if it does not exist).</p>

<h3><code>Discord a répondu 401 / 403 / 404 : …</code></h3>
<p><em>Discord answered…</em></p>
<?= $c->table(['Code', 'Likely cause'], [
    ['<code>401</code>', 'Invalid webhook token (URL badly copied).'],
    ['<code>403</code>', 'Insufficient permissions in the channel.'],
    ['<code>404</code>', 'Webhook deleted in Discord, <strong>webhook moved to another channel</strong> (its messages left in the former one become invisible to it), or message deleted by hand. For a deletion, an already missing message is not an error.'],
    ['<code>413</code>', 'Chunk too big for the server\'s limit: lower <code>chunk_size_mb</code>.'],
]) ?>

<h3><code>Échec après 8 tentatives : …</code></h3>
<p><em>Failed after 8 attempts.</em> Discord stayed unreachable (network down, repeated 5xx errors) despite retries spaced
from 1 to 30 seconds. Check your connection and try again; the chunks already uploaded for the file have been removed (or
will be by <code>dscs gc</code>).</p>

<h3><code>… a été modifié pendant l'envoi : recommence</code></h3>
<p><em>…was modified during the upload: try again.</em> The file changed size while it was being read. Wait until it is no
longer being written to, then upload it again.</p>

<h3><code>Morceau corrompu ou mauvaise clé (échec du déchiffrement)</code></h3>
<p><em>Corrupted chunk or wrong key (decryption failed).</em> A downloaded chunk does not pass the authenticity check: it was
modified on Discord, or does not belong to this file. Nothing is written to disk.</p>

<h3><code>Hash différent pour … : fichier corrompu</code> / <code>Index incomplet pour … (a/b morceaux)</code></h3>
<p><em>Hash mismatch: corrupted file / incomplete index (a/b chunks).</em> The rebuilt file does not match the original, or
the index does not know all its chunks. The partial file is deleted. Restoring a more recent index may help in the second case.</p>

<h3><code>Le message N n'a plus de pièce jointe</code></h3>
<p><em>Message N no longer has an attachment.</em> The message still exists but its attachment is gone (removed by hand in
Discord). This file can no longer be recovered.</p>

<h3><code>Suppression partielle de … : relance « dscs gc » pour terminer.</code></h3>
<p><em>Partial deletion: run dscs gc to finish.</em> Some messages could not be deleted. The file no longer appears in the
list; <code>dscs gc</code> will remove the rest as soon as Discord answers.</p>

<h3><code>Chemin refusé (sort du dossier de destination) : …</code></h3>
<p><em>Path refused (leaves the destination folder).</em> The remote folder stored in the index would write outside the
download folder. This cannot happen with an index produced by dscs: it has probably been tampered with.</p>

<h2>Other situations</h2>

<h3>Uploads are slow</h3>
<p>Discord limits the number of messages per webhook and per channel; dscs waits automatically when asked to. Add webhooks
in other channels to parallelise (<?= $c->link('guide/configuration', null, 'webhooks') ?>).</p>

<h3><code>Une autre instance de dscs est ouverte : ferme-la avant de lancer cette opération.</code></h3>
<p><em>Another dscs instance is open: close it before running this operation.</em> <code>dscs gc</code> needs to be alone.
Close the interface or wait for the other command to finish.</p>

<h3>“N envoi(s) interrompu(s)”</h3>
<p><em>N interrupted upload(s).</em> An upload was interrupted before it could clean up its chunks (crash, power cut).
Run <code>dscs gc</code>.</p>

<h3>A downloaded file is named <code>name (1).ext</code></h3>
<p>Expected: a file with the same name already existed at the destination, and dscs never overwrites anything.</p>
