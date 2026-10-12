"""Connexion SQLite et transactions explicites."""
from __future__ import annotations

import os
import sqlite3
from collections.abc import Iterator
from contextlib import contextmanager
from pathlib import Path


def connect(path: Path) -> sqlite3.Connection:
    path.parent.mkdir(parents=True, exist_ok=True)
    new = not path.exists()
    # autocommit : chaque écriture isolée est durable tout de suite (un crash en plein envoi
    # laisse un index cohérent), les écritures groupées passent par transaction()
    conn = sqlite3.connect(path, isolation_level=None)
    if new:
        os.chmod(path, 0o600)
    conn.execute("PRAGMA journal_mode = WAL")
    conn.execute("PRAGMA busy_timeout = 5000")
    return conn


@contextmanager
def transaction(conn: sqlite3.Connection) -> Iterator[sqlite3.Connection]:
    conn.execute("BEGIN IMMEDIATE")
    try:
        yield conn
    except BaseException:
        conn.execute("ROLLBACK")
        raise
    conn.execute("COMMIT")
