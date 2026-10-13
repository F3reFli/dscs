import sqlite3
import time

import pytest

from dscs.errors import IndexStateError
from dscs.index import SCHEMA_VERSION, Index, ProcessLock
from dscs.index.migrations import _V1
from dscs.models import Chunk

# Schéma exact de dscs 0.1 (index sans user_version, URL de webhook complète dans chunks)
LEGACY_SCHEMA = """
CREATE TABLE files (
    id INTEGER PRIMARY KEY, uid TEXT UNIQUE NOT NULL, name TEXT NOT NULL,
    folder TEXT NOT NULL DEFAULT '', size INTEGER NOT NULL, sha256 TEXT,
    chunks INTEGER NOT NULL, created REAL NOT NULL, complete INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE chunks (
    file_id INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
    idx INTEGER NOT NULL, webhook TEXT NOT NULL, message_id TEXT NOT NULL,
    size INTEGER NOT NULL, PRIMARY KEY (file_id, idx)
);
"""


def test_fresh_index_has_current_schema(tmp_path):
    idx = Index.open(tmp_path / "index.db")
    assert idx.conn.execute("PRAGMA user_version").fetchone()[0] == SCHEMA_VERSION
    assert oct((tmp_path / "index.db").stat().st_mode)[-3:] == "600"
    idx.close()


def test_file_lifecycle(tmp_path):
    idx = Index.open(tmp_path / "index.db")
    idx.register_webhook("1")
    fid = idx.add_file("u1", "a.txt", "docs", 10, 2, 1, "k1")
    idx.add_chunk(fid, Chunk(0, "1", "m0", 40))
    assert idx.get_file(fid) is None, "un envoi non terminé est invisible"
    assert [f.id for f in idx.incomplete_files()] == [fid]
    idx.add_chunk(fid, Chunk(1, "1", "m1", 40))
    idx.mark_complete(fid, "abc")
    rec = idx.get_file(fid)
    assert rec.path == "docs/a.txt" and rec.sha256 == "abc" and rec.format == 1 and rec.key_id == "k1"
    assert [c.message_id for c in idx.chunks(fid)] == ["m0", "m1"]
    idx.delete_file(fid)
    assert idx.chunks(fid) == [], "les morceaux suivent le fichier (ON DELETE CASCADE)"
    idx.close()


def test_chunk_requires_known_webhook(tmp_path):
    idx = Index.open(tmp_path / "index.db")
    fid = idx.add_file("u1", "a", "", 1, 1, 1, None)
    with pytest.raises(sqlite3.IntegrityError):
        idx.add_chunk(fid, Chunk(0, "inconnu", "m0", 1))
    idx.close()


def test_forget_webhook(tmp_path):
    idx = Index.open(tmp_path / "index.db")
    idx.update_webhook_info("1", "un", "g", "c1")
    idx.update_webhook_info("2", "deux", "g", "c2")
    files = {}
    for uid, hooks in (("a", ["1"]), ("b", ["2"]), ("c", ["1", "2"])):
        fid = files[uid] = idx.add_file(uid, uid, "", 1, len(hooks), 1, None)
        for n, w in enumerate(hooks):
            idx.add_chunk(fid, Chunk(n, w, f"{uid}{n}", 1))
        idx.mark_complete(fid, "x")
    pending = idx.add_file("d", "d", "", 1, 1, 1, None)  # envoi interrompu
    idx.add_chunk(pending, Chunk(0, "2", "d0", 1))

    assert idx.chunks_on_webhook("2") == ["b0", "c1", "d0"]
    gone = idx.forget_webhook("2")
    assert [f.uid for f in gone] == ["b", "c"], "fichiers complets touchés"
    assert [f.uid for f in idx.list_files()] == ["a"]
    assert {f.uid for f in idx.incomplete_files()} == {"b", "c", "d"}, "à nettoyer (morceaux restés ailleurs)"
    assert idx.tombstones().keys() == {"b", "c"}, "les autres appareils les oublieront"
    assert [c.webhook_id for c in idx.chunks(files["c"])] == ["1"]
    assert idx.chunks_on_webhook("2") == [] and set(idx.webhook_rows()) == {"1"}
    assert idx.forget_webhook("2") == [], "sans effet la deuxième fois"
    idx.close()


def test_files_per_key_and_adoption(tmp_path):
    idx = Index.open(tmp_path / "index.db")
    for uid, size, key in (("a", 10, "k1"), ("b", 5, "k1"), ("c", 7, "k2"), ("d", 1, None)):
        fid = idx.add_file(uid, uid, "", size, 1, 1, key)
        idx.mark_complete(fid, "sha")
    idx.add_file("en-cours", "x", "", 99, 1, 1, "k1")  # incomplet : pas compté
    assert idx.files_per_key() == {"k1": (2, 15), "k2": (1, 7), None: (1, 1)}
    idx.adopt_unknown_files("k2")
    assert idx.files_per_key() == {"k1": (2, 15), "k2": (2, 8)}
    idx.close()


def test_v1_index_gets_per_file_key(tmp_path):
    """dscs 0.2 : une seule empreinte de clé pour tout l'index (meta). Elle passe sur chaque fichier."""
    path = tmp_path / "index.db"
    db = sqlite3.connect(path)
    for stmt in _V1:
        db.execute(stmt)
    db.execute("PRAGMA user_version = 1")
    db.execute("INSERT INTO meta VALUES ('key_fingerprint', 'abcd')")
    db.execute("INSERT INTO files (uid, name, size, chunks, created, complete) VALUES ('u', 'a', 1, 1, 0, 1)")
    db.commit()
    db.close()
    idx = Index.open(path)
    assert [f.key_id for f in idx.list_files()] == ["abcd"]
    assert idx.get_meta("key_fingerprint") is None
    idx.close()


def test_legacy_index_is_migrated_without_tokens(tmp_path):
    path = tmp_path / "index.db"
    db = sqlite3.connect(path)
    db.executescript(LEGACY_SCHEMA)
    db.execute(
        "INSERT INTO files VALUES (7, 'uid7', 'a.bin', 'x', 20, 'sha', 2, ?, 1)", (time.time(),)
    )
    db.execute("INSERT INTO files VALUES (8, 'uid8', 'b.bin', '', 5, NULL, 1, ?, 0)", (time.time(),))
    db.execute("INSERT INTO chunks VALUES (7, 0, 'https://discord.com/api/webhooks/111/SECRET', 'm0', 10)")
    db.execute("INSERT INTO chunks VALUES (7, 1, 'https://discord.com/api/webhooks/222/SECRET2/', 'm1', 10)")
    db.commit()
    db.close()

    idx = Index.open(path)
    assert idx.conn.execute("PRAGMA user_version").fetchone()[0] == SCHEMA_VERSION
    rec = idx.get_file(7)
    assert rec.name == "a.bin" and rec.folder == "x" and rec.format == 1
    assert [(c.webhook_id, c.message_id) for c in idx.chunks(7)] == [("111", "m0"), ("222", "m1")]
    assert [f.id for f in idx.incomplete_files()] == [8]
    idx.close()
    wal = path.with_name("index.db-wal")
    raw = path.read_bytes() + (wal.read_bytes() if wal.exists() else b"")
    assert b"SECRET" not in raw, "plus aucun token de webhook dans l'index"


def test_newer_index_is_refused(tmp_path):
    path = tmp_path / "index.db"
    db = sqlite3.connect(path)
    db.execute(f"PRAGMA user_version = {SCHEMA_VERSION + 1}")
    db.close()
    with pytest.raises(IndexStateError, match="mets dscs à jour"):
        Index.open(path)


def test_lock_exclusive_refused_when_shared_elsewhere(tmp_path):
    a, b = ProcessLock(tmp_path / "l"), ProcessLock(tmp_path / "l")
    a.acquire_shared()
    b.acquire_shared()
    with pytest.raises(IndexStateError):
        a.upgrade_exclusive()
    b.release()
    a.upgrade_exclusive()
    a.release()
