<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Appareils et partage';
$page->description = 'Utiliser le même coffre sur plusieurs appareils, ou le partager avec une personne de confiance, sans jamais transmettre de clé.';
?>
<p>Un coffre DSCS peut être utilisé depuis plusieurs appareils : ton PC, ton portable, ou l'appareil d'une personne de
confiance. Tous voient les mêmes fichiers, et tout reste chiffré de bout en bout : <strong>aucune clé ne circule en
clair</strong>, ni sur Discord, ni entre les appareils.</p>

<h2>Le principe</h2>

<ul>
  <li><strong>Chaque appareil a sa propre clé</strong> (une paire de clés X25519). La clé privée ne quitte jamais
    l'appareil ; elle peut être protégée par une phrase de passe.</li>
  <li><strong>Le coffre a ses clés de coffre</strong>, qui chiffrent les fichiers. Elles sont rangées dans un
    <em>trousseau</em>, lui-même <em>scellé</em> pour chaque appareil membre : seul cet appareil peut l'ouvrir.</li>
  <li><strong>Ajouter un appareil</strong> ne transmet que des clés publiques (non secrètes). Un code de vérification,
    comparé sur les deux écrans, garantit que personne n'a glissé sa propre clé à la place.</li>
  <li><strong>L'index est synchronisé</strong> via Discord, chiffré : un fichier envoyé depuis un appareil apparaît sur
    les autres. Voir <?= $c->link('concepts/synchronisation') ?>.</li>
</ul>

<?= $c->callout('warning', '<p>Un appareil membre peut tout lire, et il connaît les webhooks (il peut donc aussi supprimer des messages). Ne partage un coffre qu\'avec tes propres appareils ou des personnes en qui tu as entièrement confiance.</p>') ?>

<h2>Cet appareil</h2>

<?= $c->code('bash', <<<'SH'
dscs device                 # nom, code de vérification, clé publique, protection
dscs device passphrase      # ajouter ou changer la phrase de passe
dscs device passphrase --remove
SH) ?>

<p>Avec une phrase de passe, dscs la demande à chaque lancement (interface comprise). Pour un script, la variable
<code>DSCS_PASSPHRASE</code> la fournit, à utiliser avec prudence (elle est visible des autres programmes de ta session).</p>

<h2>Ajouter un appareil</h2>

<p>Trois étapes, sur deux appareils. Garde les deux écrans sous les yeux (ou au téléphone avec l'autre personne).</p>

<?= $c->steps([
    '<p><strong>Sur le nouvel appareil</strong> (dscs installé) : crée son identité.</p>' . $c->code('bash', 'dscs device init') . '<p>Il affiche sa <strong>clé publique</strong> (<code>dscs-pub-…</code>) et son <strong>code</strong> (20 chiffres). Transmets la clé publique à l\'appareil membre, par n\'importe quel moyen : elle n\'est pas secrète.</p>',
    '<p><strong>Sur un appareil déjà membre</strong> : ajoute-le.</p>' . $c->code('bash', 'dscs device add dscs-pub-… --name portable') . '<p>dscs affiche le code de la clé reçue : <strong>vérifie qu\'il est identique</strong> à celui du nouvel appareil avant de confirmer. Il donne ensuite une <strong>invitation</strong> (<code>dscs-inv1-…</code>) et son propre code.</p>',
    '<p><strong>Sur le nouvel appareil</strong> : rejoins le coffre avec l\'invitation.</p>' . $c->code('bash', 'dscs device join dscs-inv1-…') . '<p>dscs affiche le nom et le code de l\'appareil qui l\'a ajouté : <strong>vérifie qu\'il est identique</strong>, puis confirme. Le trousseau, les webhooks et la liste des fichiers arrivent ; rien n\'est à configurer.</p>',
]) ?>

<?= $c->callout('note', <<<'HTML'
<p>L'invitation ne contient aucune clé : sans être membre, on ne peut rien lire avec. Elle contient en revanche
l'URL d'un webhook, qui permet d'écrire et de supprimer des messages : transmets-la par un canal privé.</p>
HTML) ?>

<p>Pour les scripts, <code>--code</code> remplace la confirmation interactive dans les deux sens :
<code>dscs device add CLE --code 1234-…</code>, <code>dscs device join INVITATION --code 5678-…</code>. Un code différent
bloque l'opération.</p>

<h2>Voir les membres</h2>

<?= $c->code('text', <<<'TXT'
$ dscs device list
Coffre 3209f41d9111ac84 · 2 appareil(s) membre(s)

pc-fixe  (cet appareil)
  Code   : 3381-7309-6931-3796-0561
  Ajouté : 12/10/2026 13:41
portable
  Code   : 5194-9876-1085-3989-0479
  Ajouté : 12/10/2026 13:41
TXT) ?>

<h2>Synchroniser</h2>

<p>La synchronisation est automatique : chaque commande qui contacte Discord récupère d'abord ce que les autres appareils
ont publié, et publie à la fin ce qu'elle a changé. L'interface fait de même à l'ouverture et après chaque transfert.
Pour forcer un échange complet :</p>

<?= $c->code('bash', 'dscs sync') ?>

<h2>Si le coffre change d'adresse</h2>

<p>Les appareils se retrouvent grâce au <strong>superbloc</strong>, un message posté par un webhook. Si ce webhook est déplacé
vers un autre salon dans Discord (ou le message supprimé), le superbloc devient introuvable
(détails : <?= $c->link('guide/depannage') ?>).
Le plus simple est de remettre le webhook dans son ancien salon. Sinon :</p>

<?= $c->code('bash', <<<'SH'
# sur UN appareil membre (automatique s'il est le seul appareil du coffre)
dscs sync --repair        # recrée le superbloc dans le salon actuel du webhook
dscs device invite        # affiche l'invitation, avec la nouvelle adresse

# sur chacun des autres appareils
dscs device join <invitation>
SH) ?>

<p>Un appareil déjà membre n'a pas de code à comparer : il n'accepte que des trousseaux scellés par des membres qu'il
connaît déjà, et retient l'adresse qu'ils contiennent. Une invitation falsifiée ne change donc rien. Pense ensuite à créer un
nouveau kit de secours : l'ancien pointe vers l'ancienne adresse.</p>

<h2>Retirer un appareil</h2>

<p>Appareil perdu, volé, ou personne qui ne doit plus avoir accès :</p>

<?= $c->code('bash', <<<'SH'
dscs device revoke portable          # nom, code ou identifiant (voir dscs device list)
SH) ?>

<p>L'appareil est retiré du trousseau et le coffre passe à une <strong>nouvelle génération de clé</strong>, qu'il ne
recevra jamais : il ne pourra rien lire de ce qui sera envoyé ensuite. Un appareil ne peut pas se retirer lui-même : fais-le
depuis un autre membre.</p>

<?= $c->callout('warning', <<<'HTML'
<p>Une révocation ne peut pas reprendre ce que l'appareil a déjà pu lire : les fichiers envoyés avant (et leurs
clés), et les URLs des webhooks. Il peut donc encore supprimer des messages. Si c'est un risque, crée de nouveaux salons
et webhooks pour la suite.</p>
HTML) ?>

<h2>Changer de clé</h2>

<?= $c->code('bash', <<<'SH'
dscs key            # générations de clés, et fichiers chiffrés avec chacune
dscs key rotate     # nouvelle génération pour les prochains envois, publiée pour tous les membres
SH) ?>

<p>Les anciennes clés restent dans le trousseau : les fichiers déjà envoyés restent lisibles.</p>

<h2>Kit de secours</h2>

<p>Si tu perds <em>tous</em> tes appareils, plus personne ne peut ouvrir le trousseau. Le kit de secours évite ça : c'est
la clé privée d'un appareil « de secours », membre du coffre, plus l'adresse du coffre.</p>

<?= $c->code('bash', <<<'SH'
dscs key recovery -o kit-dscs.txt     # crée le kit (fichier lisible par toi seul) ; sans -o, il est affiché
SH) ?>

<?= $c->callout('danger', '<p>Le kit donne accès à <strong>tout</strong> le coffre. Imprime-le et range-le dans un endroit sûr, ou garde-le sur une clé USB chiffrée. Jamais sur Discord, dans un mail ou dans un nuage.</p>') ?>

<p>Pour récupérer le coffre sur une nouvelle machine :</p>

<?= $c->code('bash', <<<'SH'
dscs device recover            # colle le kit (saisie masquée), reconnais la liste des membres
SH) ?>

<p>La nouvelle machine devient membre ; les autres appareils l'acceptent à leur prochaine synchronisation. Si le kit a pu
être vu, crée-en un nouveau et retire l'ancien appareil de secours (<code>dscs device list</code>, puis
<code>dscs device revoke "secours …"</code>).</p>

<h2>Venir de dscs 0.2</h2>

<p>Au premier lancement, l'ancienne clé unique (<code>~/.config/dscs/key</code>) devient la première génération du
trousseau de cet appareil, puis le fichier en clair est effacé. Tes fichiers restent lisibles. Ajoute ensuite une phrase de
passe (<code>dscs device passphrase</code>) et crée un kit de secours.</p>
