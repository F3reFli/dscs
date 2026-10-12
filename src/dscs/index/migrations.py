"""Schéma versionné par PRAGMA user_version.

Ajouter une version : écrire `_to_vN(conn)`, l'ajouter à MIGRATIONS[N-1], incrémenter
SCHEMA_VERSION, et ajouter un test qui part d'une base N-1 remplie.
"""
from __future__ import annotations

import sqlite3
from collections.abc import Callable

from ..errors import IndexStateError
from ..models import WebhookRef
from .db import transaction

SCHEMA_VERSION = 4

_V1 = [
    """CREATE TABLE meta (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )""",
    # Jamais de token ici : l'index peut être sauvegardé sans donner accès aux webhooks.
    # channel_id servira à retrouver les messages avec un bot si un webhook est perdu.
    """CREATE TABLE webhooks (
        id         TEXT PRIMARY KEY,
        channel_id TEXT
    )""",
    """CREATE TABLE files (
        id       INTEGER PRIMARY KEY,
        uid      TEXT UNIQUE NOT NULL,
        name     TEXT NOT NULL,
        folder   TEXT NOT NULL DEFAULT '',
        size     INTEGER NOT NULL,
        sha256   TEXT,
        chunks   INTEGER NOT NULL,
        format   INTEGER NOT NULL DEFAULT 1,
        created  REAL NOT NULL,
        complete INTEGER NOT NULL DEFAULT 0
    )""",
    "CREATE INDEX files_by_path ON files(folder, name)",
    """CREATE TABLE chunks (
        file_id    INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
        idx        INTEGER NOT NULL,
        webhook_id TEXT NOT NULL REFERENCES webhooks(id),
        message_id TEXT NOT NULL,
        size       INTEGER NOT NULL,
        PRIMARY KEY (file_id, idx)
    )""",
]


def _has_table(conn: sqlite3.Connection, name: str) -> bool:
    row = conn.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (name,)).fetchone()
    return row is not None


def _to_v1(conn: sqlite3.Connection) -> None:
    if not _has_table(conn, "files"):
        for stmt in _V1:
            conn.execute(stmt)
        return
    # Base créée par dscs 0.1 (user_version jamais posé) : chunks.webhook contenait l'URL
    # complète, token compris. On ne garde que l'ID du webhook.
    conn.execute("ALTER TABLE chunks RENAME TO chunks_v0")
    conn.execute("ALTER TABLE files RENAME TO files_v0")
    for stmt in _V1:
        conn.execute(stmt)
    conn.execute(
        "INSERT INTO files (id, uid, name, folder, size, sha256, chunks, format, created, complete) "
        "SELECT id, uid, name, folder, size, sha256, chunks, 1, created, complete FROM files_v0"
    )
    rows = conn.execute("SELECT file_id, idx, webhook, message_id, size FROM chunks_v0").fetchall()
    for file_id, idx, url, message_id, size in rows:
        wid = WebhookRef.parse(url).id
        conn.execute("INSERT OR IGNORE INTO webhooks (id) VALUES (?)", (wid,))
        conn.execute("INSERT INTO chunks VALUES (?, ?, ?, ?, ?)", (file_id, idx, wid, message_id, size))
    conn.execute("DROP TABLE chunks_v0")
    conn.execute("DROP TABLE files_v0")


def _to_v2(conn: sqlite3.Connection) -> None:
    # Clé refaisable et partageable : plusieurs clés peuvent avoir chiffré les fichiers d'un même index.
    # Chaque fichier retient l'empreinte de la sienne ; l'ancienne empreinte globale de l'index lui est attribuée.
    conn.execute("ALTER TABLE files ADD COLUMN key_id TEXT")
    conn.execute("UPDATE files SET key_id = (SELECT value FROM meta WHERE key = 'key_fingerprint')")
    conn.execute("DELETE FROM meta WHERE key = 'key_fingerprint'")


def _to_v3(conn: sqlite3.Connection) -> None:
    # Coffre synchronisé entre appareils : un fichier supprimé ailleurs ne doit pas réapparaître à la fusion.
    conn.execute(
        """CREATE TABLE tombstones (
            uid     TEXT PRIMARY KEY,
            deleted REAL NOT NULL
        )"""
    )


def _to_v4(conn: sqlite3.Connection) -> None:
    # Infos des webhooks en cache (affichage hors ligne du serveur et du salon dans l'onglet Distant)
    conn.execute("ALTER TABLE webhooks ADD COLUMN guild_id TEXT")
    conn.execute("ALTER TABLE webhooks ADD COLUMN name TEXT")


# version actuelle -> fonction qui amène à version + 1
MIGRATIONS: dict[int, Callable[[sqlite3.Connection], None]] = {0: _to_v1, 1: _to_v2, 2: _to_v3, 3: _to_v4}


def migrate(conn: sqlite3.Connection) -> None:
    version = conn.execute("PRAGMA user_version").fetchone()[0]
    if version > SCHEMA_VERSION:
        raise IndexStateError(
            f"Index en version {version}, cette version de dscs ne connaît que la {SCHEMA_VERSION} : "
            "mets dscs à jour."
        )
    if version < SCHEMA_VERSION:
        conn.execute("PRAGMA foreign_keys = OFF")  # sans effet dans une transaction : avant BEGIN
        try:
            while version < SCHEMA_VERSION:
                with transaction(conn):
                    MIGRATIONS[version](conn)
                    version += 1
                    conn.execute(f"PRAGMA user_version = {version}")
        finally:
            conn.execute("PRAGMA foreign_keys = ON")
        # les pages libérées gardent les anciennes données (tokens de la v0) : on réécrit le fichier
        conn.execute("VACUUM")
    conn.execute("PRAGMA foreign_keys = ON")
