<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Python API (Vault)';
$page->description = 'Using dscs as a library from your own Python scripts.';
?>
<?= $c->callout('warning', '<p>dscs is at version 0.x: this API may still change from one version to the next.</p>') ?>

<p>The CLI and the interface only use the <code>Vault</code> object (<code>dscs.services</code>). Your scripts can do the same.</p>

<h2>Complete example</h2>

<?= $c->code('python', <<<'PY'
import asyncio
from pathlib import Path

from dscs.errors import DSCSError
from dscs.services import Vault


async def main() -> None:
    with Vault.open() as vault:                      # config + key + index (offline)
        plan = vault.plan_upload([Path("~/Photos/2026").expanduser()])
        print(f"{len(plan.items)} files, {plan.total_chunks} chunks")

        async with vault.connected():                # HTTP session
            for item in plan.items:
                try:
                    rec = await vault.upload(item)
                    print("uploaded", rec.id, rec.path)
                except DSCSError as e:
                    print("failed", item.path, e)

            for rec in vault.files():
                if rec.name.endswith(".jpg"):
                    path = await vault.download(rec, Path("/tmp/restore"))
                    print("retrieved", path)


asyncio.run(main())
PY) ?>

<h2><code>Vault</code></h2>

<h3>Opening and closing</h3>
<?= $c->table(['Member', 'Description'], [
    ['<code>Vault.open(paths=None, passphrase=None)</code>', 'Reads the config, opens the keyring, takes the shared lock, opens (and migrates) the index. <code>passphrase</code>: zero-argument function returning the passphrase, only called if the device is protected. Without a keyring, the Vault still opens (list, delete). Raises <code>ConfigError</code>, <code>CryptoError</code>, <code>IndexStateError</code>.'],
    ['<code>vault.close()</code>', 'Closes the index and releases the lock. Called automatically by <code>with</code>.'],
    ['<code>vault.paths</code>, <code>vault.settings</code>', 'Current locations and settings (<code>settings.download_dir</code> can be changed).'],
]) ?>

<h3>Network</h3>
<?= $c->table(['Member', 'Description'], [
    ['<code>async with vault.connected()</code>', 'Opens the HTTP session for the duration of the block. Required by every network operation.'],
    ['<code>await vault.start()</code> / <code>await vault.stop()</code>', 'Manual equivalent (used by the interface).'],
    ['<code>await vault.webhook_names()</code>', 'Webhook names.'],
    ['<code>await vault.refresh_webhook_info()</code>', 'Asks Discord again for each webhook\'s name, server and channel; returns <code>WebhookInfo</code> objects.'],
    ['<code>vault.webhook_infos()</code>', 'Same information, from the local cache (offline).'],
    ['<code>vault.resolve_webhooks(names_or_ids)</code>', 'Webhook IDs designated by name or ID.'],
]) ?>

<h3>Reading (local, no network)</h3>
<?= $c->table(['Member', 'Returns'], [
    ['<code>vault.files()</code>', '<code>list[StoredFile]</code> of complete files, sorted by folder then name'],
    ['<code>vault.file(file_id)</code>', '<code>StoredFile</code> or <code>None</code> (unknown or incomplete)'],
    ['<code>vault.interrupted_count()</code>', 'Number of incomplete files (to clean up)'],
    ['<code>vault.plan_upload(paths)</code>', '<code>UploadPlan</code> for a list of files and folders'],
]) ?>

<h3>Operations (network)</h3>
<?= $c->table(['Member', 'Description'], [
    ['<code>await vault.upload(item, on_progress=None, webhooks=None)</code>', 'Uploads an <code>UploadItem</code>, returns the <code>StoredFile</code>. <code>webhooks</code>: IDs to use (default: all). On error or cancellation, the uploaded chunks are removed.'],
    ['<code>await vault.download(rec, dest_dir=None, on_progress=None, keep_folder=True)</code>', 'Rebuilds the file under <code>dest_dir/&lt;folder&gt;/</code> (or directly in <code>dest_dir</code> if <code>keep_folder=False</code>; default: <code>download_dir</code>), returns its actual path. Never overwrites.'],
    ['<code>await vault.delete(rec)</code>', 'Deletes the file from Discord and from the index.'],
    ['<code>await vault.collect_garbage()</code>', 'Cleans up incomplete files; returns <code>GcReport(cleaned, remaining)</code>. Requires being the only instance.'],
]) ?>

<p><code>on_progress</code> is called with a number of plaintext bytes for each processed chunk.</p>

<h3>Synchronisation (network)</h3>
<?= $c->table(['Member', 'Description'], [
    ['<code>await vault.pull()</code>', 'Receives the keyring and index published by the other devices (done by <code>connected()</code>).'],
    ['<code>await vault.flush()</code>', 'Publishes local changes if any (done when leaving <code>connected()</code>).'],
    ['<code>await vault.sync(repair=False)</code>', 'Full exchange; returns a <code>SyncReport</code> (files received, keyring updated, superblock recreated, warnings). <code>repair=True</code>: recreates a superblock that cannot be found even if other devices use the vault (automatic if this device is the only one).'],
    ['<code>vault.notify</code>', 'Function called with each warning (synchronisation, webhook that changed channel); <code>None</code> by default.'],
]) ?>

<h3>Devices</h3>
<?= $c->table(['Member', 'Description'], [
    ['<code>vault.members()</code>, <code>vault.device_id</code>', 'Vault members (<code>Member</code>: name, public key, date) and this device\'s ID.'],
    ['<code>vault.find_member(text)</code>', 'A member designated by name, code or ID.'],
    ['<code>vault.invitation()</code>', '<code>Invitation</code>: vault address, to give to a new device.'],
    ['<code>await vault.add_device(public_key, name)</code>', 'Adds a device (re-seals the keyring for everyone), returns the invitation.'],
    ['<code>await vault.revoke_device(member)</code>', 'Removes a device and moves to a new key generation.'],
    ['<code>await vault.rotate_key()</code>', 'New key generation, published.'],
    ['<code>await vault.create_recovery()</code>', 'Adds a recovery device, returns its <code>RecoveryKit</code>.'],
    ['<code>await join_vault(paths, invitation, confirm)</code>', 'Joins a vault (device without keyring); <code>confirm(name, code)</code> must validate the code of the device that added it.'],
    ['<code>await recover_vault(paths, kit, name, passphrase, confirm)</code>', 'Joins a vault with a recovery kit; <code>confirm(members)</code> has the member list recognised.'],
    ['<code>await relink_vault(paths, invitation)</code>', 'Device already a member: follows the vault\'s new address (after a repair); returns <code>(keyring, address changed)</code>.'],
]) ?>

<h2>Models (<code>dscs.models</code>)</h2>

<h3><code>StoredFile</code></h3>
<?= $c->table(['Field', 'Type', 'Description'], [
    ['<code>id</code>', 'int', 'ID shown by <code>dscs ls</code>'],
    ['<code>uid</code>', 'str', 'Random identifier used in the AAD'],
    ['<code>name</code>, <code>folder</code>', 'str', 'Name and remote folder (<code>""</code> = root)'],
    ['<code>path</code>', 'str (property)', '<code>folder/name</code>, or <code>name</code>'],
    ['<code>size</code>', 'int', 'Size in bytes'],
    ['<code>sha256</code>', 'str', 'Fingerprint of the plaintext file'],
    ['<code>chunks</code>', 'int', 'Number of chunks'],
    ['<code>created</code>', 'float', 'Unix timestamp of the upload'],
    ['<code>format</code>', 'int', 'Chunk format'],
]) ?>

<h3><code>UploadPlan</code> and <code>UploadItem</code></h3>
<?= $c->table(['Member', 'Description'], [
    ['<code>plan.items</code>', '<code>list[UploadItem]</code>; each item has <code>path</code> (local), <code>folder</code> (remote), <code>size</code>'],
    ['<code>plan.total_bytes</code>', 'Sum of the sizes'],
    ['<code>plan.total_chunks</code>', 'Total number of chunks'],
]) ?>

<h3>Others</h3>
<ul>
  <li><code>Chunk(idx, webhook_id, message_id, size)</code>: a chunk in the index.</li>
  <li><code>WebhookRef(id, url)</code>: <code>WebhookRef.parse(url)</code>; <code>.redacted</code> gives the URL without the token.</li>
  <li><code>chunk_count(size, chunk_size)</code>: number of chunks (at least 1).</li>
</ul>

<h2>Configuration and initialisation</h2>

<p><code>initialize()</code> creates a <strong>new vault</strong> on this device (identity + keyring): for an existing vault,
use <code>join_vault</code> or <code>recover_vault</code>.</p>

<?= $c->code('python', <<<'PY'
from pathlib import Path
from dscs.config import Paths
from dscs.services import Vault, initialize

paths = Paths(config_dir=Path("/srv/vault"), data_dir=Path("/srv/vault"))
result = initialize(paths, ["https://discord.com/api/webhooks/123/AbC..."])
print(result.identity.code, result.keyring_file)

with Vault.open(paths) as vault:
    print(len(vault.files()))
PY) ?>

<?= $c->table(['Function', 'Description'], [
    ['<code>Paths(config_dir, data_dir)</code>', 'Locations; properties <code>config_file</code>, <code>device_file</code>, <code>keyring_file</code>, <code>index_file</code>, <code>lock_file</code>'],
    ['<code>Paths.from_env(env=None)</code>', '<code>DSCS_HOME</code>, otherwise XDG, otherwise the defaults'],
    ['<code>initialize(paths, urls, device_name=None, new_passphrase=None)</code>', 'Validates the URLs, writes the config, creates the device identity and the keyring if missing; returns an <code>InitResult</code> (files, identity, keyring)'],
    ['<code>await probe_webhook(url)</code>', 'Webhook name if Discord knows it, otherwise <code>None</code>'],
]) ?>

<h2>Good practices</h2>
<ul>
  <li>Always use <code>with Vault.open()</code> to release the index and the lock.</li>
  <li>Do not go through <code>dscs.transfer</code>, <code>dscs.index</code> or <code>dscs.discord</code> directly: the Vault guarantees
    the checks (key, lock, webhooks).</li>
  <li>Catch <code>DSCSError</code> for expected errors (<?= $c->link('reference/erreurs') ?>).</li>
</ul>
