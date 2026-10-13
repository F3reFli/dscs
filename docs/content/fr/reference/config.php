<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'config.toml';
$page->description = 'Chaque clé du fichier de configuration : type, valeur par défaut, contraintes et erreurs.';
?>
<p>Emplacement : <code>~/.config/dscs/config.toml</code> (ou <code>$XDG_CONFIG_HOME/dscs/</code>, ou <code>$DSCS_HOME/</code>).
Format <a href="https://toml.io/fr/">TOML</a>. Les clés inconnues sont ignorées. Lecture et validation :
<code>src/dscs/config/settings.py</code>.</p>

<h2>Clés</h2>

<h3><code>webhooks</code></h3>
<?= $c->table(['', ''], [
    ['Type', 'liste de chaînes, ou une seule chaîne'],
    ['Obligatoire', 'oui, au moins une URL'],
    ['Format', '<code>https://discord.com/api/webhooks/&lt;ID&gt;/&lt;token&gt;</code> (aussi accepté : <code>discordapp.com</code>, un préfixe de version <code>/api/v10/</code>, une barre finale)'],
    ['Doublons', 'Deux entrées avec le même ID de webhook : seule la première est gardée'],
]) ?>
<p>Erreurs : <code>Aucun webhook dans …</code> ; <code>URL de webhook invalide : …</code> (token masqué).</p>

<h3><code>chunk_size_mb</code></h3>
<?= $c->table(['', ''], [
    ['Type', 'nombre (entier ou décimal), en Mio'],
    ['Défaut', '<code>9.0</code>'],
    ['Bornes', 'strictement supérieur à 0, au plus <code>499</code>'],
    ['Effet', 'Taille de chaque morceau avant chiffrement (+ 28 octets après). N\'affecte que les envois suivants.'],
]) ?>
<p>Erreurs : <code>chunk_size_mb doit être un nombre</code> ; <code>chunk_size_mb doit être entre 0 et 499 (actuellement X)</code>.
Avant d'augmenter : <?= $c->link('guide/configuration', null, 'taille-des-morceaux') ?>.</p>

<h3><code>download_dir</code></h3>
<?= $c->table(['', ''], [
    ['Type', 'chaîne (chemin)'],
    ['Défaut', '<code>"~/Downloads/dscs"</code>'],
    ['Effet', 'Destination des téléchargements de l\'interface et de <code>dscs get</code> sans <code>-o</code>. <code>~</code> est développé ; créé au besoin.'],
]) ?>

<h3><code>show_hidden</code></h3>
<?= $c->table(['', ''], [
    ['Type', 'booléen'],
    ['Défaut', '<code>false</code>'],
    ['Effet', 'Afficher les fichiers et dossiers commençant par <code>.</code> au lancement de l\'interface'],
]) ?>

<h3><code>[servers]</code> et <code>[channels]</code></h3>
<?= $c->table(['', ''], [
    ['Type', 'tables : <code>"identifiant" = "nom"</code>'],
    ['Défaut', 'vides'],
    ['Effet', 'Noms lisibles des serveurs et salons Discord, affichés dans l\'onglet Distant, l\'écran d\'envoi et <code>dscs webhooks</code>. Discord ne donne pas ces noms aux webhooks : sans eux, les identifiants sont affichés.'],
]) ?>
<p>Erreur : <code>[servers] doit être une table : "identifiant" = "nom"</code> (idem pour <code>[channels]</code>).</p>

<h2>Exemple complet</h2>

<?= $c->code('toml', <<<'TOML'
webhooks = [
  "[webhooks-link]",
  "[webhooks-link]",
  "[webhooks-link]",
]
chunk_size_mb = 9.0
download_dir = "~/Téléchargements/dscs"
show_hidden = true

[servers]
"[server-id]" = "[server-name]"

[channels]
"[channel-id]" = "[channel-name]"
"[channel-id]" = "[channel-name]"
TOML) ?>

<h2>Erreurs de lecture</h2>
<?= $c->table(['Message', 'Cause'], [
    ['<code>Aucune config trouvée. Lance d\'abord : dscs init</code>', 'Fichier absent'],
    ['<code>… : TOML invalide (…)</code>', 'Erreur de syntaxe TOML (le détail indique la ligne)'],
]) ?>

<h2>Droits du fichier</h2>
<p><code>dscs init</code> crée le fichier en <code>600</code> et rétablit ces droits s'il le réécrit. Si tu le crées toi-même,
pense à <code>chmod 600 ~/.config/dscs/config.toml</code> : il contient les tokens des webhooks.</p>
