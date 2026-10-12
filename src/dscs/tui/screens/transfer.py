"""Écran de progression d'un transfert, annulable avec `c`."""
from __future__ import annotations

from collections.abc import Callable

from rich.text import Text
from textual.app import ComposeResult
from textual.binding import Binding
from textual.containers import Vertical
from textual.screen import ModalScreen
from textual.widgets import Label, ProgressBar, RichLog
from textual.worker import Worker


class TransferScreen(ModalScreen[None]):
    BINDINGS = [
        Binding("escape", "close", "Fermer"),
        Binding("q", "close", show=False),
        Binding("c", "cancel", "Annuler"),
        Binding("j", "scroll_log(1)", show=False),
        Binding("k", "scroll_log(-1)", show=False),
        Binding("g", "scroll_log_edge(False)", show=False),
        Binding("G", "scroll_log_edge(True)", show=False),
    ]

    def __init__(self, title: str, total: int, start: Callable[[TransferScreen], Worker] | None = None) -> None:
        super().__init__()
        self._start = start  # lance le transfert une fois l'écran monté
        self._title = title
        self._total = max(total, 1)
        self.done_bytes = 0
        self.finished = False
        self.on_cancel: Callable[[], None] | None = None

    def compose(self) -> ComposeResult:
        with Vertical(classes="dialog wide"):
            yield Label(self._title, classes="dlg-title")
            yield Label("", id="current")
            yield ProgressBar(total=self._total, show_eta=True, id="bar")
            yield RichLog(id="log", wrap=True, markup=False)
            yield Label("c : annuler", id="hint", classes="dim")

    def on_mount(self) -> None:
        if self._start:
            self.on_cancel = self._start(self).cancel

    def set_current(self, text: str) -> None:
        self.query_one("#current", Label).update(Text(text))

    def advance(self, n: int) -> None:
        self.done_bytes += n
        self.query_one("#bar", ProgressBar).advance(n)

    def log_line(self, text: str, style: str = "") -> None:
        self.query_one("#log", RichLog).write(Text(text, style=style))

    def finish(self, text: str, ok: bool = True) -> None:
        self.finished = True
        self.set_current(text)
        if ok:
            self.query_one("#bar", ProgressBar).update(progress=self._total)
        self.query_one("#hint", Label).update("Échap : fermer")

    def action_scroll_log(self, lines: int) -> None:
        self.query_one("#log", RichLog).scroll_relative(y=lines, animate=False)

    def action_scroll_log_edge(self, end: bool) -> None:
        log = self.query_one("#log", RichLog)
        log.scroll_end(animate=False) if end else log.scroll_home(animate=False)

    def action_close(self) -> None:
        if self.finished:
            self.dismiss()
        else:
            self.notify("Transfert en cours — c pour annuler", timeout=2)

    def action_cancel(self) -> None:
        if not self.finished and self.on_cancel:
            self.on_cancel()
