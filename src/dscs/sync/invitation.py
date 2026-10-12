"""Textes à transmettre entre appareils : invitation (adresse du coffre) et kit de secours.

- L'invitation donne l'adresse du superbloc : l'URL de son webhook (secrète : droit d'écrire et de supprimer) et
  l'identifiant du message. Elle ne contient aucune clé : sans être membre, on n'y lit rien.
- Le kit de secours ajoute la clé privée d'un appareil « de secours », membre du coffre : quiconque le possède peut
  tout lire. À garder hors ligne, comme un mot de passe principal.
"""
from __future__ import annotations

import base64
import json
from dataclasses import dataclass

from ..errors import ConfigError

INVITATION_PREFIX = "dscs-inv1-"
KIT_PREFIX = "dscs-kit1-"


def _encode(prefix: str, doc: dict) -> str:
    return prefix + base64.urlsafe_b64encode(json.dumps(doc, separators=(",", ":")).encode()).decode().rstrip("=")


def _decode(prefix: str, text: str, what: str) -> dict:
    text = "".join(text.split())
    if not text.startswith(prefix):
        raise ConfigError(f"{what} invalide : elle doit commencer par « {prefix} ».")
    body = text[len(prefix) :]
    try:
        doc = json.loads(base64.urlsafe_b64decode(body + "=" * (-len(body) % 4)))
        if not isinstance(doc, dict):
            raise ValueError
        return doc
    except ValueError:
        raise ConfigError(f"{what} illisible (copie incomplète ?).") from None


@dataclass(frozen=True)
class Invitation:
    vault_id: str
    webhook_url: str  # webhook du superbloc
    message_id: str

    def to_text(self) -> str:
        return _encode(INVITATION_PREFIX, {"v": self.vault_id, "w": self.webhook_url, "m": self.message_id})

    @classmethod
    def from_text(cls, text: str) -> Invitation:
        doc = _decode(INVITATION_PREFIX, text, "Invitation")
        try:
            return cls(vault_id=str(doc["v"]), webhook_url=str(doc["w"]), message_id=str(doc["m"]))
        except KeyError:
            raise ConfigError("Invitation incomplète.") from None


@dataclass(frozen=True)
class RecoveryKit:
    invitation: Invitation
    private_key: bytes  # clé privée X25519 de l'appareil de secours

    def to_text(self) -> str:
        inv = self.invitation
        return _encode(
            KIT_PREFIX,
            {
                "v": inv.vault_id,
                "w": inv.webhook_url,
                "m": inv.message_id,
                "k": base64.urlsafe_b64encode(self.private_key).decode(),
            },
        )

    @classmethod
    def from_text(cls, text: str) -> RecoveryKit:
        doc = _decode(KIT_PREFIX, text, "Kit de secours")
        try:
            key = base64.urlsafe_b64decode(doc["k"])
            if len(key) != 32:
                raise ValueError
            return cls(Invitation(str(doc["v"]), str(doc["w"]), str(doc["m"])), key)
        except (KeyError, ValueError):
            raise ConfigError("Kit de secours incomplet ou abîmé.") from None
