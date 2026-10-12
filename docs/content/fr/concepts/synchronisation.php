<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Synchronisation';
$page->description = 'Comment plusieurs appareils partagent le même coffre via Discord, sans serveur et sans rien révéler.';
?>
<p>Il n'y a pas de serveur DSCS : les appareils d'un coffre s'échangent tout via Discord, avec les mêmes webhooks que
pour les fichiers. Ce qu'ils publient est chiffré ou scellé ; Discord ne voit que des blocs illisibles.</p>

<h2>Le superbloc</h2>

<p>Un webhook ne peut pas lister un salon. Il faut donc un point d'entrée connu : le <strong>superbloc</strong>, un
message posté par le premier webhook du coffre, dont la pièce jointe (nom aléatoire) est remplacée à chaque publication.
Son adresse (webhook + identifiant du message) est rangée dans le trousseau, et donnée dans les invitations.</p>

<?= $c->table(['Contenu du superbloc', 'Lisible par'], [
    ['Les trousseaux scellés, rangés par appareil destinataire puis par appareil qui les a scellés', 'Chaque destinataire, pour le sien'],
    ['Le pointeur vers le dernier instantané d\'index : numéro de séquence, clé utilisée, empreinte (HMAC), morceaux', 'Tout le monde, mais l\'instantané est chiffré'],
]) ?>

<h2>L'instantané d'index</h2>

<p>L'index partagé contient les fichiers complets (avec la liste de leurs morceaux) et les <strong>pierres
tombales</strong> des fichiers supprimés. Il est sérialisé de façon déterministe, compressé, puis chiffré avec la clé de
coffre courante ; le numéro de séquence fait partie des données authentifiées. L'instantané est envoyé comme des morceaux
ordinaires, et l'ancien est supprimé une fois le nouveau publié.</p>

<p>L'empreinte (un HMAC calculé avec la clé) permet à un appareil de voir « rien de neuf » sans rien télécharger, sans
rien révéler du contenu.</p>

<h2>Recevoir, publier</h2>

<?= $c->table(['Moment', 'Ce qui se passe'], [
    ['Début de chaque commande réseau, ouverture de l\'interface', '<strong>Réception</strong> : lecture du superbloc, fusion des trousseaux scellés pour cet appareil, téléchargement de l\'instantané s\'il est plus récent que celui connu'],
    ['Fin d\'une commande qui a envoyé ou supprimé, fin d\'un transfert dans l\'interface, <code>dscs sync</code>', '<strong>Publication</strong> : réception d\'abord, puis nouvel instantané (séquence + 1) et/ou trousseau re-scellé pour chaque membre'],
]) ?>

<p>Avant de publier, l'appareil relit le superbloc : s'il a changé entre-temps (un autre appareil vient de publier), il
recommence avec l'état à jour. Il ne publie jamais par-dessus un état distant qu'il n'a pas pu lire.</p>

<h2>Fusion sans conflit</h2>

<ul>
  <li><strong>Fichiers</strong> : union des fichiers (identifiés par leur <code>uid</code> aléatoire) et des pierres
    tombales ; une pierre tombale l'emporte toujours. Deux appareils qui envoient en même temps ne perdent rien.</li>
  <li><strong>Trousseau</strong> : union des clés, des membres et des révocations (une révocation l'emporte) ; la clé
    courante est la plus récente. Le résultat ne dépend pas de l'ordre des fusions.</li>
  <li>Les envois en cours ou interrompus restent locaux : ils ne sont partagés qu'une fois complets.</li>
</ul>

<h2>Confiance</h2>

<p>Un trousseau reçu n'est fusionné que s'il a été <strong>scellé par un membre connu</strong> : le scellement
authentifie l'appareil qui l'a produit. La confiance s'étend de proche en proche : un appareil ajouté par un membre connu
devient connu à son tour. Un appareil révoqué ne peut donc ni se ré-ajouter, ni injecter une clé qu'il connaîtrait ; ses
blocs sont ignorés puis retirés à la publication suivante.</p>

<h3>Superbloc perdu</h3>

<p>Un webhook ne retrouve ses messages que dans son salon actuel : s'il est déplacé vers un autre salon (ou le message
supprimé), le superbloc devient introuvable. dscs en crée alors un nouveau, dans le salon actuel du webhook,
<strong>automatiquement</strong> si cet appareil est le seul à utiliser le coffre (les appareils de secours ne comptent pas),
et sur demande (<code>dscs sync --repair</code>) sinon, pour éviter que deux appareils en créent chacun un. La nouvelle
adresse est datée : la fusion des trousseaux retient toujours la plus récente. Les autres appareils la reçoivent par une
invitation (<code>dscs device join</code>) et ne l'acceptent que dans un trousseau scellé par un membre qu'ils connaissent.</p>

<p>Au moment de rejoindre un coffre, le nouvel appareil n'a encore confiance en personne : c'est la comparaison du code
de l'appareil qui l'a ajouté qui crée la première confiance (<?= $c->link('guide/appareils', null, 'ajouter-un-appareil') ?>).</p>

<h2>Ce que Discord pourrait tenter</h2>

<?= $c->table(['Tentative', 'Résultat'], [
    ['Lire le superbloc, les instantanés, les trousseaux', 'Rien d\'exploitable : tout est chiffré ou scellé'],
    ['Modifier un bloc', 'Détecté (chiffrement authentifié) : ignoré ou refusé'],
    ['Remettre un ancien superbloc (retour en arrière)', 'Ignoré : un appareil n\'accepte qu\'une séquence plus grande que celle qu\'il connaît'],
    ['Glisser un trousseau scellé par un appareil inconnu', 'Ignoré, avec un avertissement'],
    ['Supprimer les messages', 'Possible : DSCS ne protège pas la disponibilité. Garde d\'autres copies.'],
    ['Faire disparaître le superbloc', 'dscs en recrée un ; les fichiers restent lisibles tant que leurs messages existent'],
    ['Donner une fausse invitation à un appareil membre', 'Ignorée : seule compte une adresse scellée par un membre connu'],
]) ?>

<h2>Coût</h2>

<p>Une commande qui contacte Discord fait environ 3 requêtes pour recevoir, et une dizaine quand elle publie. C'est
négligeable pour de gros envois, sensible pour un tout petit fichier : voir la feuille de route
(<?= $c->link('dev/roadmap') ?>).</p>
