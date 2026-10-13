"""Suppression des morceaux sur Discord, tolérante aux échecs (reprise par `dscs gc`)."""
from __future__ import annotations

import logging

from ..discord import WebhookPool
from ..errors import DSCSError
from ..index import Index
from ..models import StoredFile

log = logging.getLogger(__name__)


async def purge_file(file_id: int, *, index: Index, pool: WebhookPool) -> bool:
    """Supprime de Discord les morceaux connus d'un fichier, puis son entrée d'index.

    Chaque morceau supprimé sort de l'index aussitôt. Si un morceau résiste (réseau, webhook
    retiré de la config…), l'entrée reste, marquée incomplète, et la fonction renvoie False :
    rien n'est oublié, `dscs gc` réessaiera.
    """
    ok = True
    chunks = index.chunks(file_id)
    log.debug("suppression de %d morceau(x) du fichier %d", len(chunks), file_id)
    for c in chunks:
        try:
            await pool.get(c.webhook_id).delete_message(c.message_id, missing_ok=True)
        except DSCSError as e:
            log.warning("morceau %d du fichier %d non supprimé (webhook %s) : %s", c.idx, file_id, c.webhook_id, e)
            ok = False
            continue
        index.delete_chunk(file_id, c.idx)
    if ok:
        index.delete_file(file_id)
    else:
        index.mark_incomplete(file_id)
    return ok


async def delete_file(rec: StoredFile, *, index: Index, pool: WebhookPool) -> None:
    """Suppression demandée par l'utilisateur."""
    for c in index.chunks(rec.id):
        pool.get(c.webhook_id)  # webhook absent de la config : on refuse avant de toucher à quoi que ce soit
    index.mark_incomplete(rec.id)  # dès le premier morceau supprimé, le fichier n'est plus entier
    index.add_tombstone(rec.uid)  # les autres appareils l'oublieront à la prochaine synchronisation
    if not await purge_file(rec.id, index=index, pool=pool):
        raise DSCSError(f"Suppression partielle de {rec.name} : relance « dscs gc » pour terminer.")
