"""Client d'un webhook Discord : envoyer, relire, supprimer ses propres messages."""
from __future__ import annotations

import json
import re
from dataclasses import dataclass

import aiohttp

from ..errors import DiscordError
from ..models import WebhookRef
from .http import request

OFFICIAL_URL_RE = re.compile(r"^https://(?:\w+\.)?discord(?:app)?\.com/api/(?:v\d+/)?webhooks/\d+/[\w-]+/?$")
SUPPRESS_NOTIFICATIONS = 1 << 12  # pas de ping dans le salon


def is_official_url(url: str) -> bool:
    return bool(OFFICIAL_URL_RE.match(url.strip()))


@dataclass(frozen=True, slots=True)
class SentMessage:
    id: str
    channel_id: str | None


class WebhookClient:
    def __init__(self, session: aiohttp.ClientSession, ref: WebhookRef):
        self.session = session
        self.ref = ref

    @property
    def id(self) -> str:
        return self.ref.id

    async def info(self) -> dict:
        return await request(self.session, "GET", self.ref.url)

    async def upload(self, blob: bytes, filename: str) -> SentMessage:
        def build():
            form = aiohttp.FormData()
            payload = {
                "content": "",
                "flags": SUPPRESS_NOTIFICATIONS,
                "attachments": [{"id": 0, "filename": filename}],
            }
            form.add_field("payload_json", json.dumps(payload), content_type="application/json")
            form.add_field("files[0]", blob, filename=filename, content_type="application/octet-stream")
            return {"data": form}

        msg = await request(self.session, "POST", f"{self.ref.url}?wait=true", build=build)
        channel = msg.get("channel_id")
        return SentMessage(id=str(msg["id"]), channel_id=str(channel) if channel else None)

    async def replace_attachment(self, message_id: str, blob: bytes, filename: str) -> None:
        """Remplace la pièce jointe d'un message déjà envoyé par ce webhook (le superbloc du coffre)."""

        def build():
            form = aiohttp.FormData()
            payload = {"attachments": [{"id": 0, "filename": filename}]}
            form.add_field("payload_json", json.dumps(payload), content_type="application/json")
            form.add_field("files[0]", blob, filename=filename, content_type="application/octet-stream")
            return {"data": form}

        await request(self.session, "PATCH", f"{self.ref.url}/messages/{message_id}", build=build)

    async def download_attachment(self, message_id: str) -> bytes:
        # les liens CDN expirent (~24 h) : on redemande toujours le message pour avoir un lien frais
        try:
            msg = await request(self.session, "GET", f"{self.ref.url}/messages/{message_id}")
        except DiscordError as e:
            if e.status == 404:
                raise DiscordError(
                    f"Message {message_id} introuvable sur Discord : supprimé, ou son webhook a été déplacé vers "
                    "un autre salon (un webhook ne retrouve ses messages que dans son salon actuel).",
                    404,
                ) from None
            raise
        atts = msg.get("attachments") or []
        if not atts:
            raise DiscordError(f"Le message {message_id} n'a plus de pièce jointe", 404)
        return await request(self.session, "GET", atts[0]["url"], expect="bytes")

    async def delete_message(self, message_id: str, *, missing_ok: bool = False) -> None:
        try:
            await request(self.session, "DELETE", f"{self.ref.url}/messages/{message_id}")
        except DiscordError as e:
            if not (missing_ok and e.status == 404):  # déjà supprimé à la main : pas grave
                raise
