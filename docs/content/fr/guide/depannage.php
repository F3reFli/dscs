<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Dépannage';
$page->description = 'Les messages d\'erreur les plus fréquents, ce qu\'ils veulent dire et comment s\'en sortir.';
?>
<p>Tous les messages ci-dessous sont affichés tels quels par la ligne de commande (précédés de <code>Erreur :</code>)
ou par l'interface (dans le journal du transfert ou une notification). La liste exhaustive, classée par type, est dans
<?= $c->link('reference/erreurs') ?>.</p>

<h2>Au démarrage</h2>

<h3><code>Aucune config trouvée. Lance d'abord : dscs init</code></h3>
<p>Pas de <code>config.toml</code> à l'emplacement attendu. Lance <code>dscs init</code>, ou vérifie <code>DSCS_HOME</code> /
<code>XDG_CONFIG_HOME</code> si tu les utilises (<?= $c->link('guide/configuration', null, 'emplacement-des-fichiers') ?>).</p>

<h3><code>Phrase de passe incorrecte.</code></h3>
<p>La phrase de passe de cet appareil ne correspond pas. Elle ne peut pas être récupérée : si tu l'as oubliée, voir
<?= $c->link('guide/sauvegarde', null, 'phrase-de-passe-oubliee') ?>.</p>

<h3><code>Cet appareil est protégé par une phrase de passe : lance dscs dans un terminal, ou définis DSCS_PASSPHRASE.</code></h3>
<p>dscs a besoin de la phrase de passe mais ne peut pas la demander (script, tâche planifiée). Lance-le dans un terminal,
ou fournis-la par la variable <code>DSCS_PASSPHRASE</code>.</p>

<h3><code>Clé invalide dans …</code></h3>
<p>L'ancienne clé unique de dscs 0.2 (<code>key</code>), en cours de transfert dans le trousseau, ne contient pas 64
caractères hexadécimaux. Compare-la avec ta copie de sauvegarde.</p>

<h3><code>URL de webhook invalide : …</code></h3>
<p>Une entrée de <code>webhooks</code> n'a pas la forme <code>https://discord.com/api/webhooks/&lt;ID&gt;/&lt;token&gt;</code>.
Le token est masqué (<code>***</code>) dans le message.</p>

<h3><code>chunk_size_mb doit être…</code> / <code>TOML invalide</code></h3>
<p>Erreur dans <code>config.toml</code> : le message indique laquelle. Valeurs acceptées : <?= $c->link('reference/config') ?>.</p>

<h3><code>Index en version N, cette version de dscs ne connaît que la M : mets dscs à jour.</code></h3>
<p>L'index a été ouvert par une version plus récente de dscs, qui a fait évoluer son format. Installe la même version
(ou plus récente) que celle utilisée en dernier.</p>

<h3><code>Index illisible (…) : …</code></h3>
<p>Le fichier <code>index.db</code> est corrompu ou n'est pas une base SQLite. Restaure une sauvegarde.</p>

<h2>Clés, appareils et synchronisation</h2>

<h3><code>Aucune clé de coffre sur cet appareil. Crée un coffre avec « dscs init », ou rejoins un coffre existant avec « dscs device join ».</code></h3>
<p>Cet appareil n'a pas de trousseau : il peut lister et supprimer, mais ni envoyer ni télécharger. S'il s'agit de ton
coffre existant, ne lance pas <code>dscs init</code> (il en créerait un nouveau) : rejoins-le
(<?= $c->link('guide/appareils', null, 'ajouter-un-appareil') ?>) ou utilise ton kit de secours.</p>

<h3><code>… a été chiffré avec une clé absente du trousseau de cet appareil (empreinte …).</code></h3>
<p>Ce fichier a été chiffré avec une génération de clé que cet appareil n'a pas (marqué ✗ dans <code>dscs ls</code> et
l'onglet Distant). Lance <code>dscs sync</code> : si la clé vient d'être créée ailleurs, elle arrivera. Si l'appareil a été
retiré du coffre, il ne la recevra jamais.</p>

<h3><code>Cet appareil n'a plus accès à ce coffre : il en a été retiré depuis un autre appareil.</code></h3>
<p>Un autre membre a révoqué cet appareil (<code>dscs device revoke</code>). Il ne reçoit plus rien. Pour le réintégrer, il
faut lui créer une nouvelle identité (supprime <code>device.json</code> et <code>keyring.sealed</code>, puis
<code>dscs device init</code>) et l'ajouter de nouveau.</p>

<h3><code>Ce coffre n'a pas encore ajouté cet appareil…</code></h3>
<p>L'invitation est correcte, mais aucun membre n'a encore ajouté la clé publique de cet appareil. Sur un appareil membre :
<code>dscs device add &lt;clé publique&gt;</code> (la clé est affichée par <code>dscs device show</code>), puis réessaie.</p>

<h3><code>Aucun appareil confirmé : le coffre n'a pas été rejoint.</code> / <code>Le code donné ne correspond pas à cette clé publique</code></h3>
<p>Les codes de vérification ne correspondent pas. Ne force rien : recopie la clé publique ou l'invitation directement depuis
l'autre écran. Un code différent peut signifier qu'une clé a été remplacée en route.</p>

<h3><code>L'index du coffre est chiffré avec une clé que cet appareil n'a pas encore reçue…</code></h3>
<p>Un autre appareil vient de changer de génération de clé et son trousseau n'est pas encore arrivé (ou il a été scellé par
un appareil que celui-ci ne connaît pas encore). Réessaie après une synchronisation de l'appareil qui a fait le changement.</p>

<h3><code>Point d'entrée du coffre (superbloc) introuvable sur Discord…</code> / <code>Discord a répondu 404 : {"message": "Unknown Message", "code": 10008}</code></h3>
<p>Le <strong>superbloc</strong>, le message qui sert de point d'entrée au coffre (<?= $c->link('concepts/synchronisation') ?>),
n'est plus joignable. Cause la plus fréquente : son webhook a été <strong>déplacé vers un autre salon</strong> dans les
paramètres de Discord. Un webhook ne retrouve ses messages que dans son salon actuel : ceux restés dans l'ancien salon (le
superbloc, et les morceaux des fichiers envoyés avant le déplacement) lui deviennent invisibles. Autre cause : le message a
été supprimé à la main.</p>
<?= $c->table(['Situation', 'Que faire'], [
    ['L\'ancien salon existe encore', 'Remets le webhook dans ce salon (paramètres Discord) : tout redevient accessible, superbloc et anciens fichiers.'],
    ['Un seul appareil utilise le coffre', 'Rien : la prochaine commande (par exemple <code>dscs sync</code>) recrée le superbloc dans le salon actuel et l\'affiche dans un avertissement.'],
    ['Plusieurs appareils', 'Sur <strong>un seul</strong> : <code>dscs sync --repair</code>, puis <code>dscs device invite</code>. Sur chacun des autres : <code>dscs device join &lt;invitation&gt;</code>.'],
]) ?>
<p>Les fichiers envoyés avant le déplacement restent dans l'ancien salon : ils ne redeviennent lisibles que si le webhook y
est remis. Après une réparation, crée un nouveau kit de secours (<code>dscs key recovery</code>) : l'ancien pointe vers
l'ancienne adresse. Pour l'éviter : <?= $c->link('guide/configuration', 'ne déplace pas un webhook qui a servi', 'ne-jamais-supprimer-ni-deplacer-un-webhook-qui-a-servi') ?>.</p>

<h3><code>Cette invitation pointe vers une adresse du coffre qui n'existe plus…</code> / <code>Ce kit de secours pointe vers une adresse du coffre qui n'existe plus…</code></h3>
<p>Le superbloc a été recréé ailleurs depuis (ou son webhook déplacé). Demande une invitation à jour
(<code>dscs device invite</code> sur un appareil membre), ou utilise un kit créé après la réparation.</p>

<h3><code>Aucun trousseau d'un appareil de confiance à cette adresse : invitation périmée ou falsifiée.</code></h3>
<p>Un appareil déjà membre a reçu une invitation dont le superbloc ne contient rien de scellé par un membre qu'il connaît :
invitation d'un autre coffre recréé, ou tentative de tromperie. Rien n'a été modifié ; demande l'invitation à un appareil
membre.</p>

<h3><code>Synchronisation impossible : le coffre est modifié en continu ailleurs. Réessaie.</code></h3>
<p>Plusieurs appareils publient en même temps. Réessaie dans quelques secondes : aucune donnée n'est perdue.</p>

<h3><code>Ce kit de secours n'est plus valide…</code></h3>
<p>L'appareil de secours de ce kit a été retiré du coffre, ou le kit appartient à un autre coffre. Utilise un kit plus récent,
ou un appareil membre.</p>

<h3><code>Invitation invalide…</code> / <code>Clé publique d'appareil invalide…</code></h3>
<p>Le texte collé est incomplet ou abîmé. Une invitation commence par <code>dscs-inv1-</code>, une clé publique par
<code>dscs-pub-</code>, un kit par <code>dscs-kit1-</code> : recopie-les en entier.</p>

<h2>Pendant un transfert</h2>

<h3><code>Le webhook N est inconnu (ni dans le trousseau du coffre, ni dans config.toml)</code></h3>
<p>Le fichier a des morceaux envoyés par un webhook que cet appareil ne connaît pas. Les webhooks du coffre sont
mémorisés avec ses clés : les retirer de <code>config.toml</code> ne les fait pas oublier. Ce message signifie donc que
l'URL n'a jamais été donnée sur cet appareil (index venu d'ailleurs, par exemple). Remets-la dans la liste
<code>webhooks</code> de <code>config.toml</code> : rien n'a été modifié, tout redevient accessible.</p>

<h3><code>Webhook inconnu : « … ». Webhooks du coffre : …</code></h3>
<p>Le nom ou l'identifiant donné à <code>dscs put -w</code> ne correspond à aucun webhook. La liste affichée donne les noms
et identifiants valides (aussi visibles avec <code>dscs webhooks</code>).</p>

<h3><code>La destination n'est pas un dossier : …</code></h3>
<p>Le chemin choisi pour un téléchargement existe et désigne un fichier. Choisis un dossier (il est créé s'il n'existe
pas).</p>

<h3><code>Discord a répondu 401 / 403 / 404 : …</code></h3>
<?= $c->table(['Code', 'Cause probable'], [
    ['<code>401</code>', 'Token de webhook invalide (URL mal copiée).'],
    ['<code>403</code>', 'Permissions insuffisantes dans le salon.'],
    ['<code>404</code>', 'Webhook supprimé dans Discord, <strong>webhook déplacé vers un autre salon</strong> (ses messages restés dans l\'ancien lui deviennent invisibles), ou message supprimé à la main. Pour une suppression, un message déjà absent n\'est pas une erreur.'],
    ['<code>413</code>', 'Morceau trop gros pour la limite du serveur : baisse <code>chunk_size_mb</code>.'],
]) ?>

<h3><code>Échec après 8 tentatives : …</code></h3>
<p>Discord est resté injoignable (réseau coupé, erreurs 5xx répétées) malgré les nouvelles tentatives espacées de
1 à 30 secondes. Vérifie ta connexion et réessaie ; les morceaux déjà envoyés du fichier ont été retirés (ou le seront par
<code>dscs gc</code>).</p>

<h3><code>… a été modifié pendant l'envoi : recommence</code></h3>
<p>Le fichier a changé de taille pendant qu'il était lu. Attends qu'il ne soit plus en cours d'écriture, puis renvoie-le.</p>

<h3><code>Morceau corrompu ou mauvaise clé (échec du déchiffrement)</code></h3>
<p>Un morceau téléchargé ne passe pas le contrôle d'authenticité : il a été modifié sur Discord, ou n'appartient pas à ce
fichier. Rien n'est écrit sur le disque.</p>

<h3><code>Hash différent pour … : fichier corrompu</code> / <code>Index incomplet pour … (a/b morceaux)</code></h3>
<p>Le fichier reconstitué ne correspond pas à l'original, ou l'index ne connaît pas tous ses morceaux. Le fichier
partiel est supprimé. Restaurer un index plus récent peut aider dans le second cas.</p>

<h3><code>Le message N n'a plus de pièce jointe</code></h3>
<p>Le message existe encore mais sa pièce jointe a disparu (retirée à la main dans Discord). Ce fichier n'est plus récupérable.</p>

<h3><code>Suppression partielle de … : relance « dscs gc » pour terminer.</code></h3>
<p>Une partie des messages n'a pas pu être supprimée. Le fichier n'apparaît plus dans la liste ; <code>dscs gc</code> retirera
le reste dès que Discord répondra.</p>

<h3><code>Chemin refusé (sort du dossier de destination) : …</code></h3>
<p>Le dossier distant enregistré dans l'index tenterait d'écrire hors du dossier de téléchargement. Ça ne peut pas arriver avec
un index produit par dscs : celui-ci a probablement été modifié.</p>

<h2>Comprendre ce qui se passe : le journal</h2>

<p>Ajoute <code>-v</code> à n'importe quelle commande pour voir les étapes, ou <code>-vv</code> pour chaque requête vers
Discord et chaque morceau :</p>

<?= $c->code('bash', <<<'SH'
dscs -v put [folder]            # étapes : synchronisation, fichiers, freinages de Discord
dscs -vv get 12                 # en plus : chaque requête HTTP et chaque morceau
dscs -vv --log dscs.log sync    # dans un fichier, pour le garder ou le joindre à un rapport
dscs -v                         # l'interface : journal dans ~/.local/share/dscs/dscs.log
SH) ?>

<p>Le journal ne contient aucun secret (tokens masqués par <code>***</code>, aucune clé) mais contient les noms de tes
fichiers : relis-le avant de le partager.</p>

<h2>Autres situations</h2>

<h3>Les envois sont lents</h3>
<p>Discord limite le nombre de messages par webhook et par salon ; dscs attend automatiquement quand il le demande.
Ajoute des webhooks dans d'autres salons pour paralléliser (<?= $c->link('guide/configuration', null, 'webhooks') ?>) : les
morceaux d'un fichier de plus de 9 Mo se répartissent entre eux. Mesure le gain avec <code>dscs bench</code>
(<?= $c->link('reference/cli', null, 'bench') ?>) : si le total ne monte plus en ajoutant des salons, c'est ta connexion
qui sature.</p>

<h3><code>Une autre instance de dscs est ouverte : ferme-la avant de lancer cette opération.</code></h3>
<p><code>dscs gc</code> a besoin d'être seul. Ferme l'interface ou attends la fin de l'autre commande.</p>

<h3>« N envoi(s) interrompu(s) »</h3>
<p>Un envoi a été interrompu avant d'avoir pu nettoyer ses morceaux (crash, coupure). Lance <code>dscs gc</code>.</p>

<h3>Un fichier téléchargé s'appelle <code>nom (1).ext</code></h3>
<p>Normal : un fichier du même nom existait déjà dans la destination, et dscs n'écrase jamais rien.</p>
