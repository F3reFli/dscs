"""Base des fenêtres : on passe d'un champ à l'autre avec h j k l (ou ↑ ↓), comme dans le reste de l'interface.

Ces touches ne servent que si le widget sous le curseur ne les utilise pas déjà : une liste ou un arbre gardent
j/k pour bouger, et un champ de saisie garde toutes les lettres.
"""
from __future__ import annotations

from textual.binding import Binding
from textual.screen import ModalScreen, ScreenResultType


class VimModal(ModalScreen[ScreenResultType]):
    BINDINGS = [
        Binding("j", "app.focus_next", show=False),
        Binding("l", "app.focus_next", show=False),
        Binding("down", "app.focus_next", show=False),
        Binding("k", "app.focus_previous", show=False),
        Binding("h", "app.focus_previous", show=False),
        Binding("up", "app.focus_previous", show=False),
    ]
