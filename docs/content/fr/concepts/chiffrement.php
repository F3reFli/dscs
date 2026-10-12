<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Chiffrement';
$page->description = 'Les clés de l\'appareil et du coffre, le chiffrement des morceaux, le scellement des trousseaux, et les limites du modèle.';
?>
<p>Tout est chiffré sur ton appareil avant de partir. Discord ne reçoit que des blocs indistinguables de données
aléatoires, sous des noms aléatoires, et aucune clé n'y circule en clair.</p>

<h2>Trois niveaux de clés</h2>

<?= $c->code('text', <<<'TXT'
clé privée de l'appareil (X25519)         jamais transmise ; phrase de passe en option (scrypt)
   └── ouvre ─► trousseau scellé pour cet appareil
                  └── contient ─► clés de coffre (AES-256), génération 1, 2, 3…
                                    └── chiffrent ─► morceaux de fichiers et instantanés d'index
TXT) ?>

<?= $c->table(['Clé', 'Où', 'Rôle'], [
    ['Clé privée de l\'appareil', '<code>device.json</code> (chiffrée par la phrase de passe si définie)', 'Ouvrir le trousseau ; sceller le trousseau pour les autres membres'],
    ['Clés de coffre', 'Dans le trousseau, jamais en clair sur disque', 'Chiffrer les fichiers ; la génération courante sert aux nouveaux envois'],
    ['Trousseau', '<code>keyring.sealed</code> et, scellé pour chaque membre, sur Discord', 'Contenir les clés de coffre, les membres, les webhooks, l\'adresse du coffre'],
]) ?>

<h2>Les morceaux</h2>

<?= $c->table(['Élément', 'Valeur'], [
    ['Algorithme', 'AES-256-GCM (chiffrement authentifié), bibliothèque <code>cryptography</code>'],
    ['Clé', 'Génération courante du trousseau ; son empreinte est enregistrée avec le fichier'],
    ['Nonce', '12 octets aléatoires, nouveau pour chaque morceau'],
    ['Données associées (AAD)', '<code>"&lt;uid&gt;:&lt;idx&gt;"</code> : identifiant aléatoire du fichier et position du morceau'],
    ['Format d\'un morceau', '<code>nonce (12 o) ‖ données chiffrées ‖ tag (16 o)</code> → 28 octets de plus que le clair'],
    ['Intégrité du fichier', 'SHA-256 du fichier en clair, vérifié après reconstitution'],
]) ?>

<p>L'AAD est authentifiée : un morceau ne se déchiffre qu'à sa place, dans son fichier. Quelqu'un qui contrôle le salon ne
peut ni <strong>modifier</strong> un morceau, ni le <strong>déplacer</strong>, ni le <strong>remplacer</strong> par celui
d'un autre fichier : le téléchargement s'arrête (<code>Morceau corrompu ou mauvaise clé</code>) et rien n'est écrit. Une fois
tous les morceaux réunis dans un fichier <code>.part</code>, le SHA-256 est comparé à celui de l'envoi avant de donner au
fichier son vrai nom.</p>

<h2>Le scellement des trousseaux</h2>

<p>Pour remettre le trousseau à un appareil membre, dscs le <em>scelle</em> pour sa clé publique, dans l'esprit de HPKE
(mode authentifié) :</p>
<ul>
  <li>une clé éphémère X25519 est tirée ; la clé de chiffrement est dérivée (HKDF-SHA256) de deux échanges
    Diffie-Hellman : <em>éphémère × destinataire</em> et <em>expéditeur × destinataire</em> ;</li>
  <li>le trousseau est chiffré en AES-256-GCM, avec l'identifiant du coffre dans le contexte ;</li>
  <li>le destinataire seul peut l'ouvrir, et il apprend <strong>qui</strong> l'a scellé : seul le détenteur de la clé
    privée de l'expéditeur a pu le produire. dscs n'accepte que les trousseaux scellés par un membre connu
    (<?= $c->link('concepts/synchronisation', null, 'confiance') ?>).</li>
</ul>

<h2>Codes de vérification</h2>

<p>Chaque appareil a un code de 20 chiffres (environ 66 bits), dérivé de sa clé publique. Lors d'un appairage, chacun des
deux appareils affiche le code de l'autre : s'ils correspondent, aucune clé n'a été substituée en route. Fabriquer une clé
qui produirait le même code demanderait de l'ordre de 2<sup>66</sup> essais.</p>

<h2>Phrase de passe de l'appareil</h2>

<p>En option, la clé privée de l'appareil est chiffrée (AES-256-GCM) avec une clé dérivée de ta phrase de passe par
scrypt (environ 64 Mio de mémoire par essai), ce qui rend une attaque par force brute coûteuse. Sans phrase de passe, elle
n'est protégée que par les droits du fichier (<code>600</code>).</p>

<h2>Empreintes</h2>

<?= $c->table(['Empreinte', 'Calcul', 'Usage'], [
    ['Clé de coffre', '<code>sha256("dscs:key-fingerprint:v1:" ‖ clé)</code>, 16 caractères', 'Savoir quelle génération a chiffré chaque fichier'],
    ['Appareil', '<code>sha256("dscs:device-id:v1:" ‖ clé publique)</code>, 16 caractères', 'Identifier les membres'],
]) ?>

<p>Ces empreintes ne permettent pas de retrouver les clés (hachage à sens unique de valeurs aléatoires de 256 bits).</p>

<h2>Ce que voit Discord</h2>

<?= $c->table(['Visible', 'Caché'], [
    ['Le nombre de morceaux et leur taille (donc la taille approximative de chaque fichier)', 'Le contenu'],
    ['La date et l\'heure de chaque envoi', 'Le nom et l\'extension des fichiers, l\'arborescence'],
    ['Le nom que tu as donné aux webhooks', 'Quel morceau appartient à quel fichier (l\'index est chiffré)'],
    ['Les empreintes des appareils membres', 'Les clés, le trousseau, la liste des noms d\'appareils'],
]) ?>

<h2>Modèle de menace</h2>

<h3>Protégé</h3>
<ul>
  <li>Lecture du contenu, des noms et de l'index par Discord, par les membres du serveur, ou par quiconque récupère les
    pièces jointes.</li>
  <li>Altération, mélange ou retour en arrière des données sur Discord (détecté, jamais écrit sur ton disque).</li>
  <li>Interception d'une clé publique ou d'une invitation : elles ne permettent de rien lire ; une clé substituée est
    repérée par la comparaison des codes.</li>
  <li>Vol d'un appareil éteint protégé par une phrase de passe solide.</li>
</ul>

<h3>Non protégé</h3>
<ul>
  <li><strong>La disponibilité</strong> : Discord, un administrateur du serveur, ou tout détenteur d'une URL de webhook (donc
    tout appareil membre, même révoqué) peut supprimer les messages. Garde toujours une autre copie de tes fichiers.</li>
  <li><strong>Un appareil compromis</strong> pendant son utilisation : il a accès aux clés. Retire-le dès que possible.</li>
  <li><strong>Le passé après une révocation</strong> : un appareil retiré garde ce qu'il a pu lire (fichiers déjà envoyés,
    anciennes clés). Seuls les envois suivants lui échappent.</li>
  <li><strong>Les métadonnées</strong> listées plus haut (tailles, dates, rythme).</li>
</ul>

<h2>Évolution du format</h2>

<p>Le format des morceaux décrit ici est le <strong>format 1</strong> (<code>CHUNK_FORMAT = 1</code>), enregistré avec chaque
fichier. Un futur format (compression, regroupement des petits fichiers) incrémentera ce numéro : les anciens fichiers
resteront lisibles, et une version de dscs trop ancienne refusera un format qu'elle ne connaît pas.</p>

<p>Un nonce aléatoire de 96 bits par morceau reste sûr tant qu'une même clé chiffre moins de quelques milliards de
morceaux : <code>dscs key rotate</code> permet de changer de génération bien avant.</p>
