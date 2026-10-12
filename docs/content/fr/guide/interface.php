<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Interface (TUI)';
$page->description = 'L\'interface au clavier : onglets, touches, confirmation et écran de transfert.';
?>
<h2>Lancer</h2>

<?= $c->code('bash', <<<'SH'
dscs                  # sur le dossier courant
dscs ~/Documents      # sur un autre dossier
dscs tui ls           # sur un dossier qui porte le nom d'une commande (ls, put, gc…)
SH) ?>

<p>L'interface se compose d'un en-tête (titre, puis <code>→ nom des webhooks</code> une fois que Discord a répondu),
de deux onglets et d'une barre de raccourcis en bas.</p>

<?= $c->callout('note', '<p>S\'il reste des envois interrompus dans l\'index, une notification le signale au démarrage : <code>N envoi(s) interrompu(s) : lance « dscs gc » pour nettoyer</code>. Voir ' . $c->link('guide/cli', null, 'nettoyer-dscs-gc') . '.</p>') ?>

<h2>Touches globales</h2>

<?= $c->table(['Touche', 'Action'], [
    [$c->kbd('Tab'), 'Passer de l\'onglet Local à Distant et inversement ; dans une fenêtre, passer au champ suivant'],
    [$c->kbd('1') . ' / ' . $c->kbd('2'), 'Aller directement à l\'onglet Local / Distant'],
    [$c->kbd('q'), 'Quitter'],
]) ?>

<h2>Onglet Local</h2>

<p>Un arbre de fichiers à partir du dossier de lancement, dossiers d'abord. La taille de chaque fichier est affichée
en gris ; les éléments marqués sont précédés d'un <code>●</code>.</p>

<h3>Se déplacer</h3>

<?= $c->table(['Touche', 'Action'], [
    [$c->kbd('j') . ' ' . $c->kbd('k') . ' / ' . $c->kbd('↓') . ' ' . $c->kbd('↑'), 'Ligne suivante / précédente'],
    [$c->kbd('l') . ' / ' . $c->kbd('→'), 'Déplier un dossier ; s\'il est déjà déplié, entrer dedans'],
    [$c->kbd('h') . ' / ' . $c->kbd('←'), 'Replier le dossier ; sinon remonter au dossier parent dans l\'arbre ; à la racine, remonter d\'un niveau sur le disque'],
    [$c->kbd('g') . ' / ' . $c->kbd('G'), 'Tout en haut / tout en bas'],
    [$c->kbd('-'), 'Changer la racine de l\'arbre pour le dossier parent'],
    [$c->kbd('~'), 'Changer la racine de l\'arbre pour ton dossier personnel'],
    [$c->kbd('.'), 'Afficher / masquer les fichiers cachés (masqués par défaut, réglable avec <code>show_hidden</code>)'],
]) ?>

<h3>Marquer et envoyer</h3>

<?= $c->table(['Touche', 'Action'], [
    [$c->kbd('Espace'), 'Marquer / démarquer l\'élément sous le curseur, puis descendre d\'une ligne. Un dossier marqué = tout son contenu, récursivement.'],
    [$c->kbd('u'), 'Envoyer la sélection ; sans marque, l\'élément sous le curseur (fichier ou dossier)'],
    [$c->kbd('Entrée'), 'Sur un fichier : l\'envoyer, ou envoyer la sélection s\'il y a des marques. Sur un dossier : le déplier / replier.'],
]) ?>

<p>Ce qui est envoyé :</p>
<ul>
  <li>un <strong>fichier</strong> sélectionné seul arrive à la racine distante ;</li>
  <li>un <strong>dossier</strong> garde sa structure : <code>projet/src/main.py</code> arrive dans le dossier distant
    <code>projet/src</code> ;</li>
  <li>les liens symboliques <em>à l'intérieur</em> d'un dossier sont ignorés ; un même fichier sélectionné deux fois
    n'est envoyé qu'une fois.</li>
</ul>

<p>Après un envoi, les marques sont effacées et l'onglet Distant est rafraîchi.</p>

<h2>Onglet Distant</h2>

<h3>Les webhooks : serveur et salon</h3>

<p>En haut de l'onglet, un panneau liste les webhooks du coffre : leur <strong>nom</strong>, le <strong>serveur</strong>
et le <strong>salon</strong> Discord où ils postent, le nombre de <strong>morceaux</strong> qu'ils portent, et un lien
<code>ouvrir ↗</code> vers le salon (cliquable dans les terminaux qui gèrent les liens).</p>

<?= $c->callout('note', '<p>Discord ne donne pas aux webhooks le <em>nom</em> de leur serveur ni de leur salon, seulement leurs identifiants. Le panneau affiche donc les identifiants (en gris), sauf si tu leur as donné un nom lisible dans <code>config.toml</code> : voir ' . $c->link('guide/configuration', null, 'noms-des-serveurs-et-des-salons') . '. Les informations sont mises en cache : elles restent affichées hors ligne.</p>') ?>

<h3>Les fichiers</h3>

<p>La liste des fichiers stockés, triée par dossier puis par nom, avec les colonnes <strong>Nom</strong>,
<strong>Dossier</strong> (<code>—</code> pour la racine), <strong>Webhook</strong> (le ou les webhooks qui portent ses
morceaux), <strong>Taille</strong>, <strong>Morceaux</strong> et <strong>Envoyé le</strong>. Cette liste vient de l'index
local : l'afficher ne contacte pas Discord.</p>

<?= $c->table(['Touche', 'Action'], [
    [$c->kbd('j') . ' ' . $c->kbd('k'), 'Ligne suivante / précédente'],
    [$c->kbd('g') . ' / ' . $c->kbd('G'), 'Tout en haut / tout en bas'],
    [$c->kbd('Espace'), 'Marquer / démarquer le fichier sous le curseur, puis descendre d\'une ligne'],
    [$c->kbd('Entrée') . ' / ' . $c->kbd('l') . ' / ' . $c->kbd('d'), 'Télécharger la sélection (ou le fichier sous le curseur) : ouvre le choix de la destination'],
    [$c->kbd('x'), 'Supprimer de Discord la sélection (ou le fichier sous le curseur) — irréversible'],
    [$c->kbd('o'), 'Ouvrir dans Discord le salon qui contient les morceaux du fichier'],
    [$c->kbd('r'), 'Rafraîchir la liste'],
]) ?>

<h3>Sélection multiple</h3>

<p>Comme dans l'onglet Local, <?= $c->kbd('Espace') ?> marque des fichiers (un <code>●</code> apparaît devant leur nom).
Dès qu'il y a des marques, <?= $c->kbd('Entrée') ?> et <?= $c->kbd('x') ?> agissent sur <strong>tous les fichiers
marqués</strong> au lieu du seul fichier sous le curseur :</p>
<ul>
  <li><strong>téléchargement</strong> : une seule fenêtre pour choisir la destination de toute la sélection, puis une
    progression globale avec une ligne par fichier ;</li>
  <li><strong>suppression</strong> : une seule confirmation (nombre de fichiers, taille, messages supprimés), puis une
    progression fichier par fichier. <?= $c->kbd('c') ?> arrête la suppression : les fichiers restants sont gardés.</li>
</ul>
<p>Un échec sur un fichier n'arrête pas les suivants. Les marques sont effacées une fois l'opération terminée.</p>

<h2>Écran d'envoi : choisir les webhooks</h2>

<p>Rien ne part sur le réseau sans confirmation. L'écran d'envoi résume la sélection (les fichiers, 14 au plus puis
<code>… et N autre(s)</code>, le total et le nombre de morceaux chiffrés) et liste les webhooks du coffre, avec leur
serveur et leur salon. <strong>Coche ceux qui doivent recevoir les morceaux</strong> : tous sont cochés par défaut.</p>

<?= $c->table(['Touche', 'Action'], [
    [$c->kbd('j') . ' ' . $c->kbd('k') . ' / ' . $c->kbd('↓') . ' ' . $c->kbd('↑'), 'Se déplacer dans la liste des webhooks'],
    [$c->kbd('g') . ' / ' . $c->kbd('G'), 'Premier / dernier webhook'],
    [$c->kbd('Espace'), 'Cocher / décocher le webhook surligné'],
    [$c->kbd('a'), 'Tout cocher / tout décocher'],
    [$c->kbd('y') . ' / ' . $c->kbd('o'), 'Envoyer'],
    [$c->kbd('n') . ' / ' . $c->kbd('Échap'), 'Annuler'],
    [$c->kbd('l') . ' / ' . $c->kbd('h'), 'Passer aux boutons, puis d\'un bouton à l\'autre (' . $c->kbd('Entrée') . ' appuie)'],
]) ?>

<p>Les morceaux d'un fichier se répartissent entre les webhooks cochés, un flux d'envoi par webhook : en cocher plusieurs
(dans des salons différents) accélère l'envoi. Il faut en cocher au moins un. Le choix est retenu pour les envois
suivants, jusqu'à la fermeture de l'interface.</p>

<h2>Écran de téléchargement : choisir la destination</h2>

<p>La fenêtre est la même pour un fichier ou pour une sélection : la destination vaut pour tous les fichiers.
Le champ <strong>Dossier de destination</strong> est prérempli avec <code>download_dir</code> (ou le dernier dossier
choisi). Tu peux :</p>
<ul>
  <li><strong>écrire</strong> un chemin : <code>~</code> est accepté, et un chemin relatif part du dossier ouvert dans
    l'onglet Local ;</li>
  <li><strong>choisir</strong> un dossier dans l'arbre en dessous, au clavier : <?= $c->kbd('↓') ?> ou <?= $c->kbd('Tab') ?>
    pour y aller, puis <?= $c->kbd('j') ?> <?= $c->kbd('k') ?> <?= $c->kbd('h') ?> <?= $c->kbd('l') ?> comme dans l'onglet
    Local. Le champ suit le curseur, et <?= $c->kbd('Entrée') ?> télécharge dans le dossier surligné. L'arbre part de ton
    dossier personnel ; <?= $c->kbd('h') ?> sur sa racine remonte d'un niveau (vers <code>/mnt</code>, par exemple).</li>
</ul>

<p>Si le fichier est rangé dans un dossier distant (<code>photos/</code>…), la case <strong>Recréer le dossier
distant</strong> décide s'il arrive dans <code>destination/photos/</code> (cochée, par défaut) ou directement dans la
destination. La dernière ligne montre le chemin final ; un dossier qui n'existe pas encore est créé.</p>

<?= $c->table(['Touche', 'Action'], [
    [$c->kbd('Entrée') . ' (dans le champ)', 'Télécharger'],
    [$c->kbd('↓') . ' / ' . $c->kbd('Tab'), 'Passer du champ à l\'arbre, à la case, aux boutons (les lettres, elles, s\'écrivent dans le champ)'],
    [$c->kbd('j') . ' ' . $c->kbd('k') . ' (arbre)', 'Dossier suivant / précédent ; la destination suit'],
    [$c->kbd('l') . ' / ' . $c->kbd('h') . ' (arbre)', 'Ouvrir / entrer dans un dossier ; replier / remonter au parent'],
    [$c->kbd('g') . ' / ' . $c->kbd('G') . ' (arbre)', 'Tout en haut / tout en bas'],
    [$c->kbd('Entrée') . ' (arbre)', 'Télécharger dans le dossier surligné'],
    [$c->kbd('j') . ' ' . $c->kbd('k') . ' / ' . $c->kbd('h') . ' ' . $c->kbd('l'), 'Sur la case ou les boutons : élément suivant / précédent'],
    [$c->kbd('Espace'), 'Cocher / décocher la case « dossier distant »'],
    [$c->kbd('Échap'), 'Annuler'],
]) ?>

<p>Le dossier choisi est retenu pour les téléchargements suivants, jusqu'à la fermeture de l'interface. Un fichier
existant n'est jamais écrasé : le nouveau devient <code>nom (1).ext</code>.</p>

<h2>Confirmation de suppression</h2>

<p>Avant une suppression, une fenêtre rappelle le ou les fichiers et le nombre de messages supprimés. <?= $c->kbd('y') ?> ou
<?= $c->kbd('o') ?> confirme, <?= $c->kbd('n') ?> ou <?= $c->kbd('Échap') ?> annule ; le bouton « Non » a le focus par
défaut, l'opération étant irréversible. <?= $c->kbd('h') ?> et <?= $c->kbd('l') ?> passent d'un bouton à l'autre.</p>

<h2>Écran de transfert</h2>

<p>Il affiche le fichier en cours (<code>[2/5] photo.jpg</code>), une barre de progression avec temps restant estimé,
et un journal : <code>✓</code> en vert pour un fichier réussi, <code>✗</code> en rouge avec la raison pour un échec.
Un échec n'arrête pas les fichiers suivants.</p>

<?= $c->table(['Touche', 'Action'], [
    [$c->kbd('c'), 'Annuler le transfert en cours'],
    [$c->kbd('j') . ' ' . $c->kbd('k') . ' / ' . $c->kbd('g') . ' ' . $c->kbd('G'), 'Faire défiler le journal / aller au début ou à la fin'],
    [$c->kbd('Échap') . ' / ' . $c->kbd('q'), 'Fermer l\'écran (seulement une fois le transfert terminé)'],
]) ?>

<h3>Ce que fait l'annulation</h3>

<ul>
  <li><strong>Envoi</strong> : les morceaux déjà envoyés du fichier <em>en cours</em> sont supprimés de Discord, et
    rien n'apparaît dans l'index pour lui. Les fichiers déjà terminés avant l'annulation restent stockés.</li>
  <li><strong>Téléchargement</strong> : le fichier partiel (<code>.part</code>) est effacé, rien n'est écrit.</li>
</ul>

<p>Si le nettoyage échoue (réseau coupé au mauvais moment), l'envoi reste noté comme interrompu et
<code>dscs gc</code> terminera le travail plus tard.</p>
