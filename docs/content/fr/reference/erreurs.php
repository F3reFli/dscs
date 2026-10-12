<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Erreurs';
$page->description = 'La hiérarchie des exceptions de dscs et la liste de tous les messages, classés par type.';
?>
<p>Toutes les erreurs prévues héritent de <code>DSCSError</code> (<code>src/dscs/errors.py</code>). Leur message est écrit pour
être montré tel quel : la CLI l'affiche précédé de <code>Erreur :</code> et sort avec le code <code>1</code>, l'interface
l'affiche dans le journal du transfert ou dans une notification. Une exception d'un autre type est un bug.
Solutions pas à pas : <?= $c->link('guide/depannage') ?>.</p>

<h2>Hiérarchie</h2>

<?= $c->code('text', <<<'TXT'
DSCSError
├── ConfigError       config, identité, trousseau ou webhook manquant ou invalide
├── CryptoError       phrase de passe, bloc scellé, morceau altéré, clé absente du trousseau
│   └── RevokedError  cet appareil a été retiré du coffre
├── IntegrityError    fichier reconstitué différent de l'original
├── IndexStateError   index illisible, trop récent, verrouillé
├── SuperblockLostError  point d'entrée du coffre introuvable (webhook déplacé, message supprimé)
└── DiscordError      refus de Discord ou échec après plusieurs tentatives (.status)
TXT) ?>

<p>Les erreurs système (<code>OSError</code> : fichier illisible, disque plein…) sont aussi rattrapées par les interfaces pendant
les transferts et affichées fichier par fichier.</p>

<h2><code>ConfigError</code></h2>
<?= $c->table(['Message', 'Levée par'], [
    ['<code>Aucune config trouvée. Lance d\'abord : dscs init</code>', 'Lecture de la config'],
    ['<code>&lt;fichier&gt; : TOML invalide (…)</code>', 'Lecture de la config'],
    ['<code>Aucun webhook dans &lt;fichier&gt;</code>', 'Lecture de la config'],
    ['<code>URL de webhook invalide : &lt;url masquée&gt;</code>', 'Lecture de la config, <code>dscs init</code>'],
    ['<code>chunk_size_mb doit être un nombre</code>', 'Lecture de la config'],
    ['<code>chunk_size_mb doit être entre 0 et 499 (actuellement X)</code>', 'Lecture de la config'],
    ['<code>Webhook inconnu : « … ». Webhooks du coffre : …</code>', '<code>dscs put -w</code>'],
    ['<code>Plusieurs webhooks s\'appellent « … » : désigne-le par son identifiant.</code>', '<code>dscs put -w</code>'],
    ['<code>Aucun webhook : ajoute l\'URL d\'un webhook Discord dans config.toml (clé webhooks).</code>', 'Envoi'],
    ['<code>[servers] doit être une table : "identifiant" = "nom"</code> (idem <code>[channels]</code>)', 'Lecture de la config'],
    ['<code>Clé invalide dans &lt;fichier&gt;</code>', 'Transfert de l\'ancienne clé de dscs 0.2 dans le trousseau'],
    ['<code>Aucune clé de coffre sur cet appareil. Crée un coffre avec « dscs init », ou rejoins un coffre existant avec « dscs device join ».</code>', 'Envoi, téléchargement, commandes de clés et d\'appareils'],
    ['<code>Cet appareil est protégé par une phrase de passe : elle est nécessaire pour continuer.</code>', 'Ouverture du trousseau sans moyen de demander la phrase'],
    ['<code>Aucune identité d\'appareil (…)</code>', '<code>dscs device</code> sur un appareil jamais initialisé'],
    ['<code>Cet appareil n\'a pas encore d\'identité : lance d\'abord « dscs device init ».</code>', '<code>dscs device join</code>'],
    ['<code>Cet appareil a déjà un coffre (…).</code>', '<code>dscs device join</code>, <code>dscs device recover</code>'],
    ['<code>Ce coffre n\'a pas encore ajouté cet appareil : …</code>', '<code>dscs device join</code>'],
    ['<code>Cet appareil est déjà membre du coffre.</code>', '<code>dscs device add</code>'],
    ['<code>Un appareil ne peut pas se retirer lui-même : fais-le depuis un autre appareil membre.</code>', '<code>dscs device revoke</code>'],
    ['<code>Aucun appareil « … » dans le coffre. Membres : …</code>', '<code>dscs device revoke</code>'],
    ['<code>Invitation invalide…</code>, <code>Kit de secours invalide…</code>, <code>Clé publique d\'appareil invalide…</code>', 'Lecture d\'un texte collé'],
    ['<code>Le webhook N est inconnu (ni dans le trousseau du coffre, ni dans config.toml) : ses morceaux sont inaccessibles. Remets son URL dans config.toml (clé webhooks).</code>', 'Téléchargement, suppression (pendant <code>dscs gc</code>, le fichier est simplement compté dans les restants)'],
]) ?>

<h2><code>CryptoError</code></h2>
<?= $c->table(['Message', 'Levée par'], [
    ['<code>Phrase de passe incorrecte.</code>', 'Ouverture de l\'identité de l\'appareil'],
    ['<code>&lt;chemin&gt; a été chiffré avec une clé absente du trousseau de cet appareil (empreinte …).</code>', 'Téléchargement'],
    ['<code>Bloc scellé illisible : il n\'est pas destiné à cet appareil, ou il a été modifié.</code>', 'Ouverture d\'un trousseau'],
    ['<code>Trousseau local scellé par un autre appareil : refusé.</code>', 'Ouverture de <code>keyring.sealed</code>'],
    ['<code>Aucun appareil confirmé : le coffre n\'a pas été rejoint.</code>', '<code>dscs device join</code>'],
    ['<code>Ce kit de secours n\'est plus valide (retiré du coffre, ou coffre introuvable).</code>', '<code>dscs device recover</code>'],
    ['<code>L\'index du coffre est chiffré avec une clé que cet appareil n\'a pas encore reçue : …</code>', 'Synchronisation'],
    ['<code>Cet appareil n\'a plus accès à ce coffre : il en a été retiré depuis un autre appareil.</code> (<code>RevokedError</code>)', 'Synchronisation'],
    ['<code>Morceau corrompu ou mauvaise clé (échec du déchiffrement)</code>', 'Téléchargement : tag ou AAD invalide'],
]) ?>

<h2><code>IntegrityError</code></h2>
<?= $c->table(['Message', 'Levée par'], [
    ['<code>Index incomplet pour &lt;nom&gt; (a/b morceaux)</code>', 'Téléchargement : l\'index ne connaît pas tous les morceaux'],
    ['<code>Hash différent pour &lt;nom&gt; : fichier corrompu</code>', 'Téléchargement : SHA-256 final différent'],
]) ?>

<h2><code>IndexStateError</code></h2>
<?= $c->table(['Message', 'Levée par'], [
    ['<code>Index illisible (&lt;fichier&gt;) : …</code>', 'Ouverture de l\'index (fichier corrompu ou pas SQLite)'],
    ['<code>Index en version N, cette version de dscs ne connaît que la M : mets dscs à jour.</code>', 'Ouverture de l\'index'],
    ['<code>Une autre instance de dscs est ouverte : ferme-la avant de lancer cette opération.</code>', '<code>dscs gc</code>'],
]) ?>

<h2><code>DiscordError</code></h2>
<p>Porte l'attribut <code>status</code> (code HTTP) quand Discord a répondu.</p>
<?= $c->table(['Message', 'Levée par'], [
    ['<code>Discord a répondu &lt;code&gt; : &lt;réponse&gt;</code>', 'Toute requête refusée par une erreur <code>4xx</code> autre que 429'],
    ['<code>Échec après 8 tentatives : …</code>', 'Toute requête : 5xx ou coupures répétées'],
    ['<code>Le message N n\'a plus de pièce jointe</code>', 'Téléchargement'],
]) ?>

<h2><code>DSCSError</code> (sans sous-type)</h2>
<?= $c->table(['Message', 'Levée par'], [
    ['<code>&lt;nom&gt; a été modifié pendant l\'envoi : recommence</code>', 'Envoi'],
    ['<code>&lt;nom&gt; : format de morceaux N inconnu, mets dscs à jour</code>', 'Téléchargement'],
    ['<code>Suppression partielle de &lt;nom&gt; : relance « dscs gc » pour terminer.</code>', 'Suppression'],
    ['<code>Chemin refusé (sort du dossier de destination) : …</code>', 'Téléchargement'],
    ['<code>La destination n\'est pas un dossier : …</code>', 'Téléchargement'],
    ['<code>Impossible de trouver un nom libre pour &lt;chemin&gt;</code>', 'Téléchargement (10 000 homonymes)'],
    ['<code>Synchronisation impossible : le coffre est modifié en continu ailleurs. Réessaie.</code>', 'Publication sur le coffre'],
    ['<code>Point d\'entrée du coffre (superbloc) introuvable sur Discord…</code> (<code>SuperblockLostError</code>)', 'Synchronisation, quand plusieurs appareils utilisent le coffre (<code>dscs sync --repair</code>)'],
    ['<code>Le code donné ne correspond pas à cette clé publique : rien n\'a été fait.</code>', '<code>dscs device add --code</code>'],
    ['<code>Choisir une phrase de passe nécessite un terminal.</code>', '<code>dscs device passphrase</code> hors terminal'],
]) ?>

<h2>Utiliser les erreurs depuis Python</h2>

<?= $c->code('python', <<<'PY'
from dscs.errors import DSCSError, DiscordError

try:
    await vault.download(rec)
except DiscordError as e:
    if e.status == 404:
        print("message ou webhook supprimé dans Discord")
    raise
except DSCSError as e:
    print(f"Erreur : {e}")
PY) ?>
