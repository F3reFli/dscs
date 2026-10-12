"""Onglet Local : arbre de fichiers avec navigation vim et marquage multiple."""
from __future__ import annotations

from collections.abc import Iterable
from pathlib import Path

from rich.text import Text
from textual.binding import Binding
from textual.message import Message
from textual.widgets import DirectoryTree

from ...utils import human
from ..theme import ACCENT
from .vim import VimDirectoryTree


class VimTree(VimDirectoryTree):
    BINDINGS = [
        Binding("space", "mark", "Marquer"),
        Binding("u", "send", "Envoyer"),
        Binding("full_stop", "toggle_hidden", "Cachés"),
        Binding("minus", "parent_root", "Parent"),
        Binding("tilde", "home_root", "~", show=False),
    ]

    class SendRequest(Message):
        def __init__(self, paths: list[Path]) -> None:
            super().__init__()
            self.paths = paths

    def __init__(self, path: Path, show_hidden: bool = False, **kwargs) -> None:
        super().__init__(path, **kwargs)
        self.show_hidden = show_hidden
        self.marked: set[Path] = set()

    def filter_paths(self, paths: Iterable[Path]) -> Iterable[Path]:
        if self.show_hidden:
            return paths
        return [p for p in paths if not p.name.startswith(".")]

    def render_label(self, node, base_style, style) -> Text:
        label = super().render_label(node, base_style, style)
        if node.data is None:
            return label
        path = node.data.path
        if path in self.marked:
            label = Text.assemble(("● ", f"bold {ACCENT}"), label)
        if not node.allow_expand:
            try:
                label.append(f"  {human(path.stat().st_size)}", style="dim")
            except OSError:
                pass
        return label

    # -- navigation (h j k l g G : VimDirectoryTree)
    def action_toggle_hidden(self) -> None:
        self.show_hidden = not self.show_hidden
        self.reload()
        self.notify(f"Fichiers cachés : {'visibles' if self.show_hidden else 'masqués'}", timeout=2)

    # -- sélection
    def action_mark(self) -> None:
        node = self.cursor_node
        if node is None or node.data is None:
            return
        self.marked ^= {node.data.path}
        node.refresh()
        self.action_cursor_down()

    def clear_marks(self) -> None:
        self.marked.clear()
        self.root.refresh()
        self.refresh()

    def _targets(self, fallback: Path | None) -> list[Path]:
        if self.marked:
            return sorted(self.marked)
        return [fallback] if fallback else []

    def action_send(self) -> None:
        node = self.cursor_node
        paths = self._targets(node.data.path if node and node.data else None)
        if paths:
            self.post_message(self.SendRequest(paths))

    def on_directory_tree_file_selected(self, event: DirectoryTree.FileSelected) -> None:
        event.stop()
        self.post_message(self.SendRequest(self._targets(event.path)))
