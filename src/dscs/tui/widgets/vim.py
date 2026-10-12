"""Navigation façon vim (h j k l, g G), commune à l'onglet Local et aux fenêtres."""
from __future__ import annotations

from pathlib import Path

from textual.binding import Binding
from textual.widgets import DirectoryTree, SelectionList
from textual.widgets.selection_list import SelectionType


class VimDirectoryTree(DirectoryTree):
    """Arbre de fichiers : j/k bouger, l ouvrir (ou entrer dans le dossier ouvert), h fermer / remonter."""

    BINDINGS = [
        Binding("j", "cursor_down", "Bas", show=False),
        Binding("k", "cursor_up", "Haut", show=False),
        Binding("l", "enter", "Ouvrir", show=False),
        Binding("h", "leave", "Fermer", show=False),
        Binding("g", "top", show=False),
        Binding("G", "bottom", show=False),
    ]

    def action_enter(self) -> None:
        node = self.cursor_node
        if node is None or node.data is None:
            return
        if node.allow_expand:
            if node.is_expanded and node.children:
                self.cursor_line += 1
            else:
                node.expand()
        else:
            self.action_select_cursor()  # -> FileSelected

    def action_leave(self) -> None:
        node = self.cursor_node
        if node is None:
            return
        if node is not self.root and node.allow_expand and node.is_expanded:
            node.collapse()
        elif node.parent is not None:
            self.move_cursor(node.parent)
        else:
            self.action_parent_root()

    def action_top(self) -> None:
        self.cursor_line = 0

    def action_bottom(self) -> None:
        self.cursor_line = self.last_line

    def action_parent_root(self) -> None:
        current = Path(self.path).resolve()
        if current.parent != current:
            self.path = current.parent

    def action_home_root(self) -> None:
        self.path = Path.home()


class VimSelectionList(SelectionList[SelectionType]):
    """Liste à cocher : j/k bouger, g/G premier / dernier ; espace (ou Entrée) coche."""

    BINDINGS = [
        Binding("j", "cursor_down", show=False),
        Binding("k", "cursor_up", show=False),
        Binding("g", "first", show=False),
        Binding("G", "last", show=False),
    ]
