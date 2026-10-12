"""Les webhooks de la config, prêts à l'emploi et retrouvables par ID."""
from __future__ import annotations

import aiohttp

from ..errors import ConfigError
from ..models import WebhookRef
from .webhook import WebhookClient


class WebhookPool:
    def __init__(self, session: aiohttp.ClientSession, refs: list[WebhookRef]):
        self._clients = {r.id: WebhookClient(session, r) for r in refs}

    def all(self) -> list[WebhookClient]:
        if not self._clients:
            raise ConfigError("Aucun webhook : ajoute l'URL d'un webhook Discord dans config.toml (clé webhooks).")
        return list(self._clients.values())

    def get(self, webhook_id: str) -> WebhookClient:
        try:
            return self._clients[webhook_id]
        except KeyError:
            raise ConfigError(
                f"Le webhook {webhook_id} est inconnu (ni dans le trousseau du coffre, ni dans config.toml) : "
                "ses morceaux sont inaccessibles. Remets son URL dans config.toml (clé webhooks)."
            ) from None
