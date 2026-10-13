<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Uninstalling';
$page->description = 'Remove DSCS completely: your data on Discord, the webhooks, the program and every local file.';
?>
<p>A complete uninstall goes in this order: <strong>retrieve</strong> what you want to keep, <strong>erase</strong> the data
on Discord, <strong>revoke</strong> the webhooks, and only then <strong>remove</strong> the program and its local files.</p>

<?= $c->callout('danger', <<<'HTML'
<p>The order matters. Without the key, the chunks left on Discord become unreadable. Without the index, dscs no longer knows
which messages to delete. So only remove your local files <strong>after</strong> cleaning up Discord.</p>
HTML, 'Before you start') ?>

<?= $c->callout('tip', '<p>Only want to remove DSCS from <strong>this</strong> device, and keep the vault on your other devices? From one of them, remove this one (<code>dscs device revoke &lt;name&gt;</code>), then go straight to steps 4 and 5 here. The vault and its files stay intact elsewhere.</p>') ?>

<h2>1. Retrieve what you want to keep</h2>

<p>List what is stored, then download whatever you have no other copy of:</p>

<?= $c->code('bash', <<<'SH'
dscs ls
dscs get [id] -o [folder]          # one file
SH) ?>

<p>To retrieve everything at once (each file gets its folder back under <code>[folder]</code>):</p>

<?= $c->code('bash', <<<'SH'
dscs ls | awk '$4 ~ /^[0-9]+\/[0-9]+\/[0-9]+$/ {print $1}' | xargs -r -n1 dscs get -o [folder]
SH) ?>

<p>The <code>awk</code> filter keeps only the file lines (those containing a date) and extracts their ID.</p>

<?= $c->callout('tip', '<p>Only want to stop using DSCS on this machine, keeping the option to get everything back later? Back up the key, the index and <code>config.toml</code> (see ' . $c->link('guide/sauvegarde') . '), skip steps 2 and 3, and go straight to step 4.</p>') ?>

<h2>2. Erase the data on Discord</h2>

<p>Two methods. Choose the first if the channels are also used for something else; the second is the fastest and the most
thorough if the channels were only used for storage.</p>

<h3>Method A: with dscs, file by file</h3>

<?= $c->code('bash', <<<'SH'
# delete every listed file from Discord, without confirmation
dscs ls | awk '$4 ~ /^[0-9]+\/[0-9]+\/[0-9]+$/ {print $1}' | xargs -r -n1 dscs rm -y

# delete the chunks of interrupted uploads
dscs gc

# check: should print "Aucun fichier stocké." (no file stored) with no warning
dscs ls
SH) ?>

<p>Close the interface before running <code>dscs gc</code>: it refuses to run while another instance is open. If it reports
“remaining” files (<code>restant(s)</code>), run it again once the network is back.</p>

<h3>Method B: delete the channels in Discord</h3>

<p>Deleting a channel deletes all its messages at once, so every chunk it contains, including any the index no longer knows
about. For each storage channel: <strong>Channel settings</strong> → <strong>Delete Channel</strong>. If a whole server was
dedicated to DSCS, you can also delete the server (<strong>Server settings</strong> → <strong>Delete Server</strong>).</p>

<h2>3. Revoke the webhooks</h2>

<p>A webhook URL stays valid as long as the webhook exists: someone who got hold of it could still post in the channel.
If you kept the channels (method A), delete each webhook: <strong>Channel settings</strong> → <strong>Integrations</strong> →
<strong>Webhooks</strong> → pick the webhook → <strong>Delete Webhook</strong>. Deleting the channel (method B) also deletes
its webhooks.</p>

<h2>4. Uninstall the program</h2>

<p>With the same tool used for the installation:</p>

<?= $c->code('bash', <<<'SH'
pipx uninstall dscs        # if installed with pipx
pip uninstall dscs         # if installed with pip
SH) ?>

<p>Check that the <code>dscs</code> command is gone: <code>command -v dscs</code> must print nothing.</p>

<h2>5. Remove the local files</h2>

<?= $c->table(['Location', 'Content'], [
    [$c->path('~/.config/dscs/'), '<code>device.json</code> (device key), <code>keyring.sealed</code> (keyring), <code>config.toml</code> (settings, webhooks)'],
    [$c->path('~/.local/share/dscs/'), '<code>index.db</code> (and its <code>-wal</code> / <code>-shm</code> files), <code>index.lock</code>'],
    [$c->path('~/.config/dstore/'), $c->path('~/.local/share/dstore/') . ': old folders, if you used dstore'],
]) ?>

<?= $c->code('bash', <<<'SH'
# the secret files first, overwriting their content before deleting them
shred -u ~/.config/dscs/device.json ~/.config/dscs/keyring.sealed
shred -u ~/.config/dscs/key 2>/dev/null           # former dscs 0.2 key, if still there

# then the rest
rm -rf ~/.config/dscs ~/.local/share/dscs
rm -rf ~/.config/dstore ~/.local/share/dstore     # old folders, if they exist
SH) ?>

<?= $c->callout('note', <<<'HTML'
<p>If you had set <code>XDG_CONFIG_HOME</code> or <code>XDG_DATA_HOME</code>, the folders are
<code>$XDG_CONFIG_HOME/dscs</code> and <code>$XDG_DATA_HOME/dscs</code>. If you used <code>DSCS_HOME</code>, delete each folder
you gave it.</p>
<p>On an SSD, <code>shred</code> does not guarantee that the old data is physically erased. This only matters if chunks
encrypted with these keys still exist on Discord: hence step 2.</p>
HTML) ?>

<h3>Download folder</h3>

<p>Downloaded files are in <code>~/Downloads/dscs/</code> (or the <code>download_dir</code> of your config). They are your files:
check their content before deleting that folder.</p>

<h3>Backup copies</h3>

<p>Don't forget what is kept elsewhere: the <strong>recovery kit</strong> (printed, on a USB stick), copies of the
<code>~/.config/dscs</code> folder, the former dscs 0.2 key. Once the data has been erased from Discord, they are of no use
any more: destroy them. Also think of the <strong>other devices</strong> in the vault: uninstall DSCS on each of them.</p>

<h2>6. Development environment</h2>

<p>If you worked on the project's code:</p>

<?= $c->code('bash', <<<'SH'
cd path/to/Dscs
make clean                                  # Python caches, build
rm -rf .venv docs/vendor docs/tools         # venv and PHP dependencies of the docs
# or delete the whole project folder
SH) ?>

<h2>Final check</h2>

<?= $c->code('bash', <<<'SH'
command -v dscs                                   # nothing
ls ~/.config/dscs ~/.local/share/dscs 2>&1        # "No such file or directory"
SH) ?>

<p>On the Discord side: the storage channels are empty or deleted, and no DSCS webhook is listed under
<strong>Integrations</strong> any more.</p>
