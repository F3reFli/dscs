<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Premiers pas';
$page->description = 'Un tour complet : envoyer un dossier, le retrouver, le télécharger, le supprimer.';
?>
<p>Ce tutoriel suppose que <code>dscs init</code> est fait (<?= $c->link('guide/installation') ?>). On va envoyer un
petit dossier de test, le récupérer, puis le supprimer, d'abord dans l'interface, puis en ligne de commande.</p>

<h2>Préparer un dossier de test</h2>

<?= $c->code('bash', <<<'SH'
mkdir -p ~/essai-dscs/photos
echo "bonjour" > ~/essai-dscs/notes.txt
head -c 20M /dev/urandom > ~/essai-dscs/photos/gros.bin   # 20 Mo -> 3 morceaux
SH) ?>

<h2>Dans l'interface</h2>

<h3>1. Ouvrir</h3>

<?= $c->code('bash', 'dscs ~/essai-dscs') ?>

<p>L'onglet <strong>Local</strong> montre l'arborescence de <code>~/essai-dscs</code>. Le sous-titre en haut affiche
le nom de tes webhooks une fois que Discord a répondu (<code>→ [webhook]</code>).</p>

<h3>2. Marquer et envoyer</h3>

<ul>
  <li>Descends avec <?= $c->kbd('j') ?> jusqu'à <code>photos</code>, marque-le avec <?= $c->kbd('Espace') ?> :
    un <code>●</code> apparaît devant. Marquer un dossier, c'est marquer tout son contenu.</li>
  <li>Marque aussi <code>notes.txt</code>.</li>
  <li>Appuie sur <?= $c->kbd('u') ?> : l'écran d'envoi liste les fichiers, leur taille, le nombre de morceaux chiffrés
    et les webhooks du coffre, tous cochés. <?= $c->kbd('Espace') ?> décoche ceux que tu ne veux pas utiliser.</li>
  <li><?= $c->kbd('y') ?> pour envoyer, <?= $c->kbd('n') ?> pour annuler.</li>
</ul>

<p>L'écran de transfert affiche la progression, la vitesse estimée et une ligne par fichier
(<code>✓ gros.bin  (20.0 Mo, 3 morceau(x))</code>). À la fin, <?= $c->kbd('Échap') ?> le ferme. Pendant l'envoi,
<?= $c->kbd('c') ?> annule : le fichier en cours est retiré de Discord.</p>

<h3>3. Retrouver</h3>

<p><?= $c->kbd('Tab') ?> passe à l'onglet <strong>Distant</strong> : tes deux fichiers y sont, avec leur dossier
distant (<code>photos</code> pour <code>gros.bin</code>, <code>—</code> pour <code>notes.txt</code> envoyé seul),
leur taille, leur nombre de morceaux et la date d'envoi.</p>

<h3>4. Télécharger</h3>

<p>En haut de l'onglet Distant, le panneau des webhooks montre dans quel serveur et quel salon Discord sont tes morceaux ;
<?= $c->kbd('o') ?> ouvre ce salon.</p>

<p>Place-toi sur <code>gros.bin</code> et appuie sur <?= $c->kbd('Entrée') ?> : une fenêtre propose le dossier de
destination (<code>~/Downloads/dscs</code> par défaut). Garde-le, écris un autre chemin ou choisis un dossier dans l'arbre
(<?= $c->kbd('Tab') ?>), puis <?= $c->kbd('Entrée') ?>. Le fichier arrive dans <code>~/Downloads/dscs/photos/gros.bin</code> :
le dossier distant <code>photos/</code> est recréé, sauf si tu décoches la case correspondante. Le message
<code>Terminé · intégrité vérifiée (SHA-256)</code> confirme qu'il est identique à l'original.</p>

<h3>5. Supprimer</h3>

<p><?= $c->kbd('x') ?> sur une ligne de l'onglet Distant, puis <?= $c->kbd('y') ?> : les messages correspondants sont
supprimés de Discord. Le bouton « Non » est sélectionné par défaut, l'opération étant irréversible.</p>

<p><?= $c->kbd('q') ?> quitte l'interface.</p>

<h2>La même chose en ligne de commande</h2>

<?= $c->code('bash', <<<'SH'
dscs put ~/essai-dscs/photos ~/essai-dscs/notes.txt
SH) ?>

<?= $c->code('text', <<<'TXT'
  ✓ [1] photos/gros.bin  (20.0 Mo)
  ✓ [2] notes.txt  (8 o)
TXT) ?>

<p>Le numéro entre crochets est l'<strong>ID</strong> du fichier, utilisé par les autres commandes.</p>

<?= $c->code('bash', <<<'SH'
dscs ls                       # liste avec les ID
dscs get 1 -o [folder]        # -> [folder]/photos/gros.bin
dscs rm 1                     # demande confirmation (-y pour s'en passer)
SH) ?>

<?= $c->code('text', <<<'TXT'
   ID      Taille  Envoyé le         Chemin
    2         8 o  12/10/2026 10:42  notes.txt
    1     20.0 Mo  12/10/2026 10:42  photos/gros.bin

2 fichier(s), 20.0 Mo
TXT) ?>

<p>Toutes les options : <?= $c->link('guide/cli') ?> et <?= $c->link('reference/cli') ?>.</p>

<h2>Et maintenant</h2>

<ul>
  <li>Sauvegarde ta clé et ton index : <?= $c->link('guide/sauvegarde') ?>.</li>
  <li>Ajoute des webhooks pour accélérer les envois : <?= $c->link('guide/configuration') ?>.</li>
  <li>Comprends ce que voit Discord : <?= $c->link('concepts/chiffrement') ?>.</li>
</ul>
