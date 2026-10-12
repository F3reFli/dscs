"""Téléchargement d'un ou plusieurs fichiers : choix du dossier de destination (saisi, ou choisi dans un arbre)."""
from __future__ import annotations

from collections.abc import Iterable
from dataclasses import dataclass
from pathlib import Path

from textual.app import ComposeResult
from textual.binding import Binding
from textual.containers import Horizontal, Vertical
from textual.message import Message
from textual.widgets import Button, Checkbox, DirectoryTree, Input, Label, Static

from ...models import StoredFile
from ...utils import human, tilde
from ..widgets import VimDirectoryTree
from .base import VimModal


@dataclass(frozen=True)
class DownloadChoice:
    dest: Path  # dossier de destination
    keep_folder: bool  # recréer le dossier distant (photos/…) sous la destination


class FolderTree(VimDirectoryTree):
    """Arbre des dossiers seulement (fichiers et dossiers cachés exclus) : h j k l pour choisir, Entrée pour
    télécharger dans le dossier sous le curseur ; h sur la racine remonte au dossier parent."""

    BINDINGS = [Binding("enter", "choose", "Télécharger ici", show=False)]

    class Chosen(Message):
        def __init__(self, path: Path) -> None:
            super().__init__()
            self.path = path

    def filter_paths(self, paths: Iterable[Path]) -> Iterable[Path]:
        return [p for p in paths if p.is_dir() and not p.name.startswith(".")]

    def action_choose(self) -> None:
        node = self.cursor_node
        if node is not None and node.data is not None:
            self.post_message(self.Chosen(node.data.path))


class DownloadScreen(VimModal[DownloadChoice | None]):
    BINDINGS = [Binding("escape", "cancel", "Annuler")]

    def __init__(
        self,
        recs: list[StoredFile],
        default_dir: Path,
        base_dir: Path,
        keep_folder: bool = True,
        tree_root: Path | None = None,
    ) -> None:
        super().__init__()
        self._recs = recs
        self._default = default_dir
        self._base = base_dir  # pour les chemins relatifs : le dossier ouvert dans l'onglet Local
        self._keep = keep_folder
        self._tree_root = tree_root or Path.home()
        self._folders = sorted({r.folder for r in recs if r.folder})

    def compose(self) -> ComposeResult:
        n = len(self._recs)
        lines = [f"{r.path}   {human(r.size)}" for r in self._recs[:14]]
        if n > 14:
            lines.append(f"… et {n - 14} autre(s)")
        with Vertical(classes="dialog wide"):
            yield Label("Télécharger" if n == 1 else f"Télécharger {n} fichiers", classes="dlg-title")
            yield Static("\n".join(lines), classes="dlg-body", markup=False)
            if n > 1:
                total = sum(r.size for r in self._recs)
                yield Static(f"{n} fichier(s) · {human(total)}", classes="dlg-summary", markup=False)
            yield Label("Destination — écris un chemin, ou ↓ puis j/k/h/l dans l'arbre et Entrée", classes="dlg-label")
            yield Input(value=tilde(self._default), placeholder="~/Téléchargements", id="dest")
            yield FolderTree(self._tree_root, id="dirs")
            if self._folders:
                if n == 1:
                    label = f"Recréer le dossier distant « {self._folders[0]}/ »"
                else:
                    shown = ", ".join(f"{f}/" for f in self._folders[:3]) + (", …" if len(self._folders) > 3 else "")
                    label = f"Recréer les dossiers distants ({shown})"
                yield Checkbox(label, value=self._keep, id="keep")
            yield Static("", id="preview", classes="dim", markup=False)
            with Horizontal(classes="dlg-buttons"):
                yield Button("Télécharger  (Entrée)", variant="success", id="go")
                yield Button("Annuler  (Échap)", variant="default", id="cancel")

    def on_mount(self) -> None:
        self.query_one("#dest", Input).focus()
        self._update_preview()

    # -- calcul de la destination
    def _keep_folder(self) -> bool:
        return bool(self._folders) and (not self.query("#keep") or self.query_one("#keep", Checkbox).value)

    def _dest(self) -> Path:
        text = self.query_one("#dest", Input).value.strip()
        path = Path(text).expanduser() if text else self._default
        return path if path.is_absolute() else self._base / path

    def _update_preview(self) -> None:
        dest = self._dest()
        if dest.exists() and not dest.is_dir():
            text = f"⚠ {tilde(dest)} n'est pas un dossier"
        else:
            if len(self._recs) == 1:
                rec = self._recs[0]
                target = dest / rec.folder / rec.name if self._keep_folder() else dest / rec.name
                text = f"→ {tilde(target)}"
            else:
                text = f"→ {tilde(dest)}/" + ("  (dossiers distants recréés)" if self._keep_folder() else "")
            text += "" if dest.exists() else "   (dossier créé)"
        self.query_one("#preview", Static).update(text)

    # -- événements
    def on_input_changed(self, event: Input.Changed) -> None:
        self._update_preview()

    def on_input_submitted(self, event: Input.Submitted) -> None:
        self.action_confirm()

    def on_checkbox_changed(self, event: Checkbox.Changed) -> None:
        self._update_preview()

    def on_directory_tree_directory_selected(self, event: DirectoryTree.DirectorySelected) -> None:
        event.stop()
        self.query_one("#dest", Input).value = tilde(event.path)

    def on_tree_node_highlighted(self, event: FolderTree.NodeHighlighted) -> None:
        """En parcourant l'arbre au clavier, la destination suit le curseur."""
        event.stop()
        tree = self.query_one(FolderTree)
        if tree.has_focus and event.node.data is not None:
            self.query_one("#dest", Input).value = tilde(event.node.data.path)

    def on_folder_tree_chosen(self, event: FolderTree.Chosen) -> None:
        event.stop()
        self.query_one("#dest", Input).value = tilde(event.path)
        self.action_confirm()

    def on_button_pressed(self, event: Button.Pressed) -> None:
        self.action_confirm() if event.button.id == "go" else self.action_cancel()

    def action_confirm(self) -> None:
        dest = self._dest()
        if dest.exists() and not dest.is_dir():
            self.notify(f"{tilde(dest)} n'est pas un dossier.", severity="error")
            return
        self.dismiss(DownloadChoice(dest=dest, keep_folder=self._keep_folder()))

    def action_cancel(self) -> None:
        self.dismiss(None)
