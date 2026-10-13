"""Journal de dscs (-v, -vv, --log) : ce que fait dscs, étape par étape, sans jamais de secret.

Chaque module écrit dans son journal (`logging.getLogger(__name__)`) ; rien ne sort tant que `setup` ne l'active
pas. Un filtre masque en dernier recours tout ce qui ressemble à un token de webhook ou à une signature de lien.
"""
from __future__ import annotations

import logging
import os
import sys
from pathlib import Path

from ..utils import redact_secrets

ROOT = "dscs"


class _Redact(logging.Filter):
    def filter(self, record: logging.LogRecord) -> bool:
        record.msg, record.args = redact_secrets(record.getMessage()), ()
        record.short = record.name.removeprefix(ROOT + ".")
        return True


def level_for(verbosity: int) -> int:
    return logging.DEBUG if verbosity >= 2 else logging.INFO


def setup(verbosity: int, log_file: Path | None = None) -> None:
    """0 : muet ; 1 (-v) : les étapes ; 2 (-vv) : chaque requête et chaque morceau.

    Avec `log_file`, le journal va dans ce fichier (créé en 600 : il contient des noms de fichiers), sinon sur la
    sortie d'erreur. Rappelable : la configuration précédente est remplacée.
    """
    logger = logging.getLogger(ROOT)
    for handler in [h for h in logger.handlers if not isinstance(h, logging.NullHandler)]:
        logger.removeHandler(handler)
        handler.close()
    if verbosity <= 0 and log_file is None:
        logger.setLevel(logging.WARNING)
        logger.propagate = True
        return
    if log_file is not None:
        log_file.parent.mkdir(parents=True, exist_ok=True)
        os.close(os.open(log_file, os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o600))
        handler: logging.Handler = logging.FileHandler(log_file, encoding="utf-8")
        formatter = logging.Formatter("%(asctime)s %(levelname)-7s %(short)s : %(message)s", "%Y-%m-%d %H:%M:%S")
    else:
        handler = logging.StreamHandler(sys.stderr)
        formatter = logging.Formatter("%(asctime)s.%(msecs)03d %(short)-17s %(message)s", "%H:%M:%S")
    handler.addFilter(_Redact())
    handler.setFormatter(formatter)
    logger.addHandler(handler)
    logger.setLevel(level_for(max(verbosity, 1)))
    logger.propagate = False  # pas de doublon si une bibliothèque configure le journal racine
