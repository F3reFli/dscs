"""Mesure du débit vers Discord (« dscs bench ») : données aléatoires, rien n'entre dans le coffre.

Même schéma que l'envoi d'un fichier : les morceaux sont répartis entre les webhooks, chacun envoyant un morceau à
la fois (ou `per_webhook`). On mesure le débit de chaque webhook et le total, puis tous les messages de test sont
supprimés, même en cas d'erreur ou d'interruption.
"""
from __future__ import annotations

import asyncio
import os
import secrets
import time
from collections.abc import Callable
from dataclasses import dataclass, field

from ..discord import WebhookClient
from ..errors import DSCSError
from ..models import WebhookInfo


@dataclass
class WebhookBench:
    info: WebhookInfo
    chunks: int = 0
    sent: int = 0  # octets envoyés
    up_seconds: float = 0.0  # du premier envoi à la dernière réponse
    received: int = 0  # octets relus (--download)
    down_seconds: float = 0.0
    throttled: int = 0  # réponses 429 de Discord
    waited: float = 0.0  # secondes d'attente imposées par Discord
    channel_id: str | None = None  # salon réellement utilisé (réponse de Discord)

    @property
    def up_rate(self) -> float:
        return self.sent / self.up_seconds if self.up_seconds else 0.0

    @property
    def down_rate(self) -> float:
        return self.received / self.down_seconds if self.down_seconds else 0.0


@dataclass
class BenchReport:
    webhooks: list[WebhookBench]
    chunk_size: int
    per_webhook: int
    sent: int = 0
    up_seconds: float = 0.0
    received: int = 0
    down_seconds: float = 0.0
    deleted: int = 0
    leftovers: int = 0  # messages de test qu'on n'a pas pu supprimer
    errors: list[str] = field(default_factory=list)

    @property
    def up_rate(self) -> float:
        return self.sent / self.up_seconds if self.up_seconds else 0.0

    @property
    def down_rate(self) -> float:
        return self.received / self.down_seconds if self.down_seconds else 0.0

    def shared_channels(self) -> list[list[WebhookBench]]:
        """Webhooks du même salon : ils partagent la limite de messages du salon."""
        groups: dict[str, list[WebhookBench]] = {}
        for w in self.webhooks:
            channel = w.channel_id or w.info.channel_id
            if channel:
                groups.setdefault(channel, []).append(w)
        return [g for g in groups.values() if len(g) > 1]


async def _run(jobs: list[tuple[WebhookBench, WebhookClient]], per_webhook: int, work) -> None:
    """Lance `per_webhook` tâches par webhook ; la première erreur arrête les autres et remonte telle quelle."""
    try:
        async with asyncio.TaskGroup() as group:
            for stat, client in jobs:
                for _ in range(per_webhook):
                    group.create_task(work(stat, client))
    except BaseExceptionGroup as group_error:
        first: BaseException = group_error
        while isinstance(first, BaseExceptionGroup):
            first = first.exceptions[0]
        raise first from None


async def run_benchmark(
    clients: list[tuple[WebhookClient, WebhookInfo]],
    total: int,
    chunk_size: int,
    per_webhook: int = 1,
    download: bool = False,
    on_progress: Callable[[int], None] | None = None,
) -> BenchReport:
    report = BenchReport([WebhookBench(info) for _, info in clients], chunk_size, per_webhook)
    jobs = list(zip(report.webhooks, [c for c, _ in clients], strict=True))
    for _, client in jobs:  # freinages comptés à partir de maintenant
        client.throttle.hits, client.throttle.waited = 0, 0.0
    sizes: asyncio.Queue[int] = asyncio.Queue()
    for start in range(0, total, chunk_size):
        sizes.put_nowait(min(chunk_size, total - start))
    sent: list[tuple[WebhookBench, WebhookClient, str, int]] = []  # pour relire puis supprimer

    def timed(stat: WebhookBench, attr: str, t0: float) -> None:
        setattr(stat, attr, max(getattr(stat, attr), time.monotonic() - t0))

    try:
        t0 = time.monotonic()

        async def upload(stat: WebhookBench, client: WebhookClient) -> None:
            while not sizes.empty():
                size = sizes.get_nowait()
                blob = await asyncio.to_thread(os.urandom, size)  # indiscernable d'un morceau chiffré
                msg = await client.upload(blob, f"{secrets.token_hex(8)}.bin")
                sent.append((stat, client, msg.id, size))
                stat.chunks += 1
                stat.sent += size
                stat.channel_id = stat.channel_id or msg.channel_id
                timed(stat, "up_seconds", t0)
                if on_progress:
                    on_progress(size)

        await _run(jobs, per_webhook, upload)
        report.sent = sum(w.sent for w in report.webhooks)
        report.up_seconds = time.monotonic() - t0

        if download:
            pending: asyncio.Queue = asyncio.Queue()
            for item in sent:
                pending.put_nowait(item)
            t1 = time.monotonic()

            async def read_back(_stat: WebhookBench, _client: WebhookClient) -> None:
                while not pending.empty():
                    stat, client, message_id, size = pending.get_nowait()
                    data = await client.download_attachment(message_id)
                    if len(data) != size:
                        raise DSCSError(f"Données relues incomplètes ({len(data)} / {size} octets).")
                    stat.received += size
                    timed(stat, "down_seconds", t1)

            await _run(jobs, per_webhook, read_back)
            report.received = sum(w.received for w in report.webhooks)
            report.down_seconds = time.monotonic() - t1
    finally:
        for stat, client in jobs:
            stat.throttled, stat.waited = client.throttle.hits, client.throttle.waited
        await _cleanup(sent, report)
    return report


async def _cleanup(sent: list[tuple[WebhookBench, WebhookClient, str, int]], report: BenchReport) -> None:
    """Supprime les messages de test, webhook par webhook en parallèle ; compte ceux qui restent."""
    by_client: dict[str, list[tuple[WebhookClient, str]]] = {}
    for _, client, message_id, _ in sent:
        by_client.setdefault(client.id, []).append((client, message_id))

    async def delete_all(items: list[tuple[WebhookClient, str]]) -> None:
        for client, message_id in items:
            try:
                await client.delete_message(message_id, missing_ok=True)
                report.deleted += 1
            except (DSCSError, OSError) as e:
                report.leftovers += 1
                report.errors.append(str(e))

    await asyncio.gather(*(delete_all(items) for items in by_client.values()))
