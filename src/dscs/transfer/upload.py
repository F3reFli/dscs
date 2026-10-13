"""Envoi de fichiers : lecture -> chiffrement -> envoi en parallèle sur tous les webhooks.

Un seul flux pour tout un envoi, comme « dscs bench » : les morceaux de tous les fichiers passent dans une même file,
et chaque webhook en prend un dès qu'il est libre. Sans ça, un fichier d'un seul morceau n'occuperait qu'un webhook
(toujours le premier) et chaque fin de fichier laisserait les autres à l'arrêt.
"""
from __future__ import annotations

import asyncio
import hashlib
import secrets
from collections.abc import Callable
from dataclasses import dataclass, field

from ..crypto import CHUNK_FORMAT, Cipher, chunk_aad
from ..discord import SentMessage, WebhookClient, WebhookPool
from ..errors import DSCSError
from ..index import Index
from ..models import Chunk, StoredFile, UploadItem, chunk_count
from .purge import purge_file

ProgressCB = Callable[[int], None]
UploadResult = StoredFile | Exception
ResultCB = Callable[[UploadItem, UploadResult], None]


@dataclass
class _Pending:
    """Un fichier en cours d'envoi."""

    item: UploadItem
    fid: int
    chunks: int
    size: int
    sha: hashlib._Hash = field(default_factory=hashlib.sha256)
    uploaded: int = 0  # morceaux sur Discord
    progressed: int = 0  # octets (en clair) déjà comptés dans la progression
    digest: str | None = None  # SHA-256 du fichier, une fois entièrement lu
    error: Exception | None = None
    done: bool = False


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
    """Envoie un fichier (voir upload_files) ; lève son erreur s'il n'a pas pu être envoyé."""
    [result] = await upload_files(
        [item], index=index, cipher=cipher, key_id=key_id, pool=pool, chunk_size=chunk_size,
        on_progress=on_progress, clients=clients,
    )
    if isinstance(result, Exception):
        raise result
    return result


async def upload_files(
    items: list[UploadItem],
    *,
    index: Index,
    cipher: Cipher,
    key_id: str,
    pool: WebhookPool,
    chunk_size: int,
    on_progress: ProgressCB | None = None,
    on_result: ResultCB | None = None,
    clients: list[WebhookClient] | None = None,
) -> list[UploadResult]:
    """Envoie des fichiers ; renvoie, dans l'ordre, le fichier stocké ou l'erreur de chacun.

    Un fichier en échec (illisible, modifié pendant l'envoi, refusé par Discord) n'arrête pas les autres : ses
    morceaux déjà envoyés sont supprimés. Chaque morceau est inscrit dans l'index dès qu'il est sur Discord : si le
    processus meurt, l'envoi reste « incomplet » dans l'index et `dscs gc` saura le nettoyer. En cas d'annulation,
    les fichiers terminés sont gardés, les autres supprimés.
    `clients` : webhooks à utiliser (défaut : tous ceux du coffre), un envoi à la fois par webhook.
    `on_progress` : octets traités, y compris ceux d'un fichier abandonné (la somme atteint le total).
    """
    hooks = clients or pool.all()
    for h in hooks:
        index.register_webhook(h.id)
    results: list[UploadResult | None] = [None] * len(items)
    started: list[_Pending] = []
    queue: asyncio.Queue = asyncio.Queue(maxsize=len(hooks) * 2)

    def progress(p: _Pending, n: int) -> None:
        p.progressed += n
        if on_progress and n:
            on_progress(n)

    def settle(pos: int, p: _Pending | None, outcome: UploadResult) -> None:
        results[pos] = outcome
        if p is not None and isinstance(outcome, Exception):
            progress(p, max(0, p.item.size - p.progressed))  # la barre avance quand même jusqu'au total
        elif p is None and on_progress and items[pos].size:
            on_progress(items[pos].size)
        if on_result:
            on_result(items[pos], outcome)

    def fail(pos: int, p: _Pending, e: Exception) -> None:
        if p.error is None and not p.done:
            p.error = e
            settle(pos, p, e)

    def finish_if_complete(pos: int, p: _Pending) -> None:
        if p.done or p.error or p.digest is None or p.uploaded < p.chunks:
            return
        index.mark_complete(p.fid, p.digest)
        rec = index.get_file(p.fid)
        assert rec is not None
        p.done = True
        progress(p, max(0, p.size - p.progressed))  # fichier vide : aucun octet compté en route
        settle(pos, p, rec)

    async def read_file(pos: int, item: UploadItem) -> None:
        path = item.path
        size = path.stat().st_size  # relu : le fichier a pu changer depuis le plan
        n = chunk_count(size, chunk_size)
        uid = secrets.token_hex(16)
        p = _Pending(item, index.add_file(uid, path.name, item.folder, size, n, CHUNK_FORMAT, key_id), n, size)
        started.append(p)
        try:
            read = 0
            with path.open("rb") as f:
                for i in range(n):
                    if p.error:
                        return
                    data = await asyncio.to_thread(f.read, chunk_size)
                    read += len(data)
                    p.sha.update(data)
                    blob = await asyncio.to_thread(cipher.encrypt, data, chunk_aad(uid, i))
                    await queue.put((pos, p, i, blob, len(data)))
                if read != size or await asyncio.to_thread(f.read, 1):
                    raise DSCSError(f"{path.name} a été modifié pendant l'envoi : recommence")
            p.digest = p.sha.hexdigest()
            finish_if_complete(pos, p)
        except (DSCSError, OSError) as e:
            fail(pos, p, e)

    async def producer() -> None:
        for pos, item in enumerate(items):
            try:
                await read_file(pos, item)
            except OSError as e:  # illisible ou disparu avant d'être inscrit dans l'index
                settle(pos, None, e)
        for _ in hooks:
            await queue.put(None)

    async def worker(hook: WebhookClient) -> None:
        channel_known = False
        while (job := await queue.get()) is not None:
            pos, p, i, blob, plain = job
            if p.error:
                continue
            try:
                msg = await _send(hook, blob, index, p.fid, i)
            except (DSCSError, OSError) as e:
                fail(pos, p, e)
                continue
            p.uploaded += 1
            if not channel_known and msg.channel_id:
                index.register_webhook(hook.id, msg.channel_id)
                channel_known = True
            progress(p, plain)
            finish_if_complete(pos, p)

    try:
        async with asyncio.TaskGroup() as tg:
            tg.create_task(producer())
            for h in hooks:
                tg.create_task(worker(h))
    except BaseException as e:
        for p in started:
            if not p.done:
                await purge_file(p.fid, index=index, pool=pool)
        if isinstance(e, BaseExceptionGroup) and isinstance(e.exceptions[0], Exception):
            raise e.exceptions[0] from None  # l'erreur utile, pas le groupe du TaskGroup
        raise
    for p in started:
        if p.error is not None:
            await purge_file(p.fid, index=index, pool=pool)  # échec : repris par dscs gc
    return [r for r in results if r is not None]


async def _send(hook: WebhookClient, blob: bytes, index: Index, file_id: int, idx: int) -> SentMessage:
    """Envoie un morceau (nom aléatoire : ni nom ni extension ne fuitent) et l'inscrit dans l'index."""

    def record(msg: SentMessage) -> None:
        index.add_chunk(file_id, Chunk(idx, hook.id, msg.id, len(blob)))

    task = asyncio.create_task(hook.upload(blob, f"{secrets.token_hex(8)}.bin"))
    try:
        msg = await asyncio.shield(task)
    except asyncio.CancelledError:
        # l'envoi en cours peut quand même aboutir côté Discord : on attend sa fin
        # et on l'enregistre, pour que le nettoyage puisse le supprimer
        try:
            record(await asyncio.wait_for(task, 120))
        except Exception:
            pass
        raise
    record(msg)
    return msg
