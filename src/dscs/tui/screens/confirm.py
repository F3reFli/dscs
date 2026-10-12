"""Écran de confirmation y/n avant toute opération réseau."""
from __future__ import annotations

from textual.app import ComposeResult
from textual.binding import Binding
from textual.containers import Horizontal, Vertical
from textual.widgets import Button, Label, Static

from .base import VimModal


class ConfirmScreen(VimModal[bool]):
    BINDINGS = [
        Binding("y", "answer(True)", "Oui"),
        Binding("o", "answer(True)", show=False),
        Binding("n", "answer(False)", "Non"),
        Binding("escape", "answer(False)", show=False),
    ]

    def __init__(self, title: str, lines: list[str], summary: str, danger: bool = False) -> None:
        super().__init__()
        self._title = title
        self._lines = lines
        self._summary = summary
        self._danger = danger

    def compose(self) -> ComposeResult:
        with Vertical(classes="dialog"):
            yield Label(self._title, classes="dlg-title")
            yield Static("\n".join(self._lines), classes="dlg-body", markup=False)
            yield Static(self._summary, classes="dlg-summary", markup=False)
            with Horizontal(classes="dlg-buttons"):
                yield Button("Oui  (y)", variant="error" if self._danger else "success", id="yes")
                yield Button("Non  (n)", variant="default", id="no")

    def on_mount(self) -> None:
        self.query_one("#no" if self._danger else "#yes", Button).focus()

    def on_button_pressed(self, event: Button.Pressed) -> None:
        self.dismiss(event.button.id == "yes")

    def action_answer(self, value: bool) -> None:
        self.dismiss(value)
