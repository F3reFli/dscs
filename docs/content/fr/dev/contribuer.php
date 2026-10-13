<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Mise en place';
$page->description = 'Préparer un environnement de développement, comprendre le dépôt et ajouter une fonctionnalité au bon endroit.';
?>
<h2>Environnement</h2>

<?= $c->code('bash', <<<'SH'
make install      # crée .venv et installe dscs en mode éditable + pytest, pytest-asyncio, ruff
make test         # toute la suite (≈ 15 s)
make lint         # ruff
make media        # captures et GIF du README dans media/ (faux Discord ; pip install -e '.[media]', Firefox)
.venv/bin/dscs --help
SH) ?>

<p>En mode éditable, une modification de <code>src/dscs/</code> est prise en compte immédiatement par <code>.venv/bin/dscs</code>.
Pour essayer sans toucher à ta vraie configuration, utilise un coffre séparé :</p>

<?= $c->code('bash', <<<'SH'
export DSCS_HOME=~/dscs-dev        # config, clé et index isolés
.venv/bin/dscs init                # avec un webhook de test, dans un salon de test
SH) ?>

<h2>Le dépôt</h2>

<?= $c->code('text', <<<'TXT'
Dscs/
├── src/dscs/            le package (voir « Architecture »)
├── tests/               unit/, integration/, tui/, fakes/ (faux serveur Discord)
├── docs/                ce site (PHP + SCSS)
├── architecture/        schémas fonctionnels (Mermaid) de toute la pipeline
├── media/               captures et GIF du README (générés par make media)
├── scripts/             make_media.py
├── ARCHITECTURE.md      référence technique
├── pyproject.toml       dépendances, commande dscs, pytest, ruff
└── Makefile             install, test, test-fast, lint, fmt, docs, docs-css, media, clean
TXT) ?>

<h2>Conventions</h2>

<ul>
  <li><strong>Français</strong> dans le code : commentaires, messages affichés. La documentation existe en français et en anglais.</li>
  <li><strong>Couches</strong> : respecte les règles de <?= $c->link('concepts/architecture', null, 'regles-de-dependance') ?>. Une interface
    ne parle qu'au Vault.</li>
  <li><strong>Pas de global calculé à l'import</strong> : passe les chemins par <code>Paths</code>.</li>
  <li><strong>Secrets</strong> : un token de webhook ne sort jamais de <code>config.toml</code> ; dans un message, utilise
    <code>WebhookRef.redacted</code>.</li>
  <li><strong>Erreurs</strong> : toute erreur prévue est une <code>DSCSError</code> (ou sous-classe) avec un message qui dit quoi faire.</li>
  <li><strong>Style</strong> : ruff, 120 colonnes, annotations de type, <code>from __future__ import annotations</code>.</li>
  <li><strong>Tests</strong> : toute fonctionnalité ou correction arrive avec son test (<?= $c->link('dev/tests') ?>).</li>
  <li><strong>Doc</strong> : une règle qui change met à jour <code>ARCHITECTURE.md</code> et la page concernée ici, dans les deux langues.</li>
</ul>

<h2>Ajouter une commande</h2>

<?= $c->steps([
    '<p>Si l\'opération est nouvelle, ajoute une méthode au <code>Vault</code> (<code>services/vault.py</code>), qui délègue à <code>transfer/</code> ou à une brique.</p>',
    '<p>Déclare la sous-commande dans <code>cli/parser.py</code> (<code>sub.add_parser</code> + <code>set_defaults(func=…)</code>) et ajoute son nom à <code>COMMANDS</code>.</p>',
    '<p>Écris <code>cmd_&lt;nom&gt;(args) -&gt; int</code> dans <code>cli/commands.py</code> : ouvre le Vault avec <code>with Vault.open()</code>, et <code>asyncio.run</code> pour la partie réseau.</p>',
    '<p>Teste le parsing dans <code>tests/unit/test_cli.py</code> et le comportement dans <code>tests/integration/</code>.</p>',
    '<p>Documente-la dans ' . $c->link('guide/cli') . ' et ' . $c->link('reference/cli') . '.</p>',
]) ?>

<h2>Ajouter un écran ou une touche à l'interface</h2>

<ul>
  <li>Un écran modal va dans <code>tui/screens/</code>, un composant dans <code>tui/widgets/</code>, exporté par le
    <code>__init__.py</code> du dossier.</li>
  <li>Les styles vont dans <code>tui/app.tcss</code> (la couleur d'accent est <code>$dscs-accent</code>, à garder synchronisée avec
    <code>tui/theme.py</code>).</li>
  <li>Une action réseau se lance dans un <code>@work(group="transfer")</code> et passe par le Vault.</li>
  <li>Ajoute la touche au scénario <code>tests/tui/test_app.py</code> et au tableau de <?= $c->link('guide/interface') ?>.</li>
</ul>

<h2>Ajouter une migration d'index</h2>

<?= $c->steps([
    '<p>Dans <code>index/migrations.py</code>, écris <code>_to_vN(conn)</code> qui fait passer le schéma de N-1 à N (sans <code>COMMIT</code> : la transaction est gérée autour).</p>',
    '<p>Ajoute-la à <code>MIGRATIONS[N - 1]</code> et incrémente <code>SCHEMA_VERSION</code>.</p>',
    '<p>Adapte <code>index/repository.py</code> (seul endroit qui écrit du SQL).</p>',
    '<p>Ajoute un test qui part d\'une base N-1 <em>remplie</em> et vérifie les données après migration (modèle : <code>test_legacy_index_is_migrated_without_tokens</code>).</p>',
    '<p>Mets à jour le schéma dans <code>ARCHITECTURE.md</code> et ' . $c->link('concepts/index') . '.</p>',
]) ?>

<h2>Changer le format des morceaux</h2>

<p>Incrémente <code>CHUNK_FORMAT</code> (<code>crypto/cipher.py</code>), garde le code de lecture des formats précédents, et
choisis le traitement selon <code>StoredFile.format</code> au téléchargement. Un fichier déjà envoyé ne doit jamais devenir
illisible.</p>
