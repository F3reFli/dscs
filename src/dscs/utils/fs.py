"""Système de fichiers local : sélection à envoyer, noms de destination, fichiers secrets."""
from __future__ import annotations

import os
import secrets
from collections.abc import Iterable
from pathlib import Path

from ..errors import DSCSError


def expand(paths: Iterable[Path]) -> list[tuple[Path, str]]:
    """Fichiers et dossiers sélectionnés -> liste (fichier, dossier distant), sans doublons.

    Un dossier `projet/` donne ses fichiers avec les dossiers distants `projet`, `projet/src`…
    Les liens symboliques à l'intérieur d'un dossier sont ignorés.
    """
    seen: set[Path] = set()
    out: list[tuple[Path, str]] = []
    for p in paths:
        if p.is_file():
            items = [(p, "")]
        elif p.is_dir():
            items = []
            for f in sorted(p.rglob("*")):
                if f.is_file() and not f.is_symlink():
                    rel = f.parent.relative_to(p)
                    folder = p.name if str(rel) == "." else f"{p.name}/{rel.as_posix()}"
                    items.append((f, folder))
        else:
            continue
        for f, folder in items:
            r = f.resolve()
            if r not in seen:
                seen.add(r)
                out.append((f, folder))
    return out


def unique_path(path: Path) -> Path:
    """Jamais d'écrasement : `notes.md` existe -> `notes (1).md`."""
    if not path.exists():
        return path
    for i in range(1, 10_000):
        cand = path.with_name(f"{path.stem} ({i}){path.suffix}")
        if not cand.exists():
            return cand
    raise DSCSError(f"Impossible de trouver un nom libre pour {path}")


def safe_join(base: Path, *parts: str) -> Path:
    """Joint des noms venant de l'index en refusant de sortir de `base` (« ../ », chemins absolus)."""
    target = base.joinpath(*parts)
    root = base.resolve()
    resolved = target.resolve()
    if resolved != root and root not in resolved.parents:
        raise DSCSError(f"Chemin refusé (sort du dossier de destination) : {'/'.join(parts)}")
    return target


def write_private(path: Path, data: bytes) -> None:
    """Écrit un fichier lisible par toi seul (0600), de façon atomique : à tout instant, il contient
    l'ancienne version complète ou la nouvelle, jamais un mélange."""
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(f".{path.name}.{secrets.token_hex(4)}.tmp")
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, path)
    except BaseException:
        tmp.unlink(missing_ok=True)
        raise


def shred_file(path: Path) -> bool:
    """Écrase le contenu d'un fichier secret puis le supprime. Renvoie False s'il n'existait pas.

    Sur SSD, l'écrasement ne garantit pas l'effacement physique des anciennes données.
    """
    try:
        size = path.stat().st_size
    except FileNotFoundError:
        return False
    with open(path, "r+b") as f:
        f.write(secrets.token_bytes(max(size, 1)))
        f.flush()
        os.fsync(f.fileno())
    path.unlink()
    return True
