<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Commandes';
$page->description = 'Référence complète de chaque sous-commande de dscs : synopsis, arguments, sorties et codes de retour.';
?>
<p>Guide d'utilisation avec exemples : <?= $c->link('guide/cli') ?>. Cette page reprend la définition exacte des commandes
(<code>src/dscs/cli/parser.py</code>).</p>

<h2>Synopsis</h2>

<?= $c->code('text', <<<'TXT'
dscs [-h] [-V] COMMANDE ...

dscs [DOSSIER]               interface (raccourci de « dscs tui »)
dscs tui [DOSSIER]
dscs init [--force]
dscs put CHEMIN [CHEMIN ...] [-w WEBHOOK]...
dscs ls
dscs get ID [-o DOSSIER] [--flat]
dscs rm ID [-y]
dscs gc
dscs webhooks
dscs bench [-s MO] [-w WEBHOOK]... [-p N] [--download] [-y]
dscs sync
dscs device [show | init | add | join | list | revoke | recover | passphrase]
dscs key [list | rotate | recovery]
dscs help                    même chose que dscs --help
TXT) ?>

<p>Si le premier argument n'est ni une commande ni une option, dscs le traite comme un dossier et ouvre l'interface.</p>

<h3>Options globales</h3>
<?= $c->table(['Option', 'Effet'], [
    ['<code>-h</code>, <code>--help</code>', 'Aide générale, ou d\'une commande (<code>dscs get --help</code>)'],
    ['<code>-V</code>, <code>--version</code>', 'Affiche <code>dscs &lt;version&gt;</code>'],
]) ?>

<h2><code>tui</code></h2>
<?= $c->code('text', 'dscs tui [DOSSIER]') ?>
<?= $c->table(['Argument', 'Description'], [
    ['<code>DOSSIER</code>', 'Dossier de départ de l\'arbre. Défaut : dossier courant. <code>~</code> est développé.'],
]) ?>
<p>Erreur si ce n'est pas un dossier : <code>Pas un dossier : … (voir : dscs --help)</code>, code <code>1</code>.
Touches : <?= $c->link('guide/interface') ?>.</p>

<h2><code>init</code></h2>
<?= $c->code('text', 'dscs init [--force]') ?>
<?= $c->table(['Option', 'Description'], [
    ['<code>--force</code>', 'Réécrire une config existante. La clé existante est toujours gardée.'],
]) ?>
<p>Interactif : demande une ou plusieurs URLs de webhook (ligne vide pour terminer), écrit <code>config.toml</code>, crée la
clé si elle n'existe pas. Sans <code>--force</code> et avec une config existante : message et code <code>1</code>.</p>

<h2><code>put</code></h2>
<?= $c->code('text', 'dscs put CHEMIN [CHEMIN ...] [-w WEBHOOK]...') ?>
<?= $c->table(['Argument / option', 'Description'], [
    ['<code>CHEMIN</code>', 'Fichiers et/ou dossiers. Un dossier est envoyé récursivement sous son propre nom.'],
    ['<code>-w</code>, <code>--webhook WEBHOOK</code>', 'N\'utiliser que ce webhook (identifiant ou nom, sans tenir compte des majuscules). Répétable. Défaut : tous les webhooks du coffre.'],
]) ?>
<p>Sortie : une ligne par fichier, <code>✓ [ID] chemin  (taille)</code> ou <code>✗ nom : raison</code>.
<code>Rien à envoyer.</code> et code <code>1</code> si la sélection est vide. Code <code>1</code> si au moins un fichier a échoué.</p>

<h2><code>ls</code></h2>
<?= $c->code('text', 'dscs ls') ?>
<p>Liste les fichiers complets : <code>ID</code>, <code>Taille</code>, <code>Envoyé le</code> (jj/mm/aaaa hh:mm), <code>Chemin</code>,
triés par dossier puis par nom, suivis du total. <code>Aucun fichier stocké.</code> si l'index est vide. Avertissement en fin de
liste s'il reste des envois interrompus. Hors ligne. Code <code>0</code>.</p>

<h2><code>get</code></h2>
<?= $c->code('text', 'dscs get ID [-o DOSSIER] [--flat]') ?>
<?= $c->table(['Argument / option', 'Description'], [
    ['<code>ID</code>', 'Identifiant donné par <code>dscs ls</code>'],
    ['<code>-o</code>, <code>--out DOSSIER</code>', 'Destination. Défaut : <code>download_dir</code> de la config. Le dossier distant y est recréé.'],
    ['<code>--flat</code>', 'Ne pas recréer le dossier distant : le fichier arrive directement dans la destination.'],
]) ?>
<p>Succès : <code>✓ /chemin/du/fichier  (intégrité vérifiée)</code>. ID inconnu : <code>Aucun fichier avec l'ID N (voir : dscs ls)</code>,
code <code>1</code>.</p>

<h2><code>rm</code></h2>
<?= $c->code('text', 'dscs rm ID [-y]') ?>
<?= $c->table(['Argument / option', 'Description'], [
    ['<code>ID</code>', 'Identifiant donné par <code>dscs ls</code>'],
    ['<code>-y</code>, <code>--yes</code>', 'Ne pas demander confirmation'],
]) ?>
<p>Demande <code>Supprimer chemin (taille) de Discord ? [o/N]</code> ; accepte <code>o</code>, <code>oui</code>, <code>y</code>,
<code>yes</code>. Refus ou ID inconnu : code <code>1</code>. Succès : <code>✓ supprimé</code>.</p>

<h2><code>gc</code></h2>
<?= $c->code('text', 'dscs gc') ?>
<p>Supprime de Discord les morceaux des fichiers incomplets (envois interrompus, suppressions partielles). Exige d'être la
seule instance de dscs ouverte.</p>
<?= $c->table(['Sortie', 'Code'], [
    ['<code>Rien à nettoyer.</code>', '<code>0</code>'],
    ['<code>✓ N envoi(s) interrompu(s) nettoyé(s)</code>', '<code>0</code>'],
    ['… suivi de <code>⚠ N restant(s) : Discord injoignable ou webhook absent de la config.</code>', '<code>1</code>'],
]) ?>

<h2><code>webhooks</code></h2>
<?= $c->code('text', 'dscs webhooks') ?>
<p>Pour chaque webhook du coffre : nom et identifiant, serveur, salon, nombre de morceaux stockés, lien
<code>https://discord.com/channels/…</code>. Interroge Discord (nom, identifiants du serveur et du salon) et met à jour le
cache local. Les noms lisibles viennent de <code>config.toml</code> (<?= $c->link('reference/config', null, 'servers-et-channels') ?>).
Code <code>1</code> si le coffre n'a aucun webhook.</p>

<h2><code>bench</code></h2>
<?= $c->code('text', 'dscs bench [-s MO] [-w WEBHOOK]... [-p N] [--download] [-y]') ?>
<p>Mesure le débit d'envoi vers Discord : envoie des données aléatoires (100 Mo par défaut) avec le même schéma que
<code>dscs put</code>, morceaux répartis entre les webhooks, puis <strong>supprime tous les messages de test</strong>,
même en cas d'erreur ou d'interruption. Rien n'entre dans le coffre et aucune clé n'est utilisée. Une confirmation est
demandée (envoi réel vers Discord).</p>
<?= $c->table(['Option', 'Effet'], [
    ['<code>-s MO</code>', 'Quantité envoyée, en Mo (1 à 4096)'],
    ['<code>-w WEBHOOK</code>', 'N\'utiliser que ce webhook (nom ou identifiant, répétable) ; défaut : tous'],
    ['<code>-p N</code>', 'Envois simultanés par webhook (1 à 8 ; défaut 1, comme <code>dscs put</code>)'],
    ['<code>--download</code>', 'Mesurer aussi le téléchargement : les données sont relues avant d\'être supprimées'],
    ['<code>-y</code>', 'Ne pas demander confirmation (obligatoire hors d\'un terminal)'],
]) ?>
<p>Le tableau donne, par webhook, le salon, les morceaux envoyés, le débit et les freinages imposés par Discord
(réponses <code>429</code> et attentes). Pour savoir si un salon de plus vaut le coup, compare le total de
<code>dscs bench</code> avec celui de <code>dscs bench -w &lt;un webhook&gt;</code> : si le total ne monte plus quand tu ajoutes
des salons, c'est ta connexion qui sature. Code <code>1</code> si des messages de test n'ont pas pu être supprimés.</p>

<h2><code>device</code></h2>
<p>Appareils du coffre. Guide pas à pas : <?= $c->link('guide/appareils') ?>.</p>
<?= $c->table(['Commande', 'Effet'], [
    ['<code>dscs device</code> / <code>device show</code>', 'Nom, code de vérification, clé publique et protection de cet appareil (sans phrase de passe)'],
    ['<code>device init [--name NOM]</code>', 'Nouvel appareil : crée son identité (phrase de passe proposée) et affiche sa clé publique et son code'],
    ['<code>device add CLE_PUBLIQUE [--name NOM] [--code CODE]</code>', 'Sur un membre : ajoute l\'appareil après vérification du code (demandée, ou <code>--code</code>), puis affiche l\'invitation et le code de cet appareil'],
    ['<code>device invite</code>', 'Affiche l\'invitation du coffre (adresse du superbloc, sans aucune clé), après <code>device add</code> ou <code>sync --repair</code>'],
    ['<code>device join [INVITATION] [--code CODE]</code>', 'Sur le nouvel appareil : rejoint le coffre après vérification du code de l\'appareil qui l\'a ajouté. Sur un appareil déjà membre : suit la nouvelle adresse du coffre après une réparation. Sans argument, l\'invitation est demandée (saisie masquée)'],
    ['<code>device list</code>', 'Membres du coffre (nom, code, date d\'ajout ; appareils de secours signalés), après synchronisation si Discord est joignable'],
    ['<code>device revoke APPAREIL [-y]</code>', 'Retire un membre (nom, code ou identifiant) et passe à une nouvelle génération de clé'],
    ['<code>device recover [KIT] [--name NOM] [-y]</code>', 'Rejoint le coffre avec un kit de secours ; <code>-y</code> évite de confirmer la liste des membres'],
    ['<code>device passphrase [--remove]</code>', 'Ajoute, change ou retire la phrase de passe de cet appareil'],
]) ?>

<h2><code>key</code></h2>
<?= $c->table(['Commande', 'Effet'], [
    ['<code>dscs key</code> / <code>key list</code>', 'Générations de clés du coffre, la courante marquée <code>*</code>, et le nombre de fichiers chiffrés avec chacune (local)'],
    ['<code>key rotate</code>', 'Nouvelle génération pour les prochains envois, publiée pour tous les membres'],
    ['<code>key recovery [-o FICHIER]</code>', 'Crée un appareil de secours et affiche son kit, ou l\'écrit dans <code>FICHIER</code> (droits <code>600</code>)'],
]) ?>

<h2><code>sync</code></h2>
<?= $c->code('text', 'dscs sync [--repair]') ?>
<p>Reçoit ce que les autres appareils ont publié (trousseau, fichiers, suppressions) et publie l'état de cet appareil.
La synchronisation est aussi faite automatiquement par toutes les commandes réseau. Code <code>1</code> si cet appareil n'a
pas de clé de coffre.</p>
<?= $c->table(['Option', 'Effet'], [
    ['<code>--repair</code>', 'Si le superbloc est introuvable (webhook déplacé vers un autre salon, message supprimé), le recrée même si d\'autres appareils utilisent le coffre ; ils suivront avec <code>dscs device join</code>. Inutile avec un seul appareil : c\'est automatique.'],
]) ?>

<h2>Codes de sortie</h2>
<?= $c->table(['Code', 'Signification'], [
    ['<code>0</code>', 'Succès'],
    ['<code>1</code>', 'Erreur prévue (<code>Erreur : …</code> sur la sortie d\'erreur) ou résultat négatif (échec d\'un fichier, ID inconnu, refus, nettoyage incomplet)'],
    ['<code>2</code>', 'Arguments invalides'],
    ['<code>130</code>', 'Interrompu (' . $c->kbd('Ctrl', 'C') . ')'],
]) ?>

<h2>Variables d'environnement</h2>
<?= $c->table(['Variable', 'Effet'], [
    ['<code>DSCS_HOME</code>', 'Dossier unique pour config, identité, trousseau, index et verrou'],
    ['<code>DSCS_PASSPHRASE</code>', 'Phrase de passe de cet appareil, pour les scripts (sinon demandée)'],
    ['<code>XDG_CONFIG_HOME</code>', 'Base de <code>dscs/config.toml</code>, <code>dscs/device.json</code>, <code>dscs/keyring.sealed</code> (défaut <code>~/.config</code>)'],
    ['<code>XDG_DATA_HOME</code>', 'Base de <code>dscs/index.db</code> (défaut <code>~/.local/share</code>)'],
    ['<code>HTTPS_PROXY</code>, <code>HTTP_PROXY</code>, <code>NO_PROXY</code>', 'Proxy réseau'],
]) ?>
