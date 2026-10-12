<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'DSCS';
$page->description = 'Discord Server Cloud Storage : tes fichiers, chiffrés, stockés sur ton propre serveur Discord.';
?>
<p>
  DSCS découpe tes fichiers en morceaux, chiffre chaque morceau en AES-256-GCM et l'envoie comme pièce jointe
  dans un salon Discord, via un ou plusieurs webhooks. Un index local garde la carte de ce qui a été envoyé.
  Tout se pilote depuis le terminal : une interface au clavier façon vim, ou des commandes classiques.
</p>

<?= $c->callout('danger', <<<'HTML'
<p>Stocker des fichiers sur Discord n'est pas un usage prévu par ses conditions d'utilisation : le compte ou le
serveur peut être banni, et les messages supprimés. <strong>Ne mets jamais sur DSCS ta seule copie de quoi que ce soit.</strong></p>
HTML, 'À lire avant tout') ?>

<h2>En trois commandes</h2>

<?= $c->code('bash', <<<'SH'
pipx install .        # depuis le dossier du projet
dscs init             # colle l'URL d'un ou plusieurs webhooks
dscs ~/Documents      # ouvre l'interface sur ce dossier
SH) ?>

<p>Le détail est dans <?= $c->link('guide/installation') ?>, puis <?= $c->link('guide/premiers-pas') ?> te guide
pour ton premier envoi.</p>

<h2>Ce que fait DSCS</h2>

<ul>
  <li><strong>Chiffrement de bout en bout</strong> : Discord ne voit ni le contenu, ni le nom, ni l'extension de tes fichiers.
    Chaque morceau porte un nom aléatoire <code>xxxxxxxxxxxxxxxx.bin</code>.</li>
  <li><strong>Intégrité vérifiée</strong> : un morceau modifié, déplacé ou échangé est détecté, et le fichier
    reconstitué est contrôlé par SHA-256 avant d'être écrit.</li>
  <li><strong>Jamais d'écrasement</strong> : un téléchargement ne remplace jamais un fichier existant
    (<code>notes.md</code> devient <code>notes (1).md</code>).</li>
  <li><strong>Résistant au réseau</strong> : limites de débit de Discord (429), erreurs serveur et coupures sont gérées
    automatiquement, avec nouvelles tentatives.</li>
  <li><strong>Envois en parallèle</strong> : avec plusieurs webhooks, les morceaux partent en même temps sur chacun.</li>
  <li><strong>Rien d'oublié</strong> : un envoi interrompu (crash, coupure) laisse une trace dans l'index et
    <code>dscs gc</code> nettoie ses morceaux sur Discord.</li>
  <li><strong>Plusieurs appareils</strong> : le même coffre sur ton PC, ton portable ou chez une personne de confiance,
    synchronisé via Discord, sans jamais transmettre de clé (<?= $c->link('guide/appareils') ?>).</li>
</ul>

<h2>Ne jamais perdre l'accès</h2>

<p>Chaque appareil a sa propre clé, qui ouvre le trousseau du coffre. Si tu perds l'accès sur <em>tous</em> tes appareils,
tout ce qui est stocké devient <strong>définitivement illisible</strong>. Deux parades :</p>
<ul>
  <li>un <strong>kit de secours</strong>, gardé hors ligne : <code>dscs key recovery</code> ;</li>
  <li>un <strong>deuxième appareil</strong> membre du coffre : <code>dscs device add</code>.</li>
</ul>

<p>Voir <?= $c->link('guide/sauvegarde') ?>.</p>

<h2>Par où continuer</h2>

<?= $c->cards([
    ['slug' => 'guide/installation', 'text' => 'Installer dscs et créer ton premier webhook.'],
    ['slug' => 'guide/premiers-pas', 'text' => 'Envoyer, retrouver, télécharger et supprimer un fichier.'],
    ['slug' => 'guide/interface', 'text' => 'Toutes les touches de l\'interface.'],
    ['slug' => 'guide/cli', 'text' => 'Utiliser dscs sans interface, dans des scripts.'],
    ['slug' => 'guide/appareils', 'text' => 'Le même coffre sur plusieurs appareils.'],
    ['slug' => 'concepts/architecture', 'text' => 'Comment le code est organisé, couche par couche.'],
    ['slug' => 'concepts/chiffrement', 'text' => 'Ce qui est protégé, et ce qui ne l\'est pas.'],
]) ?>
