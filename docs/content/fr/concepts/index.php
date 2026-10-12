<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Index SQLite';
$page->description = 'La carte des fichiers : son schéma, ses garanties, ses migrations et son verrou.';
?>
<p>Un webhook peut relire un message s'il connaît son identifiant, mais il ne peut pas <em>lister</em> un salon. L'index
(<code>~/.local/share/dscs/index.db</code>) est donc la seule façon de savoir quels messages forment quel fichier.
C'est une base SQLite ordinaire, que tu peux inspecter avec <code>sqlite3</code>.</p>

<p>Chaque appareil a sa copie locale ; la partie partageable (fichiers complets, suppressions) est publiée chiffrée sur
Discord et fusionnée entre appareils : voir <?= $c->link('concepts/synchronisation') ?>. Perdre <code>index.db</code> ne fait
donc rien perdre : il se reconstruit à la synchronisation suivante.</p>

<h2>Schéma (version 4)</h2>

<h3><code>files</code> — un fichier envoyé</h3>
<?= $c->table(['Colonne', 'Type', 'Rôle'], [
    ['<code>id</code>', 'INTEGER', 'Identifiant affiché par <code>dscs ls</code> et utilisé par <code>get</code> / <code>rm</code>'],
    ['<code>uid</code>', 'TEXT unique', 'Identifiant aléatoire (128 bits) lié à chaque morceau par le chiffrement'],
    ['<code>name</code>', 'TEXT', 'Nom du fichier'],
    ['<code>folder</code>', 'TEXT', 'Dossier distant, <code>\'\'</code> pour la racine'],
    ['<code>size</code>', 'INTEGER', 'Taille en clair, en octets'],
    ['<code>sha256</code>', 'TEXT', 'Empreinte du fichier en clair, renseignée à la fin de l\'envoi'],
    ['<code>chunks</code>', 'INTEGER', 'Nombre de morceaux attendus'],
    ['<code>format</code>', 'INTEGER', 'Format des morceaux (voir ' . $c->link('concepts/chiffrement', null, 'evolution-du-format') . ')'],
    ['<code>created</code>', 'REAL', 'Date d\'envoi (horodatage Unix)'],
    ['<code>key_id</code>', 'TEXT', 'Empreinte de la génération de clé qui a chiffré le fichier'],
    ['<code>complete</code>', 'INTEGER', '<code>1</code> : fichier entier et visible ; <code>0</code> : envoi en cours, interrompu, ou suppression partielle'],
]) ?>

<h3><code>chunks</code> — un morceau = un message Discord</h3>
<?= $c->table(['Colonne', 'Rôle'], [
    ['<code>file_id</code>', 'Fichier propriétaire (supprimer le fichier supprime ses lignes de morceaux)'],
    ['<code>idx</code>', 'Position du morceau dans le fichier (0, 1, 2…)'],
    ['<code>webhook_id</code>', 'Webhook qui a posté le message (seul lui peut le relire et le supprimer)'],
    ['<code>message_id</code>', 'Identifiant du message Discord'],
    ['<code>size</code>', 'Taille chiffrée (morceau + 28 octets)'],
]) ?>

<h3><code>webhooks</code>, <code>tombstones</code> et <code>meta</code></h3>
<ul>
  <li><code>webhooks(id, channel_id, guild_id, name)</code> : les webhooks déjà utilisés, le salon et le serveur où ils
    postent, et leur nom (cache des informations données par Discord, pour l'onglet Distant et <code>dscs webhooks</code>
    hors ligne). <strong>Jamais de token</strong> : l'URL complète ne se trouve pas dans l'index.</li>
  <li><code>tombstones(uid, deleted)</code> : les fichiers supprimés, pour que la suppression atteigne les autres appareils
    et qu'une fusion ne les fasse jamais réapparaître.</li>
  <li><code>meta(key, value)</code> : état de la synchronisation (<code>sync_seq</code>, dernier instantané connu ;
    <code>keyring_pushed</code>, dernier trousseau publié).</li>
</ul>

<h2>Garanties</h2>

<ul>
  <li><strong>Un fichier n'apparaît qu'une fois complet.</strong> Il est créé avec <code>complete = 0</code> ; <code>ls</code>,
    l'onglet Distant et <code>get</code> l'ignorent tant que l'envoi n'est pas terminé.</li>
  <li><strong>Chaque morceau est inscrit dès qu'il est sur Discord</strong>, sans attendre la fin. Si le processus meurt,
    l'index sait exactement quels messages supprimer.</li>
  <li><strong>Une suppression partielle n'oublie rien</strong> : chaque morceau supprimé sort de l'index aussitôt ; s'il en
    reste, le fichier repasse à <code>complete = 0</code> et <code>dscs gc</code> reprendra.</li>
  <li><strong>Aucun secret</strong> : ni clé, ni token. L'index peut être sauvegardé sans précaution particulière (il révèle
    tout de même tes noms de fichiers).</li>
  <li><strong>Robuste aux crashs</strong> : mode WAL de SQLite, chaque écriture est durable immédiatement.</li>
</ul>

<h2>Migrations</h2>

<p>La version du schéma est stockée dans <code>PRAGMA user_version</code>. À l'ouverture, dscs applique dans l'ordre les
migrations manquantes, chacune dans sa propre transaction.</p>

<?= $c->table(['Situation', 'Comportement'], [
    ['Base neuve', 'Création du schéma, puis passage direct à la version 4'],
    ['Base de dscs 0.1 (sans version, avec une table <code>files</code>)', 'Conversion : l\'URL complète de chaque morceau est remplacée par l\'ID du webhook, puis <code>VACUUM</code> réécrit le fichier pour effacer physiquement les anciens tokens'],
    ['Version 1 → 2', 'Ajout de <code>files.key_id</code> : l\'empreinte de l\'ancienne clé unique est attribuée à chaque fichier'],
    ['Version 2 → 3', 'Ajout de la table <code>tombstones</code> (synchronisation)'],
    ['Version 3 → 4', 'Ajout de <code>webhooks.guild_id</code> et <code>webhooks.name</code> (serveur et salon dans l\'onglet Distant)'],
    ['Base à jour', 'Rien'],
    ['Base plus récente que dscs', 'Refus : <code>Index en version N, cette version de dscs ne connaît que la M : mets dscs à jour.</code>'],
]) ?>

<p>Comment ajouter une migration : <?= $c->link('dev/contribuer', null, 'ajouter-une-migration-d-index') ?>.</p>

<h2>Verrou entre processus</h2>

<p>Plusieurs dscs peuvent tourner en même temps (l'interface ouverte et un <code>dscs put</code> dans un autre terminal) :
SQLite gère les accès concurrents. Chaque instance tient un <strong>verrou partagé</strong> sur
<code>index.lock</code>, à côté de l'index.</p>

<p><code>dscs gc</code> demande en plus un <strong>verrou exclusif</strong>, sans attendre : il échoue si une autre instance est
ouverte. Sans cette précaution, il prendrait l'envoi en cours d'une autre instance (<code>complete = 0</code>) pour un envoi
interrompu, et supprimerait ses morceaux.</p>

<p>Le verrou utilise <code>flock</code>, disponible sous Linux et macOS. Sous Windows, il n'y a pas de verrou :
ne lance pas <code>dscs gc</code> pendant un envoi.</p>

<h2>Inspecter l'index</h2>

<?= $c->code('sql', <<<'SQL'
-- sqlite3 ~/.local/share/dscs/index.db
SELECT id, folder, name, size, chunks FROM files WHERE complete = 1;
SELECT idx, webhook_id, message_id FROM chunks WHERE file_id = 3 ORDER BY idx;
SELECT id, name FROM files WHERE complete = 0;   -- envois interrompus
SQL) ?>

<?= $c->callout('warning', '<p>Ne modifie pas l\'index à la main pendant que dscs tourne, et garde une copie avant toute modification.</p>') ?>
