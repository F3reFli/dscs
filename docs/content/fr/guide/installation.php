<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Installation';
$page->description = 'Installer dscs, créer un webhook Discord et générer ta clé de chiffrement.';
?>
<h2>Prérequis</h2>

<ul>
  <li><strong>Python 3.11 ou plus récent</strong> (<code>python3 --version</code>).</li>
  <li><strong>Un serveur Discord</strong> où tu as la permission <em>Gérer les webhooks</em>. Idéalement un serveur
    privé dédié, avec un ou plusieurs salons réservés au stockage.</li>
  <li>Linux ou macOS. Sous Windows, dscs fonctionne mais sans le verrou entre processus
    (voir <?= $c->link('concepts/index', null, 'verrou-entre-processus') ?>).</li>
</ul>

<p>Les dépendances Python (<code>textual</code>, <code>aiohttp</code>, <code>cryptography</code>) sont installées automatiquement.</p>

<h2>Installer dscs</h2>

<p>Depuis le dossier du projet (celui qui contient <code>pyproject.toml</code>) :</p>

<?= $c->code('bash', <<<'SH'
pipx install .            # recommandé : dscs isolé dans son propre environnement
# ou
pip install --user .
SH) ?>

<p>Sur les distributions qui protègent le Python du système (Debian, Ubuntu…), <code>pip install --user</code> est refusé
(<code>externally-managed-environment</code>) : utilise <code>pipx</code> (<code>sudo apt install pipx</code>).</p>

<p>Vérifie :</p>

<?= $c->code('bash', <<<'SH'
dscs --version
# dscs 0.4.1
SH) ?>

<?= $c->callout('tip', '<p>Pour travailler sur le code lui-même, utilise plutôt <code>make install</code> : voir ' . $c->link('dev/contribuer') . '.</p>') ?>

<h2>Créer un webhook</h2>

<p>Un webhook est une adresse secrète qui permet de poster des messages dans un salon précis, sans bot ni compte.
C'est par là que dscs envoie, relit et supprime ses morceaux.</p>

<?= $c->steps([
    '<p>Dans Discord, ouvre les <strong>paramètres du salon</strong> qui servira au stockage (roue dentée à côté de son nom).</p>',
    '<p>Va dans <strong>Intégrations</strong> → <strong>Webhooks</strong> → <strong>Nouveau webhook</strong>.</p>',
    '<p>Donne-lui un nom parlant (par exemple « Stockage ») : c\'est ce nom que dscs affiche.</p>',
    '<p>Clique sur <strong>Copier l\'URL du webhook</strong>. Elle ressemble à <code>https://discord.com/api/webhooks/123456789/AbCd…</code>.</p>',
]) ?>

<?= $c->callout('warning', <<<'HTML'
<p>Quiconque possède cette URL peut poster dans le salon <em>et supprimer les messages du webhook</em>, donc tes
morceaux. Ne la partage jamais. dscs ne la stocke que dans <code>config.toml</code>, lisible par toi seul.</p>
HTML) ?>

<h3>Plusieurs webhooks pour aller plus vite</h3>

<p>dscs envoie les morceaux d'un fichier en parallèle, un flux par webhook. Les limites de débit de Discord
s'appliquant par webhook et par salon, le plus efficace est <strong>un webhook par salon</strong> : crée par exemple
trois salons <code>#stockage-1</code> à <code>#stockage-3</code>, un webhook dans chacun, et donne les trois URLs à
<code>dscs init</code>.</p>

<h2>Configurer : <code>dscs init</code></h2>

<?= $c->code('text', <<<'TXT'
$ dscs init
Discord : Paramètres du salon → Intégrations → Webhooks → Nouveau webhook → Copier l'URL

URL du webhook : https://discord.com/api/webhooks/123456789/AbCd...
  ✓ webhook « Stockage »
URL du webhook (Entrée pour terminer) :

La clé privée de cet appareil protège toutes les clés du coffre.
La protéger par une phrase de passe ? (recommandé) [O/n]
Nouvelle phrase de passe (8 caractères minimum) :
Confirme-la :

Config    : /home/toi/.config/dscs/config.toml
Appareil  : pc-fixe  (code 3381-7309-6931-3796-0561)
Trousseau : /home/toi/.config/dscs/keyring.sealed  (nouveau coffre, clé générée sur cet appareil)
Index     : /home/toi/.local/share/dscs/index.db

⚠ Sans cet appareil (ou un autre appareil membre du coffre), tout ce qui est stocké est illisible.
  Crée dès ton premier envoi un kit de secours, à garder hors ligne : dscs key recovery
TXT) ?>

<p>Pour chaque URL, dscs :</p>
<ul>
  <li>refuse ce qui n'a pas la forme d'une URL de webhook (<code>✗ URL de webhook invalide : …</code>) ;</li>
  <li>demande confirmation si l'adresse n'est pas sur <code>discord.com</code> ou <code>discordapp.com</code> ;</li>
  <li>interroge Discord pour afficher le nom du webhook. S'il est injoignable, l'URL est quand même gardée
    (<code>⚠ webhook injoignable pour l'instant (gardé quand même)</code>).</li>
</ul>

<p>Appuie sur <?= $c->kbd('Entrée') ?> sur une ligne vide pour terminer. dscs crée alors :</p>
<ul>
  <li>l'<strong>identité de cet appareil</strong> (sa paire de clés), protégée par une phrase de passe si tu le
    choisis : fortement recommandé, elle sera demandée à chaque utilisation ;</li>
  <li>le <strong>trousseau</strong> d'un nouveau coffre, avec une clé de coffre générée sur place ;</li>
  <li>la configuration.</li>
</ul>

<?= $c->callout('note', '<p>Le coffre existe déjà sur un autre de tes appareils ? <strong>Ne lance pas <code>dscs init</code></strong> : rejoins-le avec <code>dscs device init</code> puis <code>dscs device join</code> (' . $c->link('guide/appareils', null, 'ajouter-un-appareil') . ').</p>') ?>

<h3>Relancer <code>init</code></h3>

<p>Si une configuration existe déjà, <code>dscs init</code> s'arrête :</p>

<?= $c->code('text', <<<'TXT'
Config déjà présente : /home/toi/.config/dscs/config.toml
(édite-la, ou relance avec --force)
TXT) ?>

<p><code>dscs init --force</code> réécrit <code>config.toml</code> mais <strong>ne remplace jamais un trousseau existant</strong>
(<code>Trousseau existant conservé : …</code>). Pour simplement ajouter un webhook, édite plutôt le fichier :
voir <?= $c->link('guide/configuration') ?>.</p>

<h2>Fichiers créés</h2>

<?= $c->table(['Fichier', 'Contenu', 'Droits'], [
    [$c->path('~/.config/dscs/device.json'), 'Identité de cet appareil : clé privée (chiffrée si phrase de passe), nom', '<code>600</code>'],
    [$c->path('~/.config/dscs/keyring.sealed'), 'Trousseau du coffre (clés, membres, webhooks), scellé pour cet appareil', '<code>600</code>'],
    [$c->path('~/.config/dscs/config.toml'), 'Réglages, webhooks ajoutés à la main', '<code>600</code>'],
    [$c->path('~/.local/share/dscs/index.db'), 'Copie locale de l\'index, créée au premier lancement', '<code>600</code>'],
]) ?>

<p>Les emplacements suivent <code>XDG_CONFIG_HOME</code> et <code>XDG_DATA_HOME</code> s'ils sont définis.
<code>DSCS_HOME=/un/dossier</code> regroupe tout dans un seul dossier. Détails : <?= $c->link('guide/configuration') ?>.</p>

<?= $c->callout('warning', '<p>Dès ton premier envoi, crée un kit de secours et range-le hors ligne (<code>dscs key recovery</code>) : sans lui, perdre cet appareil, c\'est perdre le coffre. Voir ' . $c->link('guide/sauvegarde') . '.</p>', 'Avant d\'y mettre quoi que ce soit d\'important') ?>

<h2>Venir de dstore ou d'une version précédente</h2>

<ul>
  <li><strong>dstore</strong> (ancien nom) : renomme <code>~/.config/dstore</code> en <code>~/.config/dscs</code> et
    <code>~/.local/share/dstore</code> en <code>~/.local/share/dscs</code> <em>avant</em> de lancer <code>dscs init</code>,
    sinon une nouvelle clé sera créée.</li>
  <li><strong>dscs 0.2</strong> : rien à faire. Au premier lancement, l'ancienne clé en clair (<code>key</code>) devient la
    première génération du trousseau de cet appareil, puis le fichier est effacé (<?= $c->link('guide/appareils', null, 'venir-de-dscs-0-2') ?>).</li>
  <li><strong>dscs 0.1</strong> : rien à faire. L'index est converti automatiquement au premier lancement
    (voir <?= $c->link('concepts/index', null, 'migrations') ?>).</li>
</ul>

<h2>Désinstaller</h2>

<p>Pour tout retirer proprement (données sur Discord, webhooks, programme, clé et index) : <?= $c->link('guide/desinstallation') ?>.</p>
