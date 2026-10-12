"""Scénario complet de la TUI pilotée au clavier, contre le faux serveur Discord."""
import pytest
from textual.widgets import Input, SelectionList

from conftest import file_messages
from dscs.tui import DownloadScreen, DSCSApp, RemoteTable, TransferScreen, UploadScreen, VimTree, WebhookPanel

pytestmark = pytest.mark.tui


async def test_keyboard_scenario(vault, discord, sample_tree, tmp_path):
    tui = DSCSApp(sample_tree, vault)

    async def wait_transfers(pilot, timeout=60):
        await pilot.pause(0.2)
        for _ in range(int(timeout * 10)):
            if not [w for w in tui.workers if w.group in ("transfer", "sync") and not w.is_finished]:
                break
            await pilot.pause(0.1)
        else:
            raise AssertionError(f"workers bloqués : {[(w.name, w.state) for w in tui.workers]}")
        await pilot.pause(0.2)

    async with tui.run_test(size=(110, 35)) as pilot:
        tree = tui.query_one(VimTree)
        await wait_transfers(pilot)  # synchronisation initiale (publication du coffre)
        assert vault.shared, "le coffre est publié dès l'ouverture"

        def labels():
            return [str(n.label) for n in tree.root.children]

        assert ".cache" not in labels(), "fichiers cachés masqués par défaut"
        await pilot.press("full_stop")
        await pilot.pause(0.3)
        assert ".cache" in labels(), "« . » affiche les fichiers cachés"
        await pilot.press("full_stop")
        await pilot.pause(0.3)

        # ordre : projet/, exact.bin, gros.bin, notes.md, vide.txt (dossiers d'abord)
        await pilot.press("j")
        assert tree.cursor_node.data.path.name == "projet", "j descend"
        await pilot.press("l")
        await pilot.pause(0.3)
        assert tree.cursor_node.is_expanded, "l déplie un dossier"
        await pilot.press("l")
        assert tree.cursor_node.data.path.parent.name == "projet", "l entre dans le dossier déplié"
        await pilot.press("h")
        assert tree.cursor_node.data.path.name == "projet", "h remonte au parent"
        await pilot.press("h")
        assert not tree.cursor_node.is_expanded, "h replie"
        await pilot.press("k")
        assert tree.cursor_node is tree.root, "k remonte"
        await pilot.press("G")
        assert tree.cursor_node.data.path.name == "vide.txt", "G va en bas"
        await pilot.press("g")

        # marquer projet/ et notes.md, puis u
        await pilot.press("j", "space")
        await pilot.press("j", "j", "space")
        assert {p.name for p in tree.marked} == {"projet", "notes.md"}, "espace marque"
        await pilot.press("u")
        await pilot.pause(0.2)
        assert isinstance(tui.screen, UploadScreen), "u ouvre la confirmation d'envoi"
        hooks = tui.screen.query_one(SelectionList)
        assert sorted(hooks.selected) == ["1", "2"], "tous les webhooks cochés par défaut"
        await pilot.press("n")
        await pilot.pause(0.1)
        assert type(tui.screen).__name__ == "Screen" and not file_messages(discord, vault.paths), "n annule"
        await pilot.press("u")
        await pilot.pause(0.1)
        await pilot.press("y")
        await wait_transfers(pilot)
        assert isinstance(tui.screen, TransferScreen) and tui.screen.finished, "y lance l'envoi"
        assert sorted(r.name for r in vault.files()) == ["README", "main.py", "notes.md"], "3 fichiers envoyés"
        assert not tree.marked, "marques effacées après envoi"
        await pilot.press("escape")
        await pilot.pause(0.1)

        # Entrée sur un fichier sans marque -> envoie ce fichier, ici sur le seul webhook 2
        await pilot.press("g", "j", "j", "j")
        assert tree.cursor_node.data.path.name == "gros.bin"
        await pilot.press("enter")
        await pilot.pause(0.2)
        assert isinstance(tui.screen, UploadScreen)
        await pilot.press("space")  # décoche le webhook 1 (surligné en premier)
        await pilot.press("y")
        await wait_transfers(pilot)
        await pilot.press("escape")
        assert len(vault.files()) == 4, "Entrée envoie le fichier sous le curseur"
        big = next(r for r in vault.files() if r.name == "gros.bin")
        assert {c.webhook_id for c in vault.index.chunks(big.id)} == {"2"}, "seul le webhook coché est utilisé"
        assert tui.upload_webhooks == {"2"}, "le choix est mémorisé pour l'envoi suivant"

        # onglet distant
        await pilot.press("tab")
        await pilot.pause(0.2)
        table = tui.query_one(RemoteTable)
        assert table.has_focus and table.row_count == 4, "Tab -> onglet Distant (4 lignes)"
        shown = {i.id: i for i in tui.query_one(WebhookPanel).infos}
        assert shown["1"].display_name == "Stockage 1" and shown["1"].channel == "421", "salon affiché"
        assert shown["2"].link == "https://discord.com/channels/4242/422", "lien vers le salon"
        # lignes triées par dossier puis nom : gros.bin, notes.md, projet/README, projet/src/main.py
        await pilot.press("j", "j")
        await pilot.press("l")
        await pilot.pause(0.2)
        assert isinstance(tui.screen, DownloadScreen), "l (ou Entrée) ouvre le choix de la destination"
        custom = tmp_path / "choisi" / "ici"
        tui.screen.query_one(Input).value = str(custom)
        await pilot.pause(0.1)
        await pilot.press("tab", "tab")  # Tab circule dans la fenêtre : arbre, puis case « dossier distant »
        await pilot.press("space")  # décoche : le fichier va directement dans le dossier choisi
        tui.screen.query_one(Input).focus()
        await pilot.press("enter")
        await wait_transfers(pilot)
        await pilot.press("escape")
        assert (custom / "README").read_text() == "readme", "téléchargé dans le dossier saisi, sans « projet/ »"
        assert tui.download_dir == custom, "dossier mémorisé pour le téléchargement suivant"
        await pilot.press("x")
        await pilot.pause(0.1)
        await pilot.press("y")
        await wait_transfers(pilot)
        assert table.row_count == 3, "x supprime de Discord"

        # sélection multiple : gros.bin, notes.md, projet/src/main.py -> marquer les deux derniers
        await pilot.press("g", "j", "space", "space")
        names = {vault.file(fid).name for fid in table.marked}
        assert names == {"notes.md", "main.py"}, "espace marque des fichiers distants"
        await pilot.press("enter")
        await pilot.pause(0.2)
        assert isinstance(tui.screen, DownloadScreen) and len(tui.screen._recs) == 2, "une fenêtre pour la sélection"
        several = tmp_path / "plusieurs"
        tui.screen.query_one(Input).value = str(several)
        await pilot.press("enter")
        await wait_transfers(pilot)
        assert isinstance(tui.screen, TransferScreen) and tui.screen.finished
        await pilot.press("escape")
        assert (several / "notes.md").exists() and (several / "projet" / "src" / "main.py").exists(), "2 fichiers"
        assert not table.marked, "marques effacées après le téléchargement"

        await pilot.press("g", "j", "space", "space", "x")
        await pilot.pause(0.2)
        assert type(tui.screen).__name__ == "ConfirmScreen", "une seule confirmation pour la sélection"
        await pilot.press("y")
        await wait_transfers(pilot)
        assert isinstance(tui.screen, TransferScreen) and tui.screen.finished, "progression de la suppression"
        await pilot.press("escape")
        await pilot.pause(0.1)
        assert table.row_count == 1 and [r.name for r in vault.files()] == ["gros.bin"], "2 fichiers supprimés"
        await pilot.press("tab")
        await pilot.pause(0.1)
        assert tree.has_focus, "Tab revient à l'arbre"
