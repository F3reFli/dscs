<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Discord et webhooks';
$page->description = 'Comment dscs parle à Discord : requêtes, limites de taille et de débit, liens qui expirent.';
?>
<?= $c->callout('danger', <<<'HTML'
<p>Utiliser Discord comme espace de stockage n'est pas un usage prévu par ses conditions d'utilisation. Le compte,
le serveur ou les messages peuvent disparaître sans préavis. DSCS est un stockage d'appoint chiffré, pas une sauvegarde.</p>
HTML) ?>

<h2>Pourquoi des webhooks</h2>

<p>Un webhook est une URL secrète liée à un salon : elle permet de poster, relire et supprimer <em>ses propres</em>
messages, sans bot, sans compte et sans connexion permanente. C'est exactement ce dont dscs a besoin, avec un minimum de
droits : un webhook ne peut ni lire les autres messages du salon, ni lister l'historique.</p>

<p>Conséquence directe : <strong>dscs doit retenir l'identifiant de chaque message</strong> (c'est le rôle de
l'<?= $c->link('concepts/index', 'index') ?>), et <strong>chaque morceau dépend du webhook qui l'a posté</strong>.</p>

<h2>Requêtes utilisées</h2>

<?= $c->table(['Opération', 'Requête', 'Détails'], [
    ['Vérifier un webhook', '<code>GET {webhook}</code>', 'Pendant <code>dscs init</code>, au lancement de l\'interface et par <code>dscs webhooks</code> : nom du webhook et identifiants de son serveur et de son salon (Discord ne donne pas leurs noms)'],
    ['Envoyer un morceau', '<code>POST {webhook}?wait=true</code>', 'Multipart : <code>payload_json</code> + <code>files[0]</code>. <code>wait=true</code> pour recevoir l\'ID du message'],
    ['Relire un morceau', '<code>GET {webhook}/messages/{id}</code>', 'Puis téléchargement de <code>attachments[0].url</code>'],
    ['Supprimer un morceau', '<code>DELETE {webhook}/messages/{id}</code>', 'Un <code>404</code> (déjà supprimé) est accepté lors des nettoyages'],
]) ?>

<p>Chaque message envoyé a un contenu vide, une seule pièce jointe nommée aléatoirement
(<code>3f9a1c0be47d2a85.bin</code>, 16 caractères hexadécimaux) et le drapeau <code>SUPPRESS_NOTIFICATIONS</code>
(<code>4096</code>) : le salon ne sonne pas.</p>

<h2>Limite de taille</h2>

<p>Discord refuse une pièce jointe au-delà de la limite du serveur : <strong>10 Mio</strong> sans boost. dscs découpe
donc en morceaux de <strong>9 Mio</strong> par défaut ; avec les 28 octets du chiffrement, chaque pièce jointe reste
sous la limite. Réglage : <code>chunk_size_mb</code> (<?= $c->link('guide/configuration', null, 'taille-des-morceaux') ?>).
Un morceau trop gros est refusé avec un <code>413</code> et l'envoi échoue proprement.</p>

<h2>Liens qui expirent</h2>

<p>Les liens de téléchargement des pièces jointes (CDN) sont signés et expirent au bout d'environ 24 heures. dscs ne les
stocke donc jamais : à chaque téléchargement, il <strong>relit le message</strong> pour obtenir un lien frais, puis
télécharge. Un fichier envoyé il y a un an se récupère comme un fichier envoyé il y a une minute.</p>

<h2>Limites de débit</h2>

<p>Discord limite le nombre de requêtes par webhook et par salon. dscs gère tout automatiquement :</p>

<?= $c->table(['Situation', 'Réaction'], [
    ['<code>429 Too Many Requests</code>', 'Attend la durée demandée (<code>retry_after</code> dans la réponse, ou en-tête <code>Retry-After</code>) puis réessaie'],
    ['En-tête <code>X-RateLimit-Remaining: 0</code>', 'Attend <code>X-RateLimit-Reset-After</code> avant la requête suivante, pour ne pas provoquer de 429'],
    ['Erreur serveur <code>5xx</code>', 'Réessaie après 1 s, 2 s, 4 s… jusqu\'à 30 s'],
    ['Coupure réseau, délai dépassé', 'Même attente progressive'],
    ['Autre erreur <code>4xx</code>', 'Échec immédiat avec le message de Discord (<code>Discord a répondu 403 : …</code>)'],
]) ?>

<p>Au bout de 8 tentatives infructueuses : <code>Échec après 8 tentatives : …</code>. La connexion s'établit en 30 s maximum,
et une lecture qui reste muette plus de 180 s est considérée comme coupée.</p>

<h2>Parallélisme</h2>

<p>Pendant un envoi, <strong>chaque webhook a son propre flux</strong> et prend le morceau suivant dès qu'il est libre : avec
trois webhooks, trois morceaux partent en même temps. Les morceaux de tous les fichiers de l'envoi passent par la même file :
ceux d'un gros fichier se répartissent entre les webhooks (et leurs salons), et plusieurs petits fichiers d'un seul morceau
partent en même temps sur des webhooks différents, sans pause entre deux fichiers. <code>dscs bench</code> mesure le débit
obtenu (<?= $c->link('reference/cli', null, 'bench') ?>).</p>

<p>Au téléchargement, trois morceaux sont préchargés en avance pendant que le précédent est déchiffré et écrit.</p>

<h2>Conseils d'organisation</h2>

<ul>
  <li>Un <strong>serveur privé</strong> dédié, sans autres membres.</li>
  <li><strong>Un salon par webhook</strong>, réservé au stockage : personne n'y écrit, rien ne s'y perd.</li>
  <li>Ne supprime jamais un webhook ni un salon qui a servi, et ne déplace pas un webhook vers un autre salon : il ne
    retrouve ses messages que dans son salon actuel. Pour un nouveau salon, crée un nouveau webhook.</li>
  <li>Garde les URLs des webhooks aussi secrètes que des mots de passe : elles permettent de supprimer tes morceaux.</li>
</ul>
