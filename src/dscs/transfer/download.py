"""Téléchargement : récupération des morceaux (avec préchargement), déchiffrement, vérification SHA-256."""
from __future__ import annotations

import asyncio
import hashlib
import logging
import time
from collections import deque
from pathlib import Path

from ..crypto import CHUNK_FORMAT, Cipher, chunk_aad
from ..discord import WebhookPool
from ..errors import CryptoError, DSCSError, IntegrityError
from ..index import Index
from ..models import StoredFile
from ..utils import human, safe_join, unique_path
from .upload import ProgressCB

log = logging.getLogger(__name__)


async def download_file(
    rec: StoredFile,
    dest_dir: Path,
    *,
    index: Index,
    cipher: Cipher,
    key_id: str,
    pool: WebhookPool,
    on_progress: ProgressCB | None = None,
    prefetch: int = 3,
    keep_folder: bool = True,
) -> Path:
    """Reconstitue `rec` sous `dest_dir/<dossier distant>/<nom>` (ou `dest_dir/<nom>` si `keep_folder` est faux),
    sans jamais écraser un fichier existant.

    Écrit dans un `.part`, renommé seulement si le SHA-256 correspond.
    """
    if rec.key_id is not None and rec.key_id != key_id:
        raise CryptoError(
            f"{rec.path} a été chiffré avec une autre clé (empreinte {rec.key_id}) que celle fournie ({key_id}) : "
            "elle n'est pas dans le trousseau de cet appareil. Synchronise (dscs sync) depuis l'appareil qui l'a créée."
        )
    if rec.format > CHUNK_FORMAT:
        raise DSCSError(f"{rec.name} : format de morceaux {rec.format} inconnu, mets dscs à jour")
    chunks = index.chunks(rec.id)
    if len(chunks) != rec.chunks:
        raise IntegrityError(f"Index incomplet pour {rec.name} ({len(chunks)}/{rec.chunks} morceaux)")
    clients = {c.webhook_id: pool.get(c.webhook_id) for c in chunks}  # échoue avant d'écrire quoi que ce soit

    if dest_dir.exists() and not dest_dir.is_dir():
        raise DSCSError(f"La destination n'est pas un dossier : {dest_dir}")
    target = unique_path(safe_join(dest_dir, rec.folder if keep_folder else "", rec.name))
    target.parent.mkdir(parents=True, exist_ok=True)
    part = target.with_name(target.name + ".part")
    log.info("téléchargement de %s (%s, %d morceau(x)) vers %s", rec.path, human(rec.size), rec.chunks, target)
    t0 = time.monotonic()
    sha = hashlib.sha256()
    pending: deque = deque()
    it = iter(chunks)

    def schedule():
        c = next(it, None)
        if c is not None:
            task = asyncio.create_task(clients[c.webhook_id].download_attachment(c.message_id))
            pending.append((c, task))

    try:
        for _ in range(prefetch):
            schedule()
        with part.open("wb") as out:
            while pending:
                c, task = pending.popleft()
                blob = await task
                schedule()
                data = await asyncio.to_thread(cipher.decrypt, blob, chunk_aad(rec.uid, c.idx))
                log.debug("morceau %d/%d de %s : reçu du webhook %s, déchiffré (%s)",
                          c.idx + 1, rec.chunks, rec.name, c.webhook_id, human(len(data)))
                out.write(data)
                sha.update(data)
                if on_progress:
                    on_progress(len(data))
        if sha.hexdigest() != rec.sha256:
            raise IntegrityError(f"Hash différent pour {rec.name} : fichier corrompu")
        part.rename(target)
        elapsed = time.monotonic() - t0
        log.info("✓ %s : SHA-256 vérifié, %s en %.1f s (%s/s)",
                 target.name, human(rec.size), elapsed, human(rec.size / elapsed if elapsed else 0))
    except BaseException as e:
        for _, task in pending:
            task.cancel()
        part.unlink(missing_ok=True)
        log.warning("✗ %s : %s (fichier partiel supprimé)", rec.path, e or type(e).__name__)
        raise
    return target
