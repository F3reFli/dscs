"""Verrou entre processus dscs.

Chaque instance tient un verrou partagé ; `dscs gc` exige l'exclusivité, pour ne jamais
prendre l'envoi en cours d'une autre instance pour un envoi interrompu.
"""
from __future__ import annotations

import os
from pathlib import Path

from ..errors import IndexStateError

try:
    import fcntl
except ImportError:  # Windows : pas de verrou, gc reste possible mais sans garde-fou
    fcntl = None  # type: ignore[assignment]


class ProcessLock:
    def __init__(self, path: Path):
        self.path = path
        self._fd: int | None = None

    def acquire_shared(self) -> None:
        if fcntl is None or self._fd is not None:
            return
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self._fd = os.open(self.path, os.O_RDWR | os.O_CREAT, 0o600)
        fcntl.flock(self._fd, fcntl.LOCK_SH)

    def upgrade_exclusive(self) -> None:
        """Passe en exclusif sans attendre, ou échoue si une autre instance tourne."""
        if fcntl is None:
            return
        if self._fd is None:
            self.acquire_shared()
        try:
            fcntl.flock(self._fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise IndexStateError(
                "Une autre instance de dscs est ouverte : ferme-la avant de lancer cette opération."
            ) from None

    def downgrade_shared(self) -> None:
        if fcntl is not None and self._fd is not None:
            fcntl.flock(self._fd, fcntl.LOCK_SH)

    def release(self) -> None:
        if self._fd is not None:
            os.close(self._fd)  # libère le verrou
            self._fd = None
