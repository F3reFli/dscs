"""Barre de progression texte pour la CLI."""
from __future__ import annotations

import sys
import time
from typing import TextIO

from ..utils import human


class TextProgress:
    def __init__(self, label: str, total: int, stream: TextIO | None = None):
        self.label = label
        self.total = max(total, 1)
        self.done = 0
        self.t0 = time.monotonic()
        self.stream = stream or sys.stdout
        self.live = self.stream.isatty()

    @property
    def speed(self) -> float:
        """Débit moyen depuis le début, en octets par seconde."""
        return self.done / max(time.monotonic() - self.t0, 1e-6)

    def __call__(self, n: int) -> None:
        self.done += n
        self._draw()

    def _draw(self) -> None:
        if not self.live:
            return
        pct = min(self.done / self.total, 1.0)
        bar = "█" * int(pct * 30) + "░" * (30 - int(pct * 30))
        line = f"\r  {self.label[:30]:30} {bar} {pct:4.0%}  {human(self.speed)}/s "
        print(line, end="", file=self.stream, flush=True)

    def log(self, message: str) -> None:
        """Une ligne au-dessus de la barre, qui continue en dessous."""
        self.finish(message)
        self._draw()

    def finish(self, message: str) -> None:
        if self.live:  # efface la barre de progression
            print(f"\r  {message}".ljust(80), file=self.stream)
        else:
            print(f"  {message}", file=self.stream)
