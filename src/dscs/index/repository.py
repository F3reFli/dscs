"""Accès à l'index. Seul module qui écrit du SQL (hors migrations)."""
from __future__ import annotations

import sqlite3
import time
from pathlib import Path

from ..errors import IndexStateError
from ..models import Chunk, StoredFile
from .db import connect, transaction
from .migrations import migrate

_FILE_COLS = "id, uid, name, folder, size, sha256, chunks, created, format, key_id"


class Index:
    def __init__(self, conn: sqlite3.Connection):
        self.conn = conn

    @classmethod
    def open(cls, path: Path) -> Index:
        try:
            conn = connect(path)
        except sqlite3.DatabaseError as e:
            raise IndexStateError(f"Index illisible ({path}) : {e}") from None
        try:
            migrate(conn)
        except sqlite3.DatabaseError as e:
            conn.close()
            raise IndexStateError(f"Index illisible ({path}) : {e}") from None
        except BaseException:
            conn.close()
            raise
        return cls(conn)

    def close(self) -> None:
        self.conn.close()

    # ------------------------------------------------------------------ meta

    def get_meta(self, key: str) -> str | None:
        row = self.conn.execute("SELECT value FROM meta WHERE key=?", (key,)).fetchone()
        return row[0] if row else None

    def set_meta(self, key: str, value: str) -> None:
        self.conn.execute(
            "INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
            (key, value),
        )

    # ------------------------------------------------------------------ clés

    def adopt_unknown_files(self, key_id: str) -> None:
        """Fichiers sans empreinte de clé (index migré d'une version qui ne la notait pas) : attribués à la clé
        présente au premier lancement."""
        self.conn.execute("UPDATE files SET key_id = ? WHERE key_id IS NULL", (key_id,))

    def files_per_key(self) -> dict[str | None, tuple[int, int]]:
        """Fichiers complets par empreinte de clé : {key_id: (nombre, taille totale)}."""
        rows = self.conn.execute(
            "SELECT key_id, COUNT(*), COALESCE(SUM(size), 0) FROM files WHERE complete = 1 GROUP BY key_id"
        ).fetchall()
        return {key_id: (int(n), int(size)) for key_id, n, size in rows}

    # ------------------------------------------------------------------ écriture

    def register_webhook(self, webhook_id: str, channel_id: str | None = None) -> None:
        self.conn.execute(
            "INSERT INTO webhooks (id, channel_id) VALUES (?, ?) "
            "ON CONFLICT(id) DO UPDATE SET channel_id = COALESCE(excluded.channel_id, webhooks.channel_id)",
            (webhook_id, channel_id),
        )

    def update_webhook_info(self, webhook_id: str, name: str | None, guild_id: str | None, channel_id: str | None):
        self.conn.execute(
            "INSERT INTO webhooks (id, name, guild_id, channel_id) VALUES (?, ?, ?, ?) "
            "ON CONFLICT(id) DO UPDATE SET name = excluded.name, "
            "guild_id = COALESCE(excluded.guild_id, webhooks.guild_id), "
            "channel_id = COALESCE(excluded.channel_id, webhooks.channel_id)",
            (webhook_id, name, guild_id, channel_id),
        )

    def forget_webhook(self, webhook_id: str) -> list[StoredFile]:
        """Webhook retiré du coffre : ses morceaux sont perdus, il sort de l'index (cache compris).

        Les fichiers qui avaient des morceaux dessus deviennent incomplets (leurs morceaux restés sur les autres
        webhooks sont à supprimer, voir purge_file) et reçoivent une pierre tombale : les autres appareils les
        oublient à la prochaine synchronisation. Renvoie ceux qui étaient complets.
        """
        with transaction(self.conn):
            rows = self.conn.execute(
                f"SELECT {_FILE_COLS} FROM files WHERE complete = 1 AND id IN "
                "(SELECT file_id FROM chunks WHERE webhook_id = ?) ORDER BY id",
                (webhook_id,),
            ).fetchall()
            lost = [StoredFile(*r) for r in rows]
            for rec in lost:
                self.add_tombstone(rec.uid)
                self.mark_incomplete(rec.id)
            self.conn.execute("DELETE FROM chunks WHERE webhook_id = ?", (webhook_id,))
            self.conn.execute("DELETE FROM webhooks WHERE id = ?", (webhook_id,))
        return lost

    def add_file(
        self, uid: str, name: str, folder: str, size: int, chunks: int, fmt: int, key_id: str | None
    ) -> int:
        cur = self.conn.execute(
            "INSERT INTO files (uid, name, folder, size, chunks, format, key_id, created) "
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            (uid, name, folder, size, chunks, fmt, key_id, time.time()),
        )
        return int(cur.lastrowid)

    def add_chunk(self, file_id: int, chunk: Chunk) -> None:
        self.conn.execute(
            "INSERT INTO chunks (file_id, idx, webhook_id, message_id, size) VALUES (?, ?, ?, ?, ?)",
            (file_id, chunk.idx, chunk.webhook_id, chunk.message_id, chunk.size),
        )

    def mark_complete(self, file_id: int, sha256: str) -> None:
        self.conn.execute("UPDATE files SET sha256=?, complete=1 WHERE id=?", (sha256, file_id))

    def mark_incomplete(self, file_id: int) -> None:
        self.conn.execute("UPDATE files SET complete=0 WHERE id=?", (file_id,))

    def delete_chunk(self, file_id: int, idx: int) -> None:
        self.conn.execute("DELETE FROM chunks WHERE file_id=? AND idx=?", (file_id, idx))

    def delete_file(self, file_id: int) -> None:
        self.conn.execute("DELETE FROM files WHERE id=?", (file_id,))

    def add_tombstone(self, uid: str, when: float | None = None) -> None:
        """Marque un fichier comme supprimé, pour que la synchronisation ne le fasse pas réapparaître."""
        self.conn.execute(
            "INSERT OR IGNORE INTO tombstones (uid, deleted) VALUES (?, ?)", (uid, when or time.time())
        )

    # ------------------------------------------------------------------ lecture

    def get_file(self, file_id: int) -> StoredFile | None:
        row = self.conn.execute(
            f"SELECT {_FILE_COLS} FROM files WHERE id=? AND complete=1", (file_id,)
        ).fetchone()
        return StoredFile(*row) if row else None

    def list_files(self) -> list[StoredFile]:
        rows = self.conn.execute(
            f"SELECT {_FILE_COLS} FROM files WHERE complete=1 ORDER BY folder, name"
        ).fetchall()
        return [StoredFile(*r) for r in rows]

    def incomplete_files(self) -> list[StoredFile]:
        """Envois interrompus (crash, coupure) ou suppressions partielles : à nettoyer par gc."""
        rows = self.conn.execute(f"SELECT {_FILE_COLS} FROM files WHERE complete=0 ORDER BY id").fetchall()
        return [StoredFile(*r) for r in rows]

    def webhook_rows(self) -> dict[str, tuple[str | None, str | None, str | None]]:
        """Infos en cache par webhook : {id: (nom, guild_id, channel_id)}."""
        rows = self.conn.execute("SELECT id, name, guild_id, channel_id FROM webhooks").fetchall()
        return {wid: (name, guild, channel) for wid, name, guild, channel in rows}

    def chunks_per_webhook(self) -> dict[str, int]:
        rows = self.conn.execute(
            "SELECT c.webhook_id, COUNT(*) FROM chunks c JOIN files f ON f.id = c.file_id "
            "WHERE f.complete = 1 GROUP BY c.webhook_id"
        ).fetchall()
        return dict(rows)

    def chunks_on_webhook(self, webhook_id: str) -> list[str]:
        """Messages de tous les morceaux (envois terminés ou non) portés par un webhook."""
        rows = self.conn.execute(
            "SELECT message_id FROM chunks WHERE webhook_id = ? ORDER BY file_id, idx", (webhook_id,)
        ).fetchall()
        return [r[0] for r in rows]

    def webhooks_by_file(self) -> dict[int, list[str]]:
        """Webhooks qui portent les morceaux de chaque fichier complet."""
        rows = self.conn.execute(
            "SELECT DISTINCT c.file_id, c.webhook_id FROM chunks c JOIN files f ON f.id = c.file_id "
            "WHERE f.complete = 1 ORDER BY c.file_id, c.webhook_id"
        ).fetchall()
        out: dict[int, list[str]] = {}
        for file_id, webhook_id in rows:
            out.setdefault(file_id, []).append(webhook_id)
        return out

    def chunks(self, file_id: int) -> list[Chunk]:
        rows = self.conn.execute(
            "SELECT idx, webhook_id, message_id, size FROM chunks WHERE file_id=? ORDER BY idx", (file_id,)
        ).fetchall()
        return [Chunk(*r) for r in rows]

    # ------------------------------------------------------------------ synchronisation

    def tombstones(self) -> dict[str, float]:
        return dict(self.conn.execute("SELECT uid, deleted FROM tombstones").fetchall())

    def export_state(self) -> dict:
        """État partageable : fichiers complets (avec leurs morceaux) et pierres tombales. Trié : déterministe."""
        files = []
        for rec in self.list_files():
            files.append(
                {
                    "uid": rec.uid,
                    "name": rec.name,
                    "folder": rec.folder,
                    "size": rec.size,
                    "sha256": rec.sha256,
                    "chunks": rec.chunks,
                    "created": rec.created,
                    "format": rec.format,
                    "key_id": rec.key_id,
                    "parts": [[c.idx, c.webhook_id, c.message_id, c.size] for c in self.chunks(rec.id)],
                }
            )
        files.sort(key=lambda f: f["uid"])
        return {"files": files, "tombstones": dict(sorted(self.tombstones().items()))}

    def import_state(self, state: dict) -> int:
        """Fusionne un état venu d'un autre appareil. Renvoie le nombre de fichiers ajoutés.

        Union des fichiers et des pierres tombales ; une pierre tombale l'emporte toujours. Les fichiers
        incomplets locaux (envoi ou suppression en cours) ne sont pas touchés : `dscs gc` s'en charge.
        """
        added = 0
        with transaction(self.conn):
            for uid, when in state.get("tombstones", {}).items():
                self.conn.execute("INSERT OR IGNORE INTO tombstones (uid, deleted) VALUES (?, ?)", (uid, when))
            self.conn.execute("DELETE FROM files WHERE complete = 1 AND uid IN (SELECT uid FROM tombstones)")
            known = {row[0] for row in self.conn.execute("SELECT uid FROM files")}
            dead = {row[0] for row in self.conn.execute("SELECT uid FROM tombstones")}
            for f in state.get("files", []):
                if f["uid"] in known or f["uid"] in dead:
                    continue
                cur = self.conn.execute(
                    "INSERT INTO files (uid, name, folder, size, sha256, chunks, format, key_id, created, complete) "
                    "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                    (f["uid"], f["name"], f["folder"], f["size"], f["sha256"], f["chunks"], f["format"],
                     f["key_id"], f["created"]),
                )
                for idx, webhook_id, message_id, size in f["parts"]:
                    self.conn.execute("INSERT OR IGNORE INTO webhooks (id) VALUES (?)", (webhook_id,))
                    self.conn.execute(
                        "INSERT INTO chunks (file_id, idx, webhook_id, message_id, size) VALUES (?, ?, ?, ?, ?)",
                        (cur.lastrowid, idx, webhook_id, message_id, size),
                    )
                added += 1
        return added
