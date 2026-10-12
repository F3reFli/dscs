<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Errors';
$page->description = 'The exception hierarchy of dscs and the list of every message, sorted by type.';
?>
<p>Every expected error inherits from <code>DSCSError</code> (<code>src/dscs/errors.py</code>). Its message is written to be shown
as is: the CLI prints it prefixed with <code>Erreur :</code> and exits with code <code>1</code>, the interface shows it in the
transfer log or in a notification. An exception of any other type is a bug. Messages are in French; the second column gives
their meaning. Step-by-step fixes: <?= $c->link('guide/depannage') ?>.</p>

<h2>Hierarchy</h2>

<?= $c->code('text', <<<'TXT'
DSCSError
├── ConfigError       missing or invalid config, identity, keyring or webhook
├── CryptoError       passphrase, sealed blob, tampered chunk, key missing from the keyring
│   └── RevokedError  this device was removed from the vault
├── IntegrityError    rebuilt file differs from the original
├── IndexStateError   unreadable, too recent or locked index
├── SuperblockLostError  vault entry point not found (webhook moved, message deleted)
└── DiscordError      refused by Discord or failure after several attempts (.status)
TXT) ?>

<p>System errors (<code>OSError</code>: unreadable file, full disk…) are also caught by the interfaces during transfers and shown
file by file.</p>

<h2><code>ConfigError</code></h2>
<?= $c->table(['Message', 'Meaning', 'Raised by'], [
    ['<code>Aucune config trouvée. Lance d\'abord : dscs init</code>', 'No config found, run dscs init first', 'Reading the config'],
    ['<code>&lt;file&gt; : TOML invalide (…)</code>', 'Invalid TOML', 'Reading the config'],
    ['<code>Aucun webhook dans &lt;file&gt;</code>', 'No webhook in the file', 'Reading the config'],
    ['<code>URL de webhook invalide : &lt;masked url&gt;</code>', 'Invalid webhook URL', 'Reading the config, <code>dscs init</code>'],
    ['<code>chunk_size_mb doit être un nombre</code>', 'chunk_size_mb must be a number', 'Reading the config'],
    ['<code>chunk_size_mb doit être entre 0 et 499 (actuellement X)</code>', 'chunk_size_mb must be between 0 and 499', 'Reading the config'],
    ['<code>Webhook inconnu : « … ». Webhooks du coffre : …</code>', 'Unknown webhook (list of valid ones follows)', '<code>dscs put -w</code>'],
    ['<code>Plusieurs webhooks s\'appellent « … » : désigne-le par son identifiant.</code>', 'Several webhooks have this name: use its ID', '<code>dscs put -w</code>'],
    ['<code>Aucun webhook : ajoute l\'URL d\'un webhook Discord dans config.toml (clé webhooks).</code>', 'No webhook: add one to config.toml', 'Upload'],
    ['<code>[servers] doit être une table : "identifiant" = "nom"</code> (same for <code>[channels]</code>)', '[servers] must be a table', 'Reading the config'],
    ['<code>Clé invalide dans &lt;file&gt;</code>', 'Invalid key', 'Moving the former dscs 0.2 key into the keyring'],
    ['<code>Aucune clé de coffre sur cet appareil. Crée un coffre avec « dscs init », ou rejoins un coffre existant avec « dscs device join ».</code>', 'No vault key on this device: create or join a vault', 'Upload, download, key and device commands'],
    ['<code>Cet appareil est protégé par une phrase de passe : elle est nécessaire pour continuer.</code>', 'This device is protected by a passphrase: it is needed', 'Opening the keyring with no way to ask'],
    ['<code>Aucune identité d\'appareil (…)</code>', 'No device identity', '<code>dscs device</code> on a device never initialised'],
    ['<code>Cet appareil n\'a pas encore d\'identité : lance d\'abord « dscs device init ».</code>', 'No identity yet: run dscs device init first', '<code>dscs device join</code>'],
    ['<code>Cet appareil a déjà un coffre (…).</code>', 'This device already has a vault', '<code>dscs device join</code>, <code>dscs device recover</code>'],
    ['<code>Ce coffre n\'a pas encore ajouté cet appareil : …</code>', 'This vault has not added this device yet', '<code>dscs device join</code>'],
    ['<code>Cet appareil est déjà membre du coffre.</code>', 'Already a member', '<code>dscs device add</code>'],
    ['<code>Un appareil ne peut pas se retirer lui-même : fais-le depuis un autre appareil membre.</code>', 'A device cannot remove itself', '<code>dscs device revoke</code>'],
    ['<code>Aucun appareil « … » dans le coffre. Membres : …</code>', 'No such device in the vault', '<code>dscs device revoke</code>'],
    ['<code>Invitation invalide…</code>, <code>Kit de secours invalide…</code>, <code>Clé publique d\'appareil invalide…</code>', 'Invalid invitation / kit / public key', 'Reading pasted text'],
    ['<code>Le webhook N est inconnu (ni dans le trousseau du coffre, ni dans config.toml) : ses morceaux sont inaccessibles. Remets son URL dans config.toml (clé webhooks).</code>', 'Webhook N is unknown (neither in the vault keyring nor in config.toml): put its URL back in config.toml', 'Download, deletion (during <code>dscs gc</code>, the file is simply counted as remaining)'],
]) ?>

<h2><code>CryptoError</code></h2>
<?= $c->table(['Message', 'Meaning', 'Raised by'], [
    ['<code>Phrase de passe incorrecte.</code>', 'Wrong passphrase', 'Opening the device identity'],
    ['<code>&lt;path&gt; a été chiffré avec une clé absente du trousseau de cet appareil (empreinte …).</code>', 'Encrypted with a key missing from this keyring', 'Download'],
    ['<code>Bloc scellé illisible : il n\'est pas destiné à cet appareil, ou il a été modifié.</code>', 'Sealed blob not for this device, or modified', 'Opening a keyring'],
    ['<code>Trousseau local scellé par un autre appareil : refusé.</code>', 'Local keyring sealed by another device: refused', 'Opening <code>keyring.sealed</code>'],
    ['<code>Aucun appareil confirmé : le coffre n\'a pas été rejoint.</code>', 'No device confirmed: vault not joined', '<code>dscs device join</code>'],
    ['<code>Ce kit de secours n\'est plus valide (retiré du coffre, ou coffre introuvable).</code>', 'This recovery kit is no longer valid', '<code>dscs device recover</code>'],
    ['<code>L\'index du coffre est chiffré avec une clé que cet appareil n\'a pas encore reçue : …</code>', 'Index encrypted with a key not received yet', 'Synchronisation'],
    ['<code>Cet appareil n\'a plus accès à ce coffre : il en a été retiré depuis un autre appareil.</code> (<code>RevokedError</code>)', 'This device was removed from the vault', 'Synchronisation'],
    ['<code>Morceau corrompu ou mauvaise clé (échec du déchiffrement)</code>', 'Corrupted chunk or wrong key (decryption failed)', 'Download: invalid tag or AAD'],
]) ?>

<h2><code>IntegrityError</code></h2>
<?= $c->table(['Message', 'Meaning', 'Raised by'], [
    ['<code>Index incomplet pour &lt;name&gt; (a/b morceaux)</code>', 'Incomplete index (a/b chunks)', 'Download: the index does not know all the chunks'],
    ['<code>Hash différent pour &lt;name&gt; : fichier corrompu</code>', 'Hash mismatch: corrupted file', 'Download: final SHA-256 differs'],
]) ?>

<h2><code>IndexStateError</code></h2>
<?= $c->table(['Message', 'Meaning', 'Raised by'], [
    ['<code>Index illisible (&lt;file&gt;) : …</code>', 'Unreadable index', 'Opening the index (corrupted file or not SQLite)'],
    ['<code>Index en version N, cette version de dscs ne connaît que la M : mets dscs à jour.</code>', 'Index in version N, this dscs only knows M: update dscs', 'Opening the index'],
    ['<code>Une autre instance de dscs est ouverte : ferme-la avant de lancer cette opération.</code>', 'Another dscs instance is open: close it first', '<code>dscs gc</code>'],
]) ?>

<h2><code>DiscordError</code></h2>
<p>Carries the <code>status</code> attribute (HTTP code) when Discord answered.</p>
<?= $c->table(['Message', 'Meaning', 'Raised by'], [
    ['<code>Discord a répondu &lt;code&gt; : &lt;response&gt;</code>', 'Discord answered &lt;code&gt;', 'Any request refused with a <code>4xx</code> error other than 429'],
    ['<code>Échec après 8 tentatives : …</code>', 'Failed after 8 attempts', 'Any request: repeated 5xx or dropped connections'],
    ['<code>Le message N n\'a plus de pièce jointe</code>', 'Message N no longer has an attachment', 'Download'],
]) ?>

<h2><code>DSCSError</code> (no subtype)</h2>
<?= $c->table(['Message', 'Meaning', 'Raised by'], [
    ['<code>&lt;name&gt; a été modifié pendant l\'envoi : recommence</code>', '…was modified during the upload: try again', 'Upload'],
    ['<code>&lt;name&gt; : format de morceaux N inconnu, mets dscs à jour</code>', 'Unknown chunk format N, update dscs', 'Download'],
    ['<code>Suppression partielle de &lt;name&gt; : relance « dscs gc » pour terminer.</code>', 'Partial deletion: run dscs gc to finish', 'Deletion'],
    ['<code>Chemin refusé (sort du dossier de destination) : …</code>', 'Path refused (leaves the destination folder)', 'Download'],
    ['<code>La destination n\'est pas un dossier : …</code>', 'The destination is not a folder', 'Download'],
    ['<code>Impossible de trouver un nom libre pour &lt;path&gt;</code>', 'Cannot find a free name', 'Download (10,000 namesakes)'],
    ['<code>Synchronisation impossible : le coffre est modifié en continu ailleurs. Réessaie.</code>', 'The vault keeps changing elsewhere: try again', 'Publishing to the vault'],
    ['<code>Point d\'entrée du coffre (superbloc) introuvable sur Discord…</code> (<code>SuperblockLostError</code>)', 'Vault entry point not found: webhook moved to another channel, or message deleted', 'Synchronisation, when several devices use the vault (<code>dscs sync --repair</code>)'],
    ['<code>Le code donné ne correspond pas à cette clé publique : rien n\'a été fait.</code>', 'The code does not match this public key', '<code>dscs device add --code</code>'],
    ['<code>Choisir une phrase de passe nécessite un terminal.</code>', 'Choosing a passphrase needs a terminal', '<code>dscs device passphrase</code> outside a terminal'],
]) ?>

<h2>Handling errors from Python</h2>

<?= $c->code('python', <<<'PY'
from dscs.errors import DSCSError, DiscordError

try:
    await vault.download(rec)
except DiscordError as e:
    if e.status == 404:
        print("message or webhook deleted in Discord")
    raise
except DSCSError as e:
    print(f"Error: {e}")
PY) ?>
