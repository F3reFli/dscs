"""Envoi d'un fichier : lecture -> chiffrement -> envoi en parallèle sur tous les webhooks."""
from __future__ import annotations

import asyncio
import hashlib
import secrets
from collections.abc import Callable

from ..crypto import CHUNK_FORMAT, Cipher, chunk_aad
from ..discord import WebhookClient, WebhookPool
from ..errors import DSCSError
from ..index import Index
from ..models import Chunk, StoredFile, UploadItem, chunk_count
from .purge import purge_file

ProgressCB = Callable[[int], None]


async def upload_file(
    item: UploadItem,
    *,
    index: Index,
    cipher: Cipher,
    key_id: str,
    pool: WebhookPool,
    chunk_size: int,
    on_progress: ProgressCB | None = None,
    clients: list[WebhookClient] | None = None,
) -> StoredFile:
    """Envoie un fichier. En cas d'échec ou d'annulation, les morceaux déjà envoyés sont supprimés.

    Chaque morceau est inscrit dans l'index dès qu'il est sur Discord : si le processus meurt,
    l'envoi reste « incomplet » dans l'index et `dscs gc` saura le nettoyer.
    `clients` : webhooks à utiliser (défaut : tous ceux du coffre), un flux d'envoi par webhook.
    """
    hooks = clients or pool.all()
    path = item.path
    size = path.stat().st_size  # relu : le fichier a pu changer depuis le plan
    n = chunk_count(size, chunk_size)
    uid = secrets.token_hex(16)
    for h in hooks:
        index.register_webhook(h.id)
    fid = index.add_file(uid, path.name, item.folder, size, n, CHUNK_FORMAT, key_id)
    sha = hashlib.sha256()
    queue: asyncio.Queue = asyncio.Queue(maxsize=len(hooks) * 2)

    async def producer():
        read = 0
        with path.open("rb") as f:
            for i in range(n):
                data = await asyncio.to_thread(f.read, chunk_size)
                read += len(data)
                sha.update(data)
                blob = await asyncio.to_thread(cipher.encrypt, data, chunk_aad(uid, i))
                await queue.put((i, blob, len(data)))
            if read != size or await asyncio.to_thread(f.read, 1):
                raise DSCSError(f"{path.name} a été modifié pendant l'envoi : recommence")
        for _ in hooks:
            await queue.put(None)

    async def worker(hook: WebhookClient):
        channel_known = False
        while (job := await queue.get()) is not None:
            i, blob, plain = job
            # nom aléatoire : rien ne fuite sur Discord (ni nom, ni extension)
            task = asyncio.create_task(hook.upload(blob, f"{secrets.token_hex(8)}.bin"))
            try:
                msg = await asyncio.shield(task)
            except asyncio.CancelledError:
                # l'envoi en cours peut quand même aboutir côté Discord : on attend sa fin
                # et on l'enregistre, pour que le nettoyage puisse le supprimer
                try:
                    msg = await asyncio.wait_for(task, 120)
                    index.add_chunk(fid, Chunk(i, hook.id, msg.id, len(blob)))
                except Exception:
                    pass
                raise
            index.add_chunk(fid, Chunk(i, hook.id, msg.id, len(blob)))
            if not channel_known and msg.channel_id:
                index.register_webhook(hook.id, msg.channel_id)
                channel_known = True
            if on_progress:
                on_progress(plain)

    try:
        async with asyncio.TaskGroup() as tg:
            tg.create_task(producer())
            for h in hooks:
                tg.create_task(worker(h))
    except BaseException as e:
        await purge_file(fid, index=index, pool=pool)
        if isinstance(e, BaseExceptionGroup) and isinstance(e.exceptions[0], Exception):
            raise e.exceptions[0] from None  # l'erreur utile, pas le groupe du TaskGroup
        raise
    index.mark_complete(fid, sha.hexdigest())
    rec = index.get_file(fid)
    assert rec is not None
    return rec
