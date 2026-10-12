<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Désinstallation';
$page->description = 'Retirer complètement DSCS : tes données sur Discord, les webhooks, le programme et tous les fichiers locaux.';
?>
<p>Une désinstallation complète se fait dans cet ordre : <strong>récupérer</strong> ce que tu veux garder,
<strong>effacer</strong> les données sur Discord, <strong>révoquer</strong> les webhooks, puis seulement
<strong>supprimer</strong> le programme et ses fichiers locaux.</p>

<?= $c->callout('danger', <<<'HTML'
<p>L'ordre compte. Sans la clé, les morceaux restés sur Discord deviennent illisibles. Sans l'index, dscs ne sait plus quels
messages supprimer. Ne supprime donc tes fichiers locaux qu'<strong>après</strong> avoir fait le ménage sur Discord.</p>
HTML, 'Avant de commencer') ?>

<?= $c->callout('tip', '<p>Tu veux seulement retirer DSCS de <strong>cet</strong> appareil, et garder le coffre sur tes autres appareils ? Depuis l\'un d\'eux, retire celui-ci (<code>dscs device revoke &lt;nom&gt;</code>), puis passe directement aux étapes 4 et 5 ici. Le coffre et ses fichiers restent intacts ailleurs.</p>') ?>

<h2>1. Récupérer ce que tu veux garder</h2>

<p>Liste ce qui est stocké, puis télécharge ce dont tu n'as pas d'autre copie :</p>

<?= $c->code('bash', <<<'SH'
dscs ls
dscs get 12 -o ~/recuperation      # un fichier
SH) ?>

<p>Pour tout récupérer d'un coup (chaque fichier retrouve son dossier sous <code>~/recuperation</code>) :</p>

<?= $c->code('bash', <<<'SH'
dscs ls | awk '$4 ~ /^[0-9]+\/[0-9]+\/[0-9]+$/ {print $1}' | xargs -r -n1 dscs get -o ~/recuperation
SH) ?>

<p>Le filtre <code>awk</code> ne garde que les lignes de fichiers (celles qui contiennent une date) et en extrait l'ID.</p>

<?= $c->callout('tip', '<p>Tu veux seulement arrêter d\'utiliser DSCS sur cette machine, en gardant la possibilité de tout retrouver plus tard ? Sauvegarde la clé, l\'index et <code>config.toml</code> (voir ' . $c->link('guide/sauvegarde') . '), saute les étapes 2 et 3, et passe directement à l\'étape 4.</p>') ?>

<h2>2. Effacer les données sur Discord</h2>

<p>Deux méthodes. Choisis la première si les salons servent aussi à autre chose ; la seconde est la plus rapide et la plus sûre
si les salons ne servaient qu'au stockage.</p>

<h3>Méthode A : avec dscs, fichier par fichier</h3>

<?= $c->code('bash', <<<'SH'
# supprime de Discord tous les fichiers listés, sans confirmation
dscs ls | awk '$4 ~ /^[0-9]+\/[0-9]+\/[0-9]+$/ {print $1}' | xargs -r -n1 dscs rm -y

# supprime les morceaux des envois interrompus
dscs gc

# vérifie : doit afficher « Aucun fichier stocké. » sans avertissement
dscs ls
SH) ?>

<p>Ferme l'interface avant de lancer <code>dscs gc</code> : il refuse de tourner si une autre instance est ouverte. S'il annonce
des fichiers « restants », relance-le une fois le réseau revenu.</p>

<h3>Méthode B : supprimer les salons dans Discord</h3>

<p>Supprimer un salon supprime d'un coup tous ses messages, donc tous les morceaux qu'il contient, y compris ceux que
l'index ne connaîtrait plus. Pour chaque salon de stockage : <strong>Paramètres du salon</strong> → <strong>Supprimer le salon</strong>.
Si tout un serveur était dédié à DSCS, tu peux aussi supprimer le serveur (<strong>Paramètres du serveur</strong> → <strong>Supprimer le serveur</strong>).</p>

<h2>3. Révoquer les webhooks</h2>

<p>Une URL de webhook reste valable tant que le webhook existe : quelqu'un qui l'aurait récupérée pourrait encore poster dans
le salon. Si tu as gardé les salons (méthode A), supprime chaque webhook : <strong>Paramètres du salon</strong> →
<strong>Intégrations</strong> → <strong>Webhooks</strong> → choisis le webhook → <strong>Supprimer le webhook</strong>.
Supprimer le salon (méthode B) supprime aussi ses webhooks.</p>

<h2>4. Désinstaller le programme</h2>

<p>Avec la même méthode que pour l'installation :</p>

<?= $c->code('bash', <<<'SH'
pipx uninstall dscs        # si installé avec pipx
pip uninstall dscs         # si installé avec pip
SH) ?>

<p>Vérifie qu'il n'y a plus de commande <code>dscs</code> : <code>command -v dscs</code> ne doit rien afficher.</p>

<h2>5. Supprimer les fichiers locaux</h2>

<?= $c->table(['Emplacement', 'Contenu'], [
    [$c->path('~/.config/dscs/'), '<code>device.json</code> (clé de l\'appareil), <code>keyring.sealed</code> (trousseau), <code>config.toml</code> (réglages, webhooks)'],
    [$c->path('~/.local/share/dscs/'), '<code>index.db</code> (et ses fichiers <code>-wal</code> / <code>-shm</code>), <code>index.lock</code>'],
    [$c->path('~/.config/dstore/'), $c->path('~/.local/share/dstore/') . ' : anciens dossiers, si tu as utilisé dstore'],
]) ?>

<?= $c->code('bash', <<<'SH'
# les fichiers secrets d'abord, en écrasant leur contenu avant de les supprimer
shred -u ~/.config/dscs/device.json ~/.config/dscs/keyring.sealed
shred -u ~/.config/dscs/key 2>/dev/null           # ancienne clé de dscs 0.2, si elle existe encore

# puis le reste
rm -rf ~/.config/dscs ~/.local/share/dscs
rm -rf ~/.config/dstore ~/.local/share/dstore     # anciens dossiers, s'ils existent
SH) ?>

<?= $c->callout('note', <<<'HTML'
<p>Si tu avais défini <code>XDG_CONFIG_HOME</code> ou <code>XDG_DATA_HOME</code>, les dossiers sont
<code>$XDG_CONFIG_HOME/dscs</code> et <code>$XDG_DATA_HOME/dscs</code>. Si tu utilisais <code>DSCS_HOME</code>, supprime
chacun des dossiers que tu lui as donnés.</p>
<p>Sur un SSD, <code>shred</code> ne garantit pas que les anciennes données sont physiquement effacées. Ce n'est grave que si
des morceaux chiffrés avec ces clés existent encore sur Discord : d'où l'étape 2.</p>
HTML) ?>

<h3>Dossier de téléchargement</h3>

<p>Les fichiers téléchargés sont dans <code>~/Downloads/dscs/</code> (ou le <code>download_dir</code> de ta config). Ce sont tes
fichiers : vérifie leur contenu avant de supprimer ce dossier.</p>

<h3>Copies de sauvegarde</h3>

<p>N'oublie pas ce qui est rangé ailleurs : <strong>kit de secours</strong> (imprimé, sur clé USB), copies du dossier
<code>~/.config/dscs</code>, ancienne clé de dscs 0.2. Une fois les données effacées de Discord, ils ne servent plus à rien :
détruis-les. Pense aussi aux <strong>autres appareils</strong> membres du coffre : désinstalle DSCS sur chacun.</p>

<h2>6. Environnement de développement</h2>

<p>Si tu as travaillé sur le code du projet :</p>

<?= $c->code('bash', <<<'SH'
cd chemin/vers/Dscs
make clean                                  # caches Python, build
rm -rf .venv docs/vendor docs/tools         # venv et dépendances PHP de la doc
# ou supprimer tout le dossier du projet
SH) ?>

<h2>Vérification finale</h2>

<?= $c->code('bash', <<<'SH'
command -v dscs                                   # rien
ls ~/.config/dscs ~/.local/share/dscs 2>&1        # « Aucun fichier ou dossier de ce nom »
SH) ?>

<p>Côté Discord : les salons de stockage sont vides ou supprimés, et plus aucun webhook DSCS n'apparaît dans
<strong>Intégrations</strong>.</p>
