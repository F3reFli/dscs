"""Onglet Distant : fichiers stockés sur Discord, avec sélection multiple."""
from __future__ import annotations

import time
from collections.abc import Callable

from rich.text import Text
from textual.binding import Binding
from textual.message import Message
from textual.widgets import DataTable

from ...models import StoredFile
from ...utils import human
from ..theme import ACCENT


class RemoteTable(DataTable):
    BINDINGS = [
        Binding("j", "cursor_down", show=False),
        Binding("k", "cursor_up", show=False),
        Binding("g", "scroll_top", show=False),
        Binding("G", "scroll_bottom", show=False),
        Binding("space", "mark", "Marquer"),
        Binding("enter", "download", "Télécharger"),
        Binding("l", "download", show=False),
        Binding("d", "download", "Télécharger", show=False),
        Binding("x", "delete", "Supprimer"),
        Binding("o", "open_channel", "Ouvrir le salon"),
        Binding("r", "reload", "Rafraîchir"),
    ]

    class Action(Message):
        def __init__(self, kind: str, file_ids: list[int]) -> None:
            super().__init__()
            self.kind = kind  # "download" | "delete" | "open" | "reload"
            self.file_ids = file_ids  # fichiers marqués, sinon celui sous le curseur

    def __init__(self, **kwargs) -> None:
        super().__init__(**kwargs)
        self.marked: set[int] = set()
        self._records: dict[int, StoredFile] = {}
        self._order: list[int] = []
        self._readable: Callable[[StoredFile], bool] = lambda r: True
        self._name_col = None

    def on_mount(self) -> None:
        self.cursor_type = "row"
        self.zebra_stripes = True
        self._name_col, *_ = self.add_columns("Nom", "Dossier", "Webhook", "Taille", "Morceaux", "Envoyé le")

    def _name_cell(self, rec: StoredFile) -> Text:
        name = Text(rec.name) if self._readable(rec) else Text(f"✗ {rec.name}", style="red")
        return Text.assemble(("● ", f"bold {ACCENT}"), name) if rec.id in self.marked else name

    def fill(
        self,
        records: list[StoredFile],
        readable: Callable[[StoredFile], bool] = lambda r: True,
        webhooks: dict[int, str] | None = None,
    ) -> None:
        """Remplit la table ; un fichier chiffré avec une clé absente du trousseau est marqué ✗ en rouge.
        `webhooks` : pour chaque fichier, le nom des webhooks qui portent ses morceaux. Les marques des fichiers
        toujours présents sont conservées."""
        self.clear()
        webhooks = webhooks or {}
        self._readable = readable
        self._records = {r.id: r for r in records}
        self._order = [r.id for r in records]
        self.marked &= set(self._records)
        for r in records:
            self.add_row(
                self._name_cell(r),
                Text(r.folder or "—", style="dim"),
                Text(webhooks.get(r.id, "—"), style="dim", overflow="ellipsis"),
                Text(human(r.size), justify="right"),
                Text(str(r.chunks), justify="right"),
                time.strftime("%d/%m/%Y %H:%M", time.localtime(r.created)),
                key=str(r.id),
            )

    # -- sélection
    def _current(self) -> int | None:
        if self.row_count == 0:
            return None
        row_key = self.coordinate_to_cell_key(self.cursor_coordinate).row_key
        return int(row_key.value)

    def _targets(self) -> list[int]:
        """Fichiers marqués (dans l'ordre de la table), sinon celui sous le curseur."""
        if self.marked:
            return [fid for fid in self._order if fid in self.marked]
        current = self._current()
        return [current] if current is not None else []

    def action_mark(self) -> None:
        fid = self._current()
        if fid is None:
            return
        self.marked ^= {fid}
        self.update_cell(str(fid), self._name_col, self._name_cell(self._records[fid]))
        self.action_cursor_down()

    def clear_marks(self) -> None:
        for fid in list(self.marked):
            self.marked.discard(fid)
            if fid in self._records:
                self.update_cell(str(fid), self._name_col, self._name_cell(self._records[fid]))

    # -- actions
    def _emit(self, kind: str, ids: list[int]) -> None:
        if ids:
            self.post_message(self.Action(kind, ids))

    def action_download(self) -> None:
        self._emit("download", self._targets())

    def action_delete(self) -> None:
        self._emit("delete", self._targets())

    def action_open_channel(self) -> None:
        current = self._current()
        self._emit("open", [current] if current is not None else [])

    def action_reload(self) -> None:
        self.post_message(self.Action("reload", []))
