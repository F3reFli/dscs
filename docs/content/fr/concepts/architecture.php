<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Architecture';
$page->description = 'Comment le code de dscs est découpé en couches, et les règles qui les relient.';
?>
<p>Le package <code>src/dscs/</code> est organisé en couches : chaque couche ne dépend que de celles en dessous.
L'interface (texte ou ligne de commande) ne fait qu'afficher et saisir ; toute la logique passe par la couche
<code>services</code>, et d'abord par un objet unique, le <strong>Vault</strong>. Le document de référence est <code>ARCHITECTURE.md</code> à la racine du dépôt.</p>

<h2>Les couches</h2>

<figure class="diagram">
  <svg viewBox="0 0 720 470" role="img" aria-labelledby="arch-title">
    <title id="arch-title">Couches du package dscs, des interfaces vers les fondations</title>
    <defs>
      <marker id="arch-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
        <path class="diagram__arrowhead" d="M0 0L10 5L0 10z"/>
      </marker>
    </defs>

    <text class="diagram__tier" x="20" y="49">Interfaces</text>
    <text class="diagram__tier" x="20" y="145">Application</text>
    <text class="diagram__tier" x="20" y="241">Orchestration</text>
    <text class="diagram__tier" x="20" y="337">Briques</text>
    <text class="diagram__tier" x="20" y="429">Fondations</text>

    <rect class="diagram__box" x="150" y="16" width="200" height="56" rx="8"/>
    <text class="diagram__label" x="250" y="40" text-anchor="middle">cli/</text>
    <text class="diagram__sub" x="250" y="58" text-anchor="middle">argparse, commandes</text>
    <rect class="diagram__box" x="390" y="16" width="200" height="56" rx="8"/>
    <text class="diagram__label" x="490" y="40" text-anchor="middle">tui/</text>
    <text class="diagram__sub" x="490" y="58" text-anchor="middle">Textual : onglets, écrans</text>

    <rect class="diagram__box diagram__box--accent" x="150" y="112" width="440" height="56" rx="8"/>
    <text class="diagram__label" x="370" y="136" text-anchor="middle">services/ — Vault</text>
    <text class="diagram__sub" x="370" y="154" text-anchor="middle">la seule API des interfaces · cycle de vie</text>

    <rect class="diagram__box" x="150" y="208" width="210" height="56" rx="8"/>
    <text class="diagram__label" x="255" y="232" text-anchor="middle">transfer/</text>
    <text class="diagram__sub" x="255" y="250" text-anchor="middle">envoi · téléchargement · purge</text>
    <rect class="diagram__box" x="380" y="208" width="210" height="56" rx="8"/>
    <text class="diagram__label" x="485" y="232" text-anchor="middle">sync/</text>
    <text class="diagram__sub" x="485" y="250" text-anchor="middle">pull · push · appairage</text>

    <rect class="diagram__box" x="150" y="304" width="133" height="56" rx="8"/>
    <text class="diagram__label" x="216.5" y="328" text-anchor="middle">crypto/</text>
    <text class="diagram__sub" x="216.5" y="346" text-anchor="middle">AES-256-GCM</text>
    <rect class="diagram__box" x="303.5" y="304" width="133" height="56" rx="8"/>
    <text class="diagram__label" x="370" y="328" text-anchor="middle">index/</text>
    <text class="diagram__sub" x="370" y="346" text-anchor="middle">SQLite, verrou</text>
    <rect class="diagram__box" x="457" y="304" width="133" height="56" rx="8"/>
    <text class="diagram__label" x="523.5" y="328" text-anchor="middle">discord/</text>
    <text class="diagram__sub" x="523.5" y="346" text-anchor="middle">HTTP, webhooks</text>

    <rect class="diagram__box" x="150" y="400" width="440" height="52" rx="8"/>
    <text class="diagram__label" x="370" y="422" text-anchor="middle">config/ · models · errors · utils/</text>
    <text class="diagram__sub" x="370" y="440" text-anchor="middle">aucune dépendance vers le haut</text>

    <path class="diagram__line" d="M250 72V110" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M490 72V110" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M255 168V206" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M485 168V206" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M216.5 264V302" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M300 264L355 302" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M440 264L385 302" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M523.5 264V302" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M216.5 360V398" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M370 360V398" marker-end="url(#arch-arrow)"/>
    <path class="diagram__line" d="M523.5 360V398" marker-end="url(#arch-arrow)"/>
  </svg>
  <figcaption>Une flèche signifie « importe ». Rien ne remonte : une brique ne connaît ni le Vault ni l'interface.</figcaption>
</figure>

<h2>Règles de dépendance</h2>

<?= $c->table(['Module', 'Peut importer', 'Ne doit jamais importer'], [
    ['<code>errors</code>, <code>models</code>, <code>utils</code>', 'Rien d\'interne (sauf <code>models</code> et <code>utils</code> → <code>errors</code>)', '—'],
    ['<code>config</code>', '<code>errors</code>, <code>models</code>', '<code>crypto</code>, <code>index</code>, <code>discord</code>…'],
    ['<code>crypto</code>, <code>index</code>, <code>discord</code>', '<code>config</code>, <code>errors</code>, <code>models</code>, <code>utils</code>', 'Les unes les autres, <code>transfer</code>, <code>services</code>'],
    ['<code>transfer</code>, <code>sync</code>', 'Les briques et les fondations', '<code>services</code>, <code>cli</code>, <code>tui</code>, et pas l\'un l\'autre'],
    ['<code>services</code>', 'Tout ce qui est en dessous', '<code>cli</code>, <code>tui</code>'],
    ['<code>cli</code>, <code>tui</code>', '<code>services</code>, <code>models</code>, <code>errors</code>, <code>utils</code>, <code>config.Paths</code>', '<code>transfer</code>, <code>index</code>, <code>crypto</code>, <code>discord</code> directement'],
]) ?>

<p>Seule exception assumée : <code>cli/commands.py</code> utilise <code>discord.is_official_url</code> pour avertir, pendant
<code>dscs init</code>, d'une URL qui n'est pas sur un domaine Discord.</p>

<p>Pourquoi ces règles : chaque brique se teste seule (la crypto sans réseau, l'index sans Discord), et une nouvelle
interface (une GUI, un service web local…) n'aurait qu'à parler au Vault.</p>

<h2>Rôle de chaque module</h2>

<h3>Fondations</h3>
<?= $c->table(['Module', 'Contenu'], [
    ['<code>errors.py</code>', '<code>DSCSError</code> et ses sous-classes. Toute <code>DSCSError</code> est une erreur prévue, affichée telle quelle (' . $c->link('reference/erreurs') . ').'],
    ['<code>models.py</code>', '<code>WebhookRef</code> (ID + URL secrète), <code>StoredFile</code>, <code>Chunk</code>, <code>UploadItem</code>, <code>UploadPlan</code>, <code>chunk_count()</code>.'],
    ['<code>config/paths.py</code>', '<code>Paths</code> : emplacements des fichiers, résolus <strong>à l\'exécution</strong> (jamais à l\'import).'],
    ['<code>config/settings.py</code>', 'Lecture validée de <code>config.toml</code> (<code>Settings</code>) et écriture du modèle initial en <code>600</code>.'],
    ['<code>utils/</code>', '<code>human()</code> (tailles lisibles), <code>expand()</code> (sélection → fichiers), <code>unique_path()</code>, <code>safe_join()</code>.'],
]) ?>

<h3>Briques</h3>
<?= $c->table(['Module', 'Contenu'], [
    ['<code>crypto/keys.py</code>', 'Clés de coffre : génération, empreinte, lecture de l\'ancienne clé unique de dscs 0.2.'],
    ['<code>crypto/cipher.py</code>', 'Chiffrement AES-256-GCM des morceaux, AAD, <code>CHUNK_FORMAT</code> (' . $c->link('concepts/chiffrement') . ').'],
    ['<code>crypto/sealing.py</code>', 'Scellement authentifié X25519 + HKDF + AES-GCM, pour remettre un trousseau à un appareil.'],
    ['<code>crypto/device.py</code>', 'Identité d\'appareil : paire de clés, nom, code de vérification, phrase de passe (scrypt).'],
    ['<code>crypto/keyring.py</code>', 'Trousseau : générations de clés, membres, révocations, webhooks, adresse du coffre ; fusion.'],
    ['<code>index/db.py</code>', 'Connexion SQLite (WAL, écritures immédiatement durables), <code>transaction()</code>.'],
    ['<code>index/migrations.py</code>', 'Schéma versionné, migration depuis dscs 0.1 (' . $c->link('concepts/index') . ').'],
    ['<code>index/repository.py</code>', '<code>Index</code> : le seul code qui écrit du SQL (hors migrations).'],
    ['<code>index/lock.py</code>', '<code>ProcessLock</code> : verrou partagé / exclusif entre processus dscs.'],
    ['<code>discord/http.py</code>', 'Session HTTP, <code>request()</code> avec gestion des 429, 5xx et coupures (' . $c->link('concepts/discord') . ').'],
    ['<code>discord/webhook.py</code>', '<code>WebhookClient</code> : envoyer, relire, supprimer ses messages.'],
    ['<code>discord/pool.py</code>', '<code>WebhookPool</code> : les webhooks de la config, retrouvables par ID.'],
]) ?>

<h3>Orchestration et application</h3>
<?= $c->table(['Module', 'Contenu'], [
    ['<code>transfer/plan.py</code>', 'Sélection → liste de fichiers avec dossier distant et taille.'],
    ['<code>transfer/upload.py</code>', 'Envoi d\'un fichier : un producteur, un worker par webhook (' . $c->link('concepts/transferts') . ').'],
    ['<code>transfer/download.py</code>', 'Téléchargement avec préchargement, déchiffrement, contrôle SHA-256.'],
    ['<code>transfer/purge.py</code>', 'Suppression tolérante aux échecs (<code>purge_file</code>) et suppression demandée (<code>delete_file</code>).'],
    ['<code>sync/</code>', 'Superbloc, instantané d\'index chiffré, moteur de synchronisation (pull / push, confiance), invitations et kits (' . $c->link('concepts/synchronisation') . ').'],
    ['<code>services/vault.py</code>', '<code>Vault</code> : assemble config, trousseau, index, verrou, session HTTP et synchronisation ; ajout et retrait d\'appareils.'],
    ['<code>services/keystore.py</code>', '<code>KeyStore</code> : identité de l\'appareil et trousseau local, phrase de passe, migration de l\'ancienne clé.'],
    ['<code>services/bench.py</code>', 'Mesure du débit (<code>dscs bench</code>) : données aléatoires, supprimées ensuite, hors du coffre.'],
    ['<code>services/devices.py</code>', 'Rejoindre un coffre : par invitation (<code>join_vault</code>) ou par kit de secours (<code>recover_vault</code>).'],
    ['<code>services/setup.py</code>', '<code>initialize()</code> et <code>probe_webhook()</code>, utilisés par <code>dscs init</code>.'],
]) ?>

<h3>Interfaces</h3>
<?= $c->table(['Module', 'Contenu'], [
    ['<code>cli/parser.py</code>', 'Sous-commandes argparse.'],
    ['<code>cli/commands.py</code>', 'Une fonction <code>cmd_*</code> par commande, qui renvoie le code de sortie.'],
    ['<code>cli/progress.py</code>', 'Barre de progression texte (désactivée hors terminal).'],
    ['<code>cli/prompts.py</code>', 'Saisies : confirmations, phrases de passe, textes secrets (saisie masquée).'],
    ['<code>tui/app.py</code>, <code>app.tcss</code>', 'Application Textual et ses styles.'],
    ['<code>tui/widgets/</code>', '<code>VimTree</code> (onglet Local), <code>RemoteTable</code> (onglet Distant, sélection multiple), <code>WebhookPanel</code> (serveur et salon).'],
    ['<code>tui/screens/</code>', '<code>UploadScreen</code> (choix des webhooks), <code>DownloadScreen</code> (destination), <code>ConfirmScreen</code>, <code>TransferScreen</code>.'],
]) ?>

<h2>Le Vault, point d'entrée unique</h2>

<?= $c->code('python', <<<'PY'
with Vault.open() as vault:              # config + clé + index + verrou partagé, sans réseau
    vault.files()                        # lecture locale
    plan = vault.plan_upload([Path("photos")])
    async with vault.connected():        # session HTTP + webhooks
        rec = await vault.upload(plan.items[0], on_progress=print)
        await vault.download(rec)
        await vault.delete(rec)
PY) ?>

<p>Le cycle de vie est en deux temps :</p>
<ul>
  <li><strong><code>Vault.open()</code></strong> lit la config, ouvre le trousseau (avec la phrase de passe si l'appareil
    est protégé), prend un verrou partagé et ouvre (et migre si besoin) l'index. Aucune connexion réseau : <code>dscs ls</code>
    marche hors ligne.</li>
  <li><strong><code>vault.connected()</code></strong> ouvre la session HTTP, reçoit ce que les autres appareils ont publié,
    et publie en sortie les modifications locales. L'interface l'ouvre au montage et la ferme en quittant.</li>
</ul>

<p>Le détail des méthodes est dans <?= $c->link('reference/api') ?>.</p>

<h2>Principes</h2>

<ul>
  <li><strong>Aucun état global calculé à l'import</strong> : les chemins passent par <code>Paths</code>, ce qui rend chaque test
    indépendant (chacun a ses propres dossiers temporaires).</li>
  <li><strong>Les secrets restent confinés</strong> : aucune clé en clair sur disque ni sur Discord (trousseau scellé) ; les
    tokens des webhooks ne sont que dans le trousseau et <code>config.toml</code>, jamais dans l'index, et les messages
    d'erreur les masquent (<code>***</code>).</li>
  <li><strong>L'index est toujours cohérent</strong> : chaque morceau y est inscrit dès qu'il est sur Discord, un fichier
    n'est visible qu'une fois complet, et une suppression qui échoue laisse une trace que <code>dscs gc</code> reprend.</li>
  <li><strong>Une erreur prévue est une <code>DSCSError</code></strong> avec un message lisible ; tout autre type d'exception
    est un bug.</li>
  <li><strong>Évolutif sans casse</strong> : schéma d'index versionné, format des morceaux versionné par fichier.</li>
</ul>
