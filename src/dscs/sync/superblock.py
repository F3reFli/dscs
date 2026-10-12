"""Superbloc : le message Discord fixe qui sert de point d'entrée au coffre.

Son adresse (webhook + identifiant du message) est dans le trousseau, et dans l'invitation d'un nouvel appareil.
Il ne contient rien de lisible sans clé :
- les trousseaux scellés, rangés par destinataire puis par appareil qui les a scellés ;
- où trouver le dernier instantané de l'index (chiffré), son numéro de séquence et son empreinte (HMAC).
"""
from __future__ import annotations

import base64
import json
import secrets
from dataclasses import dataclass, field

from ..errors import CryptoError

FORMAT = "dscs-superblock"
VERSION = 1


def random_filename() -> str:
    """Comme les morceaux : rien dans le nom ne trahit DSCS."""
    return f"{secrets.token_hex(8)}.bin"


@dataclass
class IndexPointer:
    seq: int
    key_id: str  # clé de coffre qui a chiffré l'instantané
    digest: str  # HMAC de l'état en clair : « rien de neuf » se voit sans rien télécharger
    parts: list[tuple[str, str]]  # (webhook_id, message_id) des morceaux de l'instantané


@dataclass
class Superblock:
    vault_id: str
    keyrings: dict[str, dict[str, bytes]] = field(default_factory=dict)  # destinataire -> expéditeur -> scellé
    index: IndexPointer | None = None
    extra: dict = field(default_factory=dict)

    def to_bytes(self) -> bytes:
        doc = {
            **self.extra,
            "format": FORMAT,
            "version": VERSION,
            "vault_id": self.vault_id,
            "keyrings": {
                rid: {sid: base64.b64encode(blob).decode() for sid, blob in sorted(senders.items())}
                for rid, senders in sorted(self.keyrings.items())
            },
            "index": None
            if self.index is None
            else {
                "seq": self.index.seq,
                "key_id": self.index.key_id,
                "digest": self.index.digest,
                "parts": [list(p) for p in self.index.parts],
            },
        }
        return json.dumps(doc, sort_keys=True).encode()

    @classmethod
    def from_bytes(cls, data: bytes) -> Superblock:
        try:
            doc = json.loads(data)
            if doc.get("format") != FORMAT:
                raise ValueError
            if doc.get("version", 0) > VERSION:
                raise CryptoError("Coffre publié par une version plus récente de dscs : mets dscs à jour.")
            idx = doc.get("index")
            return cls(
                vault_id=str(doc["vault_id"]),
                keyrings={
                    str(rid): {str(sid): base64.b64decode(b) for sid, b in senders.items()}
                    for rid, senders in (doc.get("keyrings") or {}).items()
                },
                index=None
                if idx is None
                else IndexPointer(
                    seq=int(idx["seq"]),
                    key_id=str(idx["key_id"]),
                    digest=str(idx["digest"]),
                    parts=[(str(w), str(m)) for w, m in idx["parts"]],
                ),
                extra={k: v for k, v in doc.items() if k not in {"format", "version", "vault_id", "keyrings", "index"}},
            )
        except CryptoError:
            raise
        except (ValueError, KeyError, TypeError, AttributeError):
            raise CryptoError("Superbloc du coffre illisible (format invalide).") from None
