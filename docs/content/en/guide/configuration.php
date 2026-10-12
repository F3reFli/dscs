<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Configuration';
$page->description = 'The config.toml file, multiple webhooks, chunk size and where files live.';
?>
<h2>The <code>config.toml</code> file</h2>

<p>Created by <code>dscs init</code> in <code>~/.config/dscs/config.toml</code>, with <code>600</code> permissions
(readable by you only: it holds the webhook tokens). You can edit it by hand; it is read again at every launch.
The comments it contains are in French.</p>

<?= $c->code('toml', <<<'TOML'
# DSCS — configuration
# Plusieurs webhooks (un par salon) = envois en parallèle. Ceux du coffre sont aussi gardés avec ses clés :
# un appareil qui rejoint le coffre les reçoit sans rien écrire ici.
# Ne supprime jamais dans Discord un webhook déjà utilisé : ses morceaux deviendraient inaccessibles.
webhooks = [
  "https://discord.com/api/webhooks/111111111111111111/AbCd...",
  "https://discord.com/api/webhooks/222222222222222222/EfGh...",
]

# Taille des morceaux. Max 10 Mio sans Nitro (on garde de la marge).
chunk_size_mb = 9.0

download_dir = "~/Downloads/dscs"
show_hidden = false

# Discord ne donne pas aux webhooks le nom de leur serveur ni de leur salon.
# Donne-leur un nom lisible ici (identifiants affichés par « dscs webhooks ») :
[servers]
# "118000000000000000" = "Perso"

[channels]
# "119000000000000000" = "stockage-1"
TOML, '~/.config/dscs/config.toml') ?>

<p>Each key is detailed in <?= $c->link('reference/config') ?>. An invalid value prevents dscs from starting, with a message
that names it.</p>

<h2>Webhooks</h2>

<h3>Adding one</h3>

<p>Just add the URL to the <code>webhooks</code> list. New uploads will be spread over all webhooks: each one sends its
chunks in parallel with the others. One webhook per channel gives the best throughput, since Discord limits the rate per
webhook and per channel.</p>

<p>The same URL listed twice (or two URLs of the same webhook) only counts once.</p>

<h3>Never delete or move a webhook that has been used</h3>

<?= $c->callout('danger', <<<'HTML'
<p>A webhook can only read back and delete <strong>its own messages, in its current channel</strong>. Deleting in Discord a
webhook (or its channel) that has already uploaded chunks makes those chunks permanently unreachable through webhooks.
<strong>Moving it to another channel</strong> makes them unreachable until it is moved back, and loses the vault's entry
point (which dscs recreates). To use a new channel, create a new webhook instead.</p>
HTML) ?>

<p>Removing a URL from <code>config.toml</code>, however, loses nothing: the vault's webhooks are remembered with its keys,
on each device. To stop using a webhook for new uploads, untick it in the upload screen, or name the webhooks you want with
<code>dscs put -w</code>.</p>

<h3>Choosing the webhooks of an upload</h3>

<p>By default, an upload uses every webhook of the vault. To pick some of them (a channel reserved for photos, for
example): tick them in the interface's upload screen (<?= $c->link('guide/interface', null, 'upload-screen-choosing-webhooks') ?>),
or use <code>dscs put -w</code> (<?= $c->link('guide/cli', null, 'uploading-dscs-put') ?>).</p>

<h2>Server and channel names</h2>

<p>Discord does not tell webhooks the <em>name</em> of their server or channel: only their IDs, and the webhook's own
name. To show readable names in the Remote tab, the upload screen and <code>dscs webhooks</code>, map each ID to a name in
<code>config.toml</code>:</p>

<?= $c->code('bash', 'dscs webhooks      # shows the name, server and channel of each webhook, with their IDs') ?>

<?= $c->code('toml', <<<'TOML'
[servers]
"118000000000000000" = "Personal"

[channels]
"119000000000000000" = "storage-1"
"119000000000000001" = "photos"
TOML, 'config.toml') ?>

<p>These names are only used for display, on this device. A channel is shown with a <code>#</code> in front of its name.
Without a name, the ID is shown, with a link that opens the channel in Discord
(<code>https://discord.com/channels/&lt;server&gt;/&lt;channel&gt;</code>).</p>

<h2>Chunk size</h2>

<p><code>chunk_size_mb</code> (9 by default) sets the size of each chunk before encryption. Encryption adds 28 bytes;
Discord rejects attachments above its limit (10 MiB for a server without boosts).</p>

<?= $c->callout('warning', <<<'HTML'
<p>The limit that applies to webhooks is the <strong>server's</strong> (it depends on its boost level), not necessarily your
Nitro subscription's. Before raising <code>chunk_size_mb</code>, check your server's actual limit. If a chunk is too big,
Discord answers <code>413</code>: the upload fails cleanly and its chunks already sent are deleted.</p>
HTML) ?>

<p>Changing the size only affects later uploads: each file keeps its original chunks, and older files remain downloadable.
Accepted values: from 0 (excluded) to 499.</p>

<h2>Downloads and display</h2>

<ul>
  <li><code>download_dir</code>: destination offered by default for downloads (can be changed for each download in the
    interface; <code>dscs get</code> without <code>-o</code>). <code>~</code> is replaced by your home folder. Remote subfolders
    are recreated inside it, unless you choose otherwise.</li>
  <li><code>show_hidden</code>: show hidden files when the interface starts (the <?= $c->kbd('.') ?> key toggles them
    at any time).</li>
</ul>

<h2>Where files live</h2>

<?= $c->table(['Situation', 'Configuration, identity, keyring', 'Index'], [
    ['Default', $c->path('~/.config/dscs/'), $c->path('~/.local/share/dscs/')],
    ['<code>XDG_CONFIG_HOME</code> / <code>XDG_DATA_HOME</code> set', $c->path('$XDG_CONFIG_HOME/dscs/'), $c->path('$XDG_DATA_HOME/dscs/')],
    ['<code>DSCS_HOME=/path</code>', $c->path('/path/'), $c->path('/path/')],
]) ?>

<p><code>DSCS_HOME</code> is handy to keep several independent “vaults” (each with its own identity, keyring and index), or
to experiment without touching your configuration:</p>

<?= $c->code('bash', <<<'SH'
DSCS_HOME=~/personal-vault dscs init
DSCS_HOME=~/personal-vault dscs ~/Documents
SH) ?>

<h2>Passphrase in scripts</h2>

<p>If this device is protected by a passphrase, dscs asks for it at every launch. For a script or a scheduled task, the
<code>DSCS_PASSPHRASE</code> variable provides it. Other programs of your session can see it: keep it for a machine only you
use.</p>

<h2>Network and proxy</h2>

<p>dscs honours the usual proxy environment variables (<code>HTTPS_PROXY</code>, <code>HTTP_PROXY</code>, <code>NO_PROXY</code>).</p>
