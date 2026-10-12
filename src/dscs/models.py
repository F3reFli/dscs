"""Objets du domaine, partagés par toutes les couches. Ne dépend que de errors."""
from __future__ import annotations

import math
import re
from dataclasses import dataclass, field
from pathlib import Path

from .errors import ConfigError

# Accepte aussi http://127.0.0.1/... (faux serveur des tests). Le contrôle « c'est bien Discord »
# est fait à part, au moment de `dscs init` (voir discord.webhook.is_official_url).
_WEBHOOK_PATH_RE = re.compile(r"/api/(?:v\d+/)?webhooks/(\d+)/([\w-]+)/?$")


@dataclass(frozen=True, slots=True, repr=False)
class WebhookRef:
    """Un webhook de la config. `url` contient le token : elle ne quitte jamais config.toml."""

    id: str
    url: str

    @classmethod
    def parse(cls, url: str) -> WebhookRef:
        url = url.strip().rstrip("/")
        m = _WEBHOOK_PATH_RE.search(url)
        if not m or not url.startswith(("https://", "http://")):
            raise ConfigError(f"URL de webhook invalide : {_redact(url)}")
        return cls(id=m.group(1), url=url)

    @property
    def redacted(self) -> str:
        return _redact(self.url)

    def __repr__(self) -> str:
        return f"WebhookRef(id={self.id!r}, url={self.redacted!r})"


def _redact(url: str) -> str:
    return _WEBHOOK_PATH_RE.sub(lambda m: m.group(0).replace(m.group(2), "***"), url)


@dataclass(frozen=True, slots=True)
class WebhookInfo:
    """Ce que Discord dit d'un webhook (nom, identifiants du serveur et du salon), plus les noms lisibles
    donnés dans config.toml : un webhook ne peut pas lire le nom de son serveur ni de son salon."""

    id: str
    name: str | None = None
    guild_id: str | None = None
    channel_id: str | None = None
    server_label: str | None = None
    channel_label: str | None = None
    chunks: int = 0  # morceaux stockés via ce webhook

    @property
    def display_name(self) -> str:
        return self.name or f"webhook {self.id}"

    @property
    def server(self) -> str:
        return self.server_label or self.guild_id or "?"

    @property
    def channel(self) -> str:
        if self.channel_label:
            return self.channel_label if self.channel_label.startswith("#") else f"#{self.channel_label}"
        return self.channel_id or "?"

    @property
    def server_detail(self) -> str:
        """« Perso (4242…) » quand un nom est donné dans config.toml, sinon l'identifiant."""
        return f"{self.server_label} ({self.guild_id})" if self.server_label and self.guild_id else self.server

    @property
    def channel_detail(self) -> str:
        return f"{self.channel} ({self.channel_id})" if self.channel_label and self.channel_id else self.channel

    @property
    def link(self) -> str | None:
        """Lien qui ouvre le salon dans Discord (navigateur ou application)."""
        if self.guild_id and self.channel_id:
            return f"https://discord.com/channels/{self.guild_id}/{self.channel_id}"
        return None


@dataclass(slots=True)
class StoredFile:
    """Un fichier stocké sur Discord (ligne `files` de l'index, envoi terminé)."""

    id: int
    uid: str  # identifiant aléatoire, lié à chaque morceau par l'AAD du chiffrement
    name: str
    folder: str  # dossier « virtuel » côté distant, '' pour la racine
    size: int
    sha256: str | None
    chunks: int
    created: float
    format: int = 1  # version du format des morceaux (voir crypto.cipher)
    key_id: str | None = None  # empreinte de la clé qui l'a chiffré (None : index d'avant la v2, clé actuelle supposée)

    @property
    def path(self) -> str:
        return f"{self.folder}/{self.name}" if self.folder else self.name


@dataclass(frozen=True, slots=True)
class Chunk:
    """Un morceau chiffré = une pièce jointe d'un message Discord."""

    idx: int
    webhook_id: str
    message_id: str
    size: int  # taille chiffrée (nonce + données + tag)


@dataclass(frozen=True, slots=True)
class UploadItem:
    path: Path
    folder: str
    size: int


@dataclass(slots=True)
class UploadPlan:
    """Ce qui partira sur Discord, calculé avant confirmation."""

    items: list[UploadItem] = field(default_factory=list)
    chunk_size: int = 1

    @property
    def total_bytes(self) -> int:
        return sum(i.size for i in self.items)

    @property
    def total_chunks(self) -> int:
        return sum(chunk_count(i.size, self.chunk_size) for i in self.items)


def chunk_count(size: int, chunk_size: int) -> int:
    """Nombre de morceaux pour un fichier. Un fichier vide fait quand même 1 morceau."""
    return max(1, math.ceil(size / chunk_size))
