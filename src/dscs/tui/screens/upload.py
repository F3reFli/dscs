"""Confirmation d'un envoi, avec le choix des webhooks à utiliser."""
from __future__ import annotations

from rich.text import Text
from textual.app import ComposeResult
from textual.binding import Binding
from textual.containers import Horizontal, Vertical
from textual.widgets import Button, Label, SelectionList, Static
from textual.widgets.selection_list import Selection

from ...models import WebhookInfo
from ..widgets import VimSelectionList
from .base import VimModal


def webhook_label(info: WebhookInfo) -> Text:
    return Text.assemble(info.display_name, ("  ·  ", "dim"), f"{info.server} / {info.channel}")


class UploadScreen(VimModal[list[str] | None]):
    """Renvoie les identifiants des webhooks cochés (dans l'ordre du coffre), ou None si annulé."""

    BINDINGS = [
        Binding("y", "confirm", "Envoyer"),
        Binding("o", "confirm", show=False),
        Binding("n", "cancel", "Annuler"),
        Binding("escape", "cancel", show=False),
        Binding("a", "toggle_all", "Tout cocher / décocher"),
    ]

    def __init__(
        self, lines: list[str], summary: str, webhooks: list[WebhookInfo], selected: set[str] | None = None
    ) -> None:
        super().__init__()
        self._lines = lines
        self._summary = summary
        self._webhooks = webhooks
        self._selected = selected if selected else {w.id for w in webhooks}

    def compose(self) -> ComposeResult:
        with Vertical(classes="dialog wide"):
            yield Label("Envoyer sur Discord ?", classes="dlg-title")
            yield Static("\n".join(self._lines), classes="dlg-body", markup=False)
            yield Static(self._summary, classes="dlg-summary", markup=False)
            yield Label(
                "Webhooks à utiliser — j/k : bouger · espace : cocher · a : tous · y : envoyer", classes="dlg-label"
            )
            yield VimSelectionList[str](
                *[Selection(webhook_label(w), w.id, w.id in self._selected) for w in self._webhooks], id="hooks"
            )
            with Horizontal(classes="dlg-buttons"):
                yield Button("Envoyer  (y)", variant="success", id="yes")
                yield Button("Annuler  (n)", variant="default", id="no")

    def on_mount(self) -> None:
        self.query_one(SelectionList).focus()

    def on_button_pressed(self, event: Button.Pressed) -> None:
        self.action_confirm() if event.button.id == "yes" else self.action_cancel()

    def action_toggle_all(self) -> None:
        hooks = self.query_one(SelectionList)
        if len(hooks.selected) == len(self._webhooks):
            hooks.deselect_all()
        else:
            hooks.select_all()

    def action_confirm(self) -> None:
        chosen = set(self.query_one(SelectionList).selected)
        if not chosen:
            self.notify("Coche au moins un webhook.", severity="warning")
            return
        self.dismiss([w.id for w in self._webhooks if w.id in chosen])

    def action_cancel(self) -> None:
        self.dismiss(None)
