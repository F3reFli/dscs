<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Ligne de commande';
$page->description = 'Envoyer, lister, récupérer, supprimer et nettoyer sans ouvrir l\'interface.';
?>
<p>Toutes les opérations de l'interface existent en commandes, pratiques pour les scripts et les tâches planifiées.
La référence complète des options est dans <?= $c->link('reference/cli') ?>.</p>

<h2>Envoyer : <code>dscs put</code></h2>

<?= $c->code('bash', <<<'SH'
dscs put rapport.pdf                   # un fichier -> racine distante
dscs put ~/Photos/2026                 # un dossier -> dossier distant « 2026 »
dscs put *.iso archives/ notes.md      # plusieurs chemins à la fois
SH) ?>

<p>Les dossiers sont parcourus récursivement et gardent leur structure sous leur propre nom (pas leur chemin complet) :
<code>~/Photos/2026/janvier/a.jpg</code> est rangé dans <code>2026/janvier</code>. Chaque fichier affiche une barre de
progression (dans un terminal), puis son résultat :</p>

<?= $c->code('text', <<<'TXT'
  ✓ [14] 2026/janvier/a.jpg  (3.2 Mo)
  ✗ verrouille.db : [Errno 13] Permission denied: '/home/toi/Photos/2026/verrouille.db'
TXT) ?>

<p>Un échec n'arrête pas les autres fichiers ; le code de sortie vaut alors <code>1</code>. Si un fichier change
pendant son envoi, il est refusé (<code>… a été modifié pendant l'envoi : recommence</code>) et ses morceaux sont
retirés de Discord.</p>

<h3>Choisir les webhooks</h3>

<p>Par défaut, les morceaux se répartissent sur tous les webhooks du coffre. <code>-w</code> (ou <code>--webhook</code>,
répétable) n'en utilise que certains, désignés par leur identifiant ou leur nom (sans tenir compte des majuscules) :</p>

<?= $c->code('bash', <<<'SH'
dscs put -w photos ~/Photos/2026              # seulement le webhook nommé « photos »
dscs put -w 1180000000000000 -w archives x.iso  # deux webhooks, par identifiant et par nom
SH) ?>

<p>Un nom inconnu est refusé avant tout envoi, avec la liste des webhooks du coffre :
<code>Webhook inconnu : « … ». Webhooks du coffre : …</code>. <code>dscs webhooks</code> donne leurs noms et identifiants.</p>

<h2>Lister : <code>dscs ls</code></h2>

<?= $c->code('text', <<<'TXT'
$ dscs ls
   ID      Taille  Envoyé le         Chemin
    3      1.2 Mo  12/10/2026 09:58  rapport.pdf
   14      3.2 Mo  12/10/2026 10:05  2026/janvier/a.jpg

2 fichier(s), 4.4 Mo
TXT) ?>

<p><code>ls</code> lit seulement l'index local : il fonctionne hors ligne. S'il reste des envois interrompus, une ligne
<code>⚠ N envoi(s) interrompu(s) laissent des morceaux sur Discord : lance « dscs gc ».</code> s'ajoute à la fin.</p>

<h2>Récupérer : <code>dscs get</code></h2>

<?= $c->code('bash', <<<'SH'
dscs get 14                 # -> download_dir/2026/janvier/a.jpg
dscs get 14 -o ~/Bureau     # -> ~/Bureau/2026/janvier/a.jpg
SH) ?>

<p>Le dossier distant est recréé sous la destination ; <code>--flat</code> place le fichier directement dans la
destination (<code>dscs get 14 -o ~/Bureau --flat</code> → <code>~/Bureau/a.jpg</code>). Un fichier existant n'est jamais écrasé : le nouveau devient
<code>a (1).jpg</code>. La ligne <code>✓ /chemin/complet  (intégrité vérifiée)</code> confirme que le SHA-256
correspond à l'original.</p>

<h2>Webhooks : <code>dscs webhooks</code></h2>

<p>Liste les webhooks du coffre avec leur serveur, leur salon, le nombre de morceaux qu'ils portent et un lien qui ouvre
le salon dans Discord. Les informations sont redemandées à Discord à chaque appel.</p>

<?= $c->code('text', <<<'TXT'
$ dscs webhooks
Stockage  (1180000000000000001)
  Serveur : Perso (1170000000000000000)
  Salon   : 1190000000000000000
  Morceaux stockés : 42
  Ouvrir  : https://discord.com/channels/1170000000000000000/1190000000000000000

Discord ne donne pas aux webhooks le nom de leur serveur ni de leur salon : tu peux les nommer
dans config.toml, sections [servers] et [channels] ("identifiant" = "nom").
TXT) ?>

<p>Voir <?= $c->link('guide/configuration', null, 'noms-des-serveurs-et-des-salons') ?> pour leur donner des noms lisibles.</p>

<h2>Supprimer : <code>dscs rm</code></h2>

<?= $c->code('bash', <<<'SH'
dscs rm 14          # Supprimer 2026/janvier/a.jpg (3.2 Mo) de Discord ? [o/N]
dscs rm 14 -y       # sans confirmation (scripts)
SH) ?>

<p>La confirmation accepte <code>o</code>, <code>oui</code>, <code>y</code> ou <code>yes</code> ; toute autre réponse annule
(code de sortie <code>1</code>).</p>

<h2>Nettoyer : <code>dscs gc</code></h2>

<p>Un envoi interrompu brutalement (crash, coupure de courant, <?= $c->kbd('Ctrl', 'C') ?> au mauvais moment, réseau
coupé pendant le nettoyage) laisse des morceaux sur Discord. L'index s'en souvient : <code>dscs gc</code> les supprime.</p>

<?= $c->code('text', <<<'TXT'
$ dscs gc
  ✓ 1 envoi(s) interrompu(s) nettoyé(s)
TXT) ?>

<ul>
  <li><code>Rien à nettoyer.</code> : tout est propre.</li>
  <li><code>⚠ N restant(s) : Discord injoignable ou webhook absent de la config.</code> : relance plus tard, ou remets
    le webhook manquant dans la configuration. Code de sortie <code>1</code>.</li>
</ul>

<?= $c->callout('note', '<p><code>dscs gc</code> refuse de tourner si une autre instance de dscs est ouverte (interface ou commande), pour ne jamais prendre un envoi en cours pour un envoi interrompu. Ferme-la d\'abord.</p>') ?>

<h2>Appareils, clés et synchronisation</h2>

<p><code>dscs device</code>, <code>dscs key</code> et <code>dscs sync</code> gèrent le partage du coffre entre appareils, les
générations de clés et le kit de secours : voir <?= $c->link('guide/appareils') ?>. Toutes les commandes qui contactent
Discord synchronisent automatiquement le coffre (réception au début, publication à la fin).</p>

<h2>Interface : <code>dscs</code> / <code>dscs tui</code></h2>

<p>Sans commande, <code>dscs</code> ouvre l'interface sur le dossier courant, et <code>dscs DOSSIER</code> sur ce dossier.
Si ton dossier s'appelle comme une commande (<code>ls</code>, <code>put</code>, <code>gc</code>…), écris
<code>dscs tui ls</code> ou <code>dscs ./ls</code>.</p>

<h2>Dans un script</h2>

<?= $c->code('bash', <<<'SH'
#!/usr/bin/env bash
# Sauvegarde nocturne d'un dossier, en gardant une trace des échecs
set -u
if ! dscs put ~/Documents/compta >> ~/dscs.log 2>&1; then
  echo "$(date) : au moins un fichier a échoué" >> ~/dscs.log
fi
dscs gc >> ~/dscs.log 2>&1
SH) ?>

<p>Hors d'un terminal, la barre de progression n'est pas affichée : seules les lignes de résultat sont écrites.</p>

<h3>Codes de sortie</h3>

<?= $c->table(['Code', 'Signification'], [
    ['<code>0</code>', 'Succès'],
    ['<code>1</code>', 'Erreur (message <code>Erreur : …</code> sur la sortie d\'erreur), au moins un fichier en échec, ID inconnu, confirmation refusée, nettoyage incomplet'],
    ['<code>2</code>', 'Arguments invalides (message d\'argparse)'],
    ['<code>130</code>', 'Interrompu par ' . $c->kbd('Ctrl', 'C') . ' (<code>Interrompu.</code>)'],
]) ?>
