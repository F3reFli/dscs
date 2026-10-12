"""Navigation h j k l dans toutes les fenêtres de la TUI (pas seulement les onglets)."""
import pytest
from textual.app import App
from textual.widgets import Button, Input, SelectionList

from dscs.models import StoredFile, WebhookInfo
from dscs.tui import DownloadScreen, TransferScreen, UploadScreen
from dscs.tui.screens import ConfirmScreen
from dscs.tui.screens.download import FolderTree

pytestmark = pytest.mark.tui

PENDING = object()


class Host(App):
    """Application minimale qui ouvre une fenêtre et garde ce qu'elle renvoie."""

    def __init__(self, screen) -> None:
        super().__init__()
        self.dialog = screen
        self.result = PENDING

    def on_mount(self) -> None:
        self.push_screen(self.dialog, lambda result: setattr(self, "result", result))


async def test_upload_webhooks_with_hjkl():
    infos = [WebhookInfo(id=i, name=n) for i, n in (("1", "A"), ("2", "B"), ("3", "C"))]
    host = Host(UploadScreen(["notes.md"], "1 fichier", infos))
    async with host.run_test(size=(100, 40)) as pilot:
        hooks = host.screen.query_one(SelectionList)
        assert hooks.has_focus
        await pilot.press("j", "space")
        assert sorted(hooks.selected) == ["1", "3"], "j descend, espace décoche"
        await pilot.press("G", "space", "g", "space")
        assert hooks.selected == [], "G / g : dernier / premier"
        await pilot.press("j", "k", "space")
        assert hooks.selected == ["1"], "k remonte"
        await pilot.press("l")
        assert host.screen.focused.id == "yes", "l passe aux boutons"
        await pilot.press("l")
        assert host.screen.focused.id == "no"
        await pilot.press("h")
        assert host.screen.focused.id == "yes", "h revient au bouton précédent"
        await pilot.press("enter")
        await pilot.pause()
    assert host.result == ["1"]


async def test_download_destination_with_hjkl(tmp_path):
    root = tmp_path / "racine"
    (root / "alpha" / "dedans").mkdir(parents=True)
    (root / "beta").mkdir()
    rec = StoredFile(1, "u", "notes.md", "", 10, None, 1, 0.0)
    host = Host(DownloadScreen([rec], tmp_path / "dl", tmp_path, tree_root=root))
    async with host.run_test(size=(100, 45)) as pilot:
        dest = host.screen.query_one("#dest", Input)
        tree = host.screen.query_one(FolderTree)
        assert dest.has_focus
        await pilot.press("end", "j")
        assert dest.value.endswith("dlj"), "dans le champ, les lettres s'écrivent"
        await pilot.press("backspace", "down")
        assert tree.has_focus, "↓ passe du champ à l'arbre"
        await pilot.pause(0.3)
        await pilot.press("j")
        assert dest.value == str(root / "alpha"), "la destination suit le curseur"
        await pilot.press("l")
        await pilot.pause(0.3)
        await pilot.press("l")
        assert dest.value == str(root / "alpha" / "dedans"), "l ouvre puis entre dans le dossier"
        await pilot.press("h")
        assert dest.value == str(root / "alpha"), "h remonte au parent"
        await pilot.press("h", "j")
        assert dest.value == str(root / "beta"), "h replie, j passe au dossier suivant"
        await pilot.press("enter")
        await pilot.pause()
    assert host.result.dest == root / "beta", "Entrée télécharge dans le dossier sous le curseur"


async def test_download_tree_climbs_above_its_root(tmp_path):
    root = tmp_path / "racine"
    root.mkdir()
    rec = StoredFile(1, "u", "notes.md", "", 10, None, 1, 0.0)
    host = Host(DownloadScreen([rec], tmp_path / "dl", tmp_path, tree_root=root))
    async with host.run_test(size=(100, 45)) as pilot:
        await pilot.press("down")
        await pilot.pause(0.3)
        await pilot.press("h")
        await pilot.pause(0.3)
        assert host.screen.query_one(FolderTree).path == tmp_path, "h sur la racine remonte d'un dossier"


async def test_confirm_buttons_with_hl():
    host = Host(ConfirmScreen("Supprimer ?", ["notes.md"], "1 fichier"))
    async with host.run_test() as pilot:
        assert host.screen.focused.id == "yes"
        await pilot.press("l")
        assert isinstance(host.screen.focused, Button) and host.screen.focused.id == "no"
        await pilot.press("enter")
        await pilot.pause()
    assert host.result is False


async def test_transfer_log_scrolls_with_jk():
    host = Host(TransferScreen("Envoi", 100))
    async with host.run_test() as pilot:
        for n in range(60):
            host.dialog.log_line(f"ligne {n}")
        await pilot.pause(0.2)
        log = host.dialog.query_one("#log")
        await pilot.press("g")
        assert log.scroll_y == 0
        await pilot.press("j", "j")
        assert log.scroll_y == 2, "j fait défiler le journal"
        await pilot.press("k")
        assert log.scroll_y == 1
        await pilot.press("G")
        assert log.scroll_y == log.max_scroll_y
