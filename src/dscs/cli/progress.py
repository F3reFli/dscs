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

    def __call__(self, n: int) -> None:
        self.done += n
        if not self.live:
            return
        pct = self.done / self.total
        speed = self.done / max(time.monotonic() - self.t0, 1e-6)
        bar = "█" * int(pct * 30) + "░" * (30 - int(pct * 30))
        print(f"\r  {self.label[:30]:30} {bar} {pct:4.0%}  {human(speed)}/s ", end="", file=self.stream, flush=True)

    def finish(self, message: str) -> None:
        if self.live:  # efface la barre de progression
            print(f"\r  {message}".ljust(80), file=self.stream)
        else:
            print(f"  {message}", file=self.stream)
