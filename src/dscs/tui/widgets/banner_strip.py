"""Bandeau d'accueil de l'interface : l'animation de `dscs -h` (tête de mort, cadenas, DSCS), puis il se replie.

Il ne bloque rien : ce n'est pas un écran, il ne prend jamais le focus, et rien n'attend la fin de l'animation.
L'arbre répond au clavier dès le lancement et la synchronisation démarre en même temps. Une tâche indépendante passe
les images (pas les minuteurs de Textual) ; la moindre erreur replie le bandeau, sans autre effet.
"""
from __future__ import annotations

import asyncio
import os

from rich.text import Text
from textual.app import ComposeResult
from textual.containers import Center
from textual.widgets import Static

from ...utils import banner

HOLD = 0.8  # la bannière au repos reste un instant avant de se replier
ROOM = 12  # lignes laissées à l'interface pendant l'animation : sinon, pas de bandeau


class BannerStrip(Center):
    can_focus = False

    def __init__(self) -> None:
        super().__init__()
        self.playing = False  # animation en cours (avant la pause finale)

    def compose(self) -> ComposeResult:
        yield Static(id="banner-art")

    def on_mount(self) -> None:
        width, height = self.app.size
        steps = banner.animation(width) if banner.fits(width) else []
        if os.environ.get("DSCS_NO_ANIMATION") or not steps or height < len(steps[0][0]) + ROOM:
            self.display = False
            return
        self.playing = True
        self.run_worker(self._play(steps), group="banner", exit_on_error=False)

    async def _play(self, steps: list[tuple[list[str], float]]) -> None:
        try:
            art = self.query_one("#banner-art", Static)
            for lines, delay in steps:
                art.update(Text.from_ansi("\n".join(lines)))
                await asyncio.sleep(delay)
            self.playing = False
            await asyncio.sleep(HOLD)
        except Exception:
            pass  # simple décor : une erreur ne doit jamais gêner l'interface
        finally:
            self.playing = False
            try:
                self.display = False
            except Exception:
                pass  # interface déjà fermée
