<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Feuille de route';
$page->description = 'Les évolutions prévues, par ordre de valeur, avec ce qu\'elles résolvent et les pistes de conception.';
?>
<p>Rien de ce qui suit n'existe encore. L'ordre reflète la valeur estimée.</p>

<?= $c->callout('tip', '<p>Déjà fait depuis la version 0.2 : coffre partagé entre appareils, synchronisation chiffrée de l\'index sur Discord, phrase de passe, rotation et révocation, kit de secours (' . $c->link('guide/appareils') . ').</p>', 'Réalisé') ?>

<h2>1. Envoi plus rapide</h2>

<p><strong>Problème</strong> : les fichiers partent l'un après l'autre ; un petit fichier n'occupe qu'un webhook. Avec
beaucoup de petits fichiers, Discord limite le nombre de messages par salon bien avant le débit.</p>
<p><strong>Pistes</strong> : envoyer plusieurs fichiers en même temps sur tous les webhooks, avec un plafond de mémoire
réglable ; alléger la synchronisation (index directement dans le superbloc quand il est petit).</p>

<h2>2. Regroupement des petits fichiers</h2>

<p>Mettre plusieurs petits fichiers dans un même morceau : 20 fichiers deviendraient 1 message au lieu de 20. Nouveau
<code>CHUNK_FORMAT</code> ; un morceau partagé n'est supprimé que lorsque tous ses fichiers le sont.</p>

<h2>3. Récupération par bot</h2>

<p><strong>Problème</strong> : un webhook ne gère que ses propres messages. Un webhook supprimé dans Discord rend ses morceaux
inaccessibles.</p>
<p><strong>Piste</strong> : avec un token de bot (optionnel), lister les salons mémorisés (<code>webhooks.channel_id</code>) et
relire ou supprimer n'importe quel message.</p>

<h2>4. Reprise d'envoi</h2>

<p>Reprendre un gros envoi interrompu au premier morceau manquant, si le fichier local n'a pas changé.</p>

<h2>5. Opérations distantes</h2>

<p>Renommer et déplacer des fichiers (simple mise à jour de l'index partagé), dossiers vides, recherche et filtres dans
l'onglet Distant, téléchargement d'un dossier entier.</p>

<h2>6. Compression</h2>

<p>Compresser avant de chiffrer (zstd), seulement quand c'est utile. Nouveau <code>CHUNK_FORMAT</code>. Attention : la
compression peut faire fuir de l'information par la taille ; à garder en option.</p>

<h2>Contribuer</h2>

<p>Avant d'attaquer un de ces points, lis <?= $c->link('concepts/architecture') ?> et <?= $c->link('dev/contribuer') ?> : la plupart
se traduisent par une méthode de Vault, une commande, une migration d'index et leurs tests.</p>
