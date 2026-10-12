<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'Tests';
$page->description = 'Organisation de la suite pytest, le faux serveur Discord, les fixtures, et comment écrire un test.';
?>
<p>La suite tourne entièrement en local, sans jamais contacter Discord : un faux serveur reproduit son comportement,
y compris ses défauts. Chaque test a ses propres dossiers temporaires.</p>

<h2>Lancer</h2>

<?= $c->code('bash', <<<'SH'
make test                                   # tout
make test-fast                              # sans le scénario de l'interface
.venv/bin/pytest tests/unit                 # un dossier
.venv/bin/pytest -k gc                      # par mot-clé
.venv/bin/pytest tests/integration/test_transfers.py::test_roundtrip -x -vv
SH) ?>

<h2>Organisation</h2>

<?= $c->table(['Dossier', 'Ce qui est testé'], [
    ['<code>tests/unit/</code>', 'Utilitaires, crypto (aller-retour, altération, AAD), scellement, identité d\'appareil et phrase de passe, trousseau, KeyStore (migration de l\'ancienne clé), config, index (schéma, migrations), verrou, CLI hors ligne'],
    ['<code>tests/integration/</code>', 'Bout en bout contre le faux serveur : transferts, altération, annulation, gc, rotation, clé absente, webhooks choisis, destination ; plusieurs appareils (appairage, partage, révocation, concurrence, trousseau forgé, retour en arrière, secours) ; webhooks déplacés et réparation du superbloc ; appairage et réparation par la CLI'],
    ['<code>tests/tui/</code>', 'Scénario complet piloté au clavier avec <code>App.run_test()</code> de Textual : envoi avec choix des webhooks, sélection multiple, destination (marqueur <code>tui</code>)'],
    ['<code>tests/fakes/</code>', 'Le faux serveur Discord'],
]) ?>

<h2>Le faux serveur Discord</h2>

<p><code>tests/fakes/fake_discord.py</code> est une petite application aiohttp qui imite l'API des webhooks, en plus méchant :</p>

<?= $c->table(['Comportement simulé', 'Ce que ça vérifie'], [
    ['Une requête d\'envoi ou de relecture sur 7 reçoit un <code>429</code>', 'La gestion des limites de débit'],
    ['Pièce jointe &gt; 10 Mio refusée (<code>413</code>)', 'Que les morceaux respectent la limite'],
    ['Le lien renvoyé à l\'envoi est <strong>déjà expiré</strong>', 'Que dscs relit toujours le message pour un lien frais'],
    ['Messages et pièces jointes stockés en mémoire', 'Que le contenu est chiffré, les noms aléatoires, les notifications coupées'],
    ['<code>control["deny_deletes"]</code> : suppressions refusées (<code>403</code>)', 'La reprise d\'un nettoyage raté par <code>dscs gc</code>'],
    ['<code>PATCH</code> d\'un message (remplacement de la pièce jointe)', 'La publication du superbloc'],
    ['Infos de webhook avec serveur et salon', 'L\'onglet Distant et <code>dscs webhooks</code>'],
    ['<code>control["channels"][id] = "…"</code> : webhook déplacé vers un autre salon (ses anciens messages lui deviennent invisibles, <code>404</code>)', 'La réparation du superbloc et l\'avertissement de changement de salon'],
]) ?>

<p>Il expose aussi des compteurs (<code>stats</code> : envois, 429, liens expirés utilisés, relectures…) que les tests vérifient.</p>

<h2>Fixtures (<code>tests/conftest.py</code>)</h2>

<?= $c->table(['Fixture', 'Fournit'], [
    ['<code>discord</code>', 'Le faux serveur démarré sur un port libre, avec deux URLs de webhook ; accès à <code>store</code>, <code>stats</code>, <code>control</code>'],
    ['<code>paths</code>', 'Un <code>Paths</code> dans le dossier temporaire du test'],
    ['<code>vault</code>', 'Un Vault initialisé (config + clé) sur le faux serveur, téléchargements dans un dossier temporaire'],
    ['<code>sample_tree</code>', 'Une arborescence de test : fichier vide, texte, 25 Mo (3 morceaux), 9 Mio pile (1 morceau), sous-dossiers, fichier caché'],
]) ?>

<p><code>asyncio_mode = "auto"</code> : une fonction de test <code>async def</code> s'exécute sans décorateur.</p>

<h2>Écrire un test</h2>

<?= $c->code('python', <<<'PY'
async def test_empty_file_roundtrip(vault, discord, tmp_path):
    src = tmp_path / "vide.txt"
    src.write_bytes(b"")
    async with vault.connected():
        [item] = vault.plan_upload([src]).items
        rec = await vault.upload(item)
        assert rec.chunks == 1
        out = await vault.download(rec, tmp_path / "out")
    assert out.read_bytes() == b""
    assert len(discord.store) == 1
PY, 'tests/integration/test_transfers.py') ?>

<ul>
  <li>Passe toujours par le Vault quand c'est possible : c'est l'API que les interfaces utilisent.</li>
  <li>Vérifie les effets côté « Discord » via <code>discord.store</code> et côté index via <code>vault.index</code>.</li>
  <li>Pour un nouveau comportement de Discord, ajoute-le au faux serveur plutôt que de mocker le client HTTP.</li>
</ul>
