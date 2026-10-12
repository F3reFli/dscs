<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Configuration';
$page->description = 'Le fichier config.toml, les webhooks multiples, la taille des morceaux et l\'emplacement des fichiers.';
?>
<h2>Le fichier <code>config.toml</code></h2>

<p>Créé par <code>dscs init</code> dans <code>~/.config/dscs/config.toml</code>, avec des droits <code>600</code>
(lisible par toi seul : il contient les tokens des webhooks). Tu peux l'éditer à la main ; il est relu à chaque
lancement.</p>

<?= $c->code('toml', <<<'TOML'
# DSCS — configuration
# Plusieurs webhooks (un par salon) = envois en parallèle. Ceux du coffre sont aussi gardés avec ses clés :
# un appareil qui rejoint le coffre les reçoit sans rien écrire ici.
# Ne supprime jamais dans Discord un webhook déjà utilisé : ses morceaux deviendraient inaccessibles.
webhooks = [
  "https://discord.com/api/webhooks/111111111111111111/AbCd...",
  "https://discord.com/api/webhooks/222222222222222222/EfGh...",
]

# Taille des morceaux. Max 10 Mio sans Nitro (on garde de la marge).
chunk_size_mb = 9.0

download_dir = "~/Downloads/dscs"
show_hidden = false

# Discord ne donne pas aux webhooks le nom de leur serveur ni de leur salon.
# Donne-leur un nom lisible ici (identifiants affichés par « dscs webhooks ») :
[servers]
# "118000000000000000" = "Perso"

[channels]
# "119000000000000000" = "stockage-1"
TOML, '~/.config/dscs/config.toml') ?>

<p>Chaque clé est détaillée dans <?= $c->link('reference/config') ?>. Une valeur invalide empêche dscs de démarrer,
avec un message qui dit laquelle.</p>

<h2>Webhooks</h2>

<h3>En ajouter</h3>

<p>Ajoute simplement l'URL à la liste <code>webhooks</code>. Les nouveaux envois se répartiront sur tous les webhooks :
chacun envoie ses morceaux en parallèle des autres. Un webhook par salon donne le meilleur débit, car Discord limite
le rythme par webhook et par salon.</p>

<p>Une même URL présente deux fois (ou deux URLs du même webhook) n'est comptée qu'une fois.</p>

<h3>Ne jamais supprimer ni déplacer un webhook qui a servi</h3>

<?= $c->callout('danger', <<<'HTML'
<p>Un webhook ne peut relire et supprimer <strong>que ses propres messages, dans son salon actuel</strong>. Supprimer
dans Discord un webhook (ou son salon) qui a déjà envoyé des morceaux rend ces morceaux définitivement inaccessibles par
webhook. Le <strong>déplacer vers un autre salon</strong> les rend inaccessibles tant qu'il n'est pas remis dans l'ancien,
et fait perdre le point d'entrée du coffre (que dscs recrée). Pour changer de salon, crée plutôt un nouveau webhook.</p>
HTML) ?>

<p>Retirer une URL de <code>config.toml</code>, en revanche, ne fait rien perdre : les webhooks du coffre sont mémorisés
avec ses clés, sur chaque appareil. Pour ne plus utiliser un webhook pour les nouveaux envois, décoche-le dans l'écran
d'envoi, ou désigne les webhooks voulus avec <code>dscs put -w</code>.</p>

<h3>Choisir les webhooks d'un envoi</h3>

<p>Par défaut, un envoi utilise tous les webhooks du coffre. Pour en choisir certains (un salon réservé aux photos, par
exemple) : coche-les dans l'écran d'envoi de l'interface (<?= $c->link('guide/interface', null, 'ecran-d-envoi-choisir-les-webhooks') ?>),
ou utilise <code>dscs put -w</code> (<?= $c->link('guide/cli', null, 'envoyer-dscs-put') ?>).</p>

<h2>Noms des serveurs et des salons</h2>

<p>Discord ne communique pas aux webhooks le <em>nom</em> de leur serveur ni de leur salon : seulement leurs
identifiants, et le nom du webhook lui-même. Pour afficher des noms lisibles dans l'onglet Distant, l'écran d'envoi et
<code>dscs webhooks</code>, associe un nom à chaque identifiant dans <code>config.toml</code> :</p>

<?= $c->code('bash', 'dscs webhooks      # affiche le nom, le serveur et le salon de chaque webhook, avec leurs identifiants') ?>

<?= $c->code('toml', <<<'TOML'
[servers]
"118000000000000000" = "Perso"

[channels]
"119000000000000000" = "stockage-1"
"119000000000000001" = "photos"
TOML, 'config.toml') ?>

<p>Ces noms ne servent qu'à l'affichage, sur cet appareil. Un salon s'affiche avec un <code>#</code> devant son nom.
Sans nom, c'est l'identifiant qui s'affiche, avec un lien qui ouvre le salon dans Discord
(<code>https://discord.com/channels/&lt;serveur&gt;/&lt;salon&gt;</code>).</p>

<h2>Taille des morceaux</h2>

<p><code>chunk_size_mb</code> (9 par défaut) fixe la taille de chaque morceau avant chiffrement. Le chiffrement ajoute
28 octets ; Discord refuse les pièces jointes au-delà de sa limite (10 Mio pour un serveur sans boost).</p>

<?= $c->callout('warning', <<<'HTML'
<p>La limite qui s'applique aux webhooks est celle du <strong>serveur</strong> (elle dépend de son niveau de boost),
pas forcément celle de ton abonnement Nitro. Avant d'augmenter <code>chunk_size_mb</code>, vérifie la limite réelle
de ton serveur. Si un morceau est trop gros, Discord répond <code>413</code> : l'envoi échoue proprement et ses
morceaux déjà envoyés sont supprimés.</p>
HTML) ?>

<p>Changer la taille n'affecte que les envois suivants : chaque fichier garde ses morceaux d'origine, et les anciens
restent téléchargeables. Valeurs acceptées : de 0 (exclu) à 499.</p>

<h2>Téléchargements et affichage</h2>

<ul>
  <li><code>download_dir</code> : dossier de destination proposé par défaut pour les téléchargements (modifiable à chaque
    téléchargement dans l'interface ; <code>dscs get</code> sans <code>-o</code>). <code>~</code> est remplacé par ton dossier
    personnel. Les sous-dossiers distants y sont recréés, sauf si tu choisis le contraire.</li>
  <li><code>show_hidden</code> : afficher les fichiers cachés au lancement de l'interface (la touche <?= $c->kbd('.') ?>
    les bascule à tout moment).</li>
</ul>

<h2>Emplacement des fichiers</h2>

<?= $c->table(['Situation', 'Configuration, identité, trousseau', 'Index'], [
    ['Par défaut', $c->path('~/.config/dscs/'), $c->path('~/.local/share/dscs/')],
    ['<code>XDG_CONFIG_HOME</code> / <code>XDG_DATA_HOME</code> définies', $c->path('$XDG_CONFIG_HOME/dscs/'), $c->path('$XDG_DATA_HOME/dscs/')],
    ['<code>DSCS_HOME=/chemin</code>', $c->path('/chemin/'), $c->path('/chemin/')],
]) ?>

<p><code>DSCS_HOME</code> est pratique pour avoir plusieurs « coffres » indépendants (chacun avec son identité, son
trousseau et son index), ou pour tester sans toucher à ta configuration :</p>

<?= $c->code('bash', <<<'SH'
DSCS_HOME=~/coffre-perso dscs init
DSCS_HOME=~/coffre-perso dscs ~/Documents
SH) ?>

<h2>Phrase de passe dans les scripts</h2>

<p>Si cet appareil est protégé par une phrase de passe, dscs la demande à chaque lancement. Pour un script ou une tâche
planifiée, la variable <code>DSCS_PASSPHRASE</code> la fournit. Elle est visible des autres programmes de ta session :
réserve-la à une machine que tu es seul à utiliser.</p>

<h2>Réseau et proxy</h2>

<p>dscs respecte les variables d'environnement de proxy habituelles (<code>HTTPS_PROXY</code>, <code>HTTP_PROXY</code>,
<code>NO_PROXY</code>).</p>
