"""Trousseau d'un coffre : tout ce qu'un appareil membre doit savoir pour l'utiliser.

- toutes les générations de clés de coffre, et la génération courante (celle des nouveaux envois) ;
- les appareils membres (clés publiques) et les appareils révoqués ;
- les URLs des webhooks (secrètes : elles donnent le droit d'écrire et de supprimer), et ceux retirés du coffre ;
- l'adresse du superbloc (message Discord qui sert de point d'entrée du coffre).

Le trousseau n'existe jamais en clair, ni sur disque ni sur Discord : il est scellé (crypto.sealing) pour chaque
appareil membre. Une rotation ajoute une clé sans retirer les anciennes : les fichiers déjà envoyés restent lisibles.
Deux trousseaux du même coffre se fusionnent sans conflit (union des clés, des membres, des révocations,
des webhooks ; un webhook retiré l'est partout, comme un appareil révoqué).
"""
from __future__ import annotations

import base64
import hashlib
import json
import secrets
import time
from dataclasses import dataclass, field

from ..errors import CryptoError
from ..models import WebhookRef
from .cipher import Cipher
from .device import DeviceIdentity, device_id
from .keys import fingerprint, generate_key
from .sealing import open_sealed, seal

FORMAT = "dscs-keyring"
VERSION = 1


@dataclass(frozen=True)
class VaultKey:
    key: bytes
    created: float

    @property
    def id(self) -> str:
        return fingerprint(self.key)


@dataclass(frozen=True)
class Member:
    public: bytes
    name: str
    added: float
    role: str = "device"  # "device" : appareil utilisé ; "recovery" : appareil de secours (kit hors ligne)

    @property
    def id(self) -> str:
        return device_id(self.public)


@dataclass
class Keyring:
    vault_id: str
    keys: dict[str, VaultKey]
    current: str
    members: dict[str, Member]
    revision: int = 1
    webhooks: list[str] = field(default_factory=list)
    superblock: dict[str, str] | None = None  # {"webhook_id": …, "message_id": …, "moved": date si recréé}
    revoked: set[str] = field(default_factory=set)  # identifiants d'appareils retirés du coffre
    removed_webhooks: dict[str, float] = field(default_factory=dict)  # webhooks retirés du coffre : id -> date
    extra: dict = field(default_factory=dict)  # champs des versions futures, conservés tels quels

    # ------------------------------------------------------------------ création / évolution

    @classmethod
    def create(cls, owner: DeviceIdentity, key: bytes | None = None, webhooks: list[str] | None = None) -> Keyring:
        vk = VaultKey(key or generate_key(), time.time())
        me = Member(owner.public, owner.name, time.time())
        return cls(
            vault_id=secrets.token_hex(8),
            keys={vk.id: vk},
            current=vk.id,
            members={me.id: me},
            webhooks=list(webhooks or []),
        )

    def current_key(self) -> VaultKey:
        return self.keys[self.current]

    def rotate(self) -> VaultKey:
        vk = VaultKey(generate_key(), time.time())
        self.keys[vk.id] = vk
        self.current = vk.id
        self.revision += 1
        return vk

    def add_member(self, public: bytes, name: str, role: str = "device") -> Member:
        member = Member(public, name, time.time(), role)
        if member.id in self.revoked:
            raise CryptoError("Cet appareil a été révoqué : il ne peut pas être ajouté de nouveau.")
        self.members[member.id] = member
        self.revision += 1
        return member

    def revoke(self, member_id: str) -> Member:
        """Retire un appareil et passe à une nouvelle génération de clé (qu'il ne recevra jamais)."""
        member = self.members.pop(member_id)
        self.revoked.add(member_id)
        self.rotate()
        return member

    def add_webhooks(self, urls: list[str]) -> bool:
        """Ajoute des webhooks, sauf ceux retirés du coffre (ils ne reviennent pas). Renvoie True si ajout."""
        new = [u for u in urls if u not in self.webhooks and WebhookRef.parse(u).id not in self.removed_webhooks]
        self.webhooks.extend(new)
        if new:
            self.revision += 1
        return bool(new)

    def remove_webhook(self, webhook_id: str) -> bool:
        """Retire un webhook du coffre, définitivement : la fusion avec un trousseau qui l'a encore ne le rend pas."""
        if webhook_id in self.removed_webhooks and not self._has_webhook(webhook_id):
            return False
        self.removed_webhooks.setdefault(webhook_id, time.time())
        self.webhooks = [u for u in self.webhooks if WebhookRef.parse(u).id != webhook_id]
        self.revision += 1
        return True

    def _has_webhook(self, webhook_id: str) -> bool:
        return any(WebhookRef.parse(u).id == webhook_id for u in self.webhooks)

    def merge(self, other: Keyring) -> bool:
        """Fusionne un autre état du même trousseau. Renvoie True si celui-ci a changé.

        Union des clés, des membres et des révocations (une révocation l'emporte), union des webhooks (un webhook
        retiré l'emporte) ;
        la clé courante est la plus récente. Le résultat ne dépend pas de l'ordre des fusions.
        """
        if other.vault_id != self.vault_id:
            raise CryptoError("Impossible de fusionner deux trousseaux de coffres différents.")
        before = self._content()
        self.keys.update(other.keys)
        self.revoked |= other.revoked
        for mid, member in other.members.items():
            self.members.setdefault(mid, member)
        for mid in self.revoked:
            self.members.pop(mid, None)
        for wid, when in other.removed_webhooks.items():
            self.removed_webhooks[wid] = min(when, self.removed_webhooks.get(wid, when))
        self.webhooks = [
            u for u in self.webhooks + [u for u in other.webhooks if u not in self.webhooks]
            if WebhookRef.parse(u).id not in self.removed_webhooks
        ]
        self.superblock = newest_superblock(self.superblock, other.superblock)
        self.current = max(self.keys.values(), key=lambda k: (k.created, k.id)).id
        changed = self._content() != before
        self.revision = max(self.revision, other.revision) + (1 if changed else 0)
        return changed

    def same_content(self, other: Keyring) -> bool:
        return self._content() == other._content()

    def content_digest(self) -> str:
        return hashlib.sha256(self._content().encode()).hexdigest()[:32]

    def generations(self) -> list[VaultKey]:
        """Clés de la plus ancienne à la plus récente (génération 1, 2, …)."""
        return sorted(self.keys.values(), key=lambda k: (k.created, k.id))

    def generation_of(self, key_id: str) -> int | None:
        for n, vk in enumerate(self.generations(), 1):
            if vk.id == key_id:
                return n
        return None

    def ciphers(self) -> dict[str, Cipher]:
        return {kid: Cipher(vk.key) for kid, vk in self.keys.items()}

    def is_member(self, public: bytes) -> bool:
        return device_id(public) in self.members

    # ------------------------------------------------------------------ sérialisation

    def _doc(self) -> dict:
        return {
            **self.extra,
            "format": FORMAT,
            "version": VERSION,
            "vault_id": self.vault_id,
            "revision": self.revision,
            "current": self.current,
            "keys": [{"key": k.key.hex(), "created": k.created} for k in self.generations()],
            "members": [
                {"public": base64.b64encode(m.public).decode(), "name": m.name, "added": m.added}
                | ({"role": m.role} if m.role != "device" else {})
                for m in sorted(self.members.values(), key=lambda m: m.id)
            ],
            "webhooks": self.webhooks,
            "superblock": self.superblock,
            "revoked": sorted(self.revoked),
        } | ({"removed_webhooks": dict(sorted(self.removed_webhooks.items()))} if self.removed_webhooks else {})

    def _content(self) -> str:
        doc = self._doc()
        doc.pop("revision")
        return json.dumps(doc, sort_keys=True)

    def to_bytes(self) -> bytes:
        return json.dumps(self._doc()).encode()

    @classmethod
    def from_bytes(cls, data: bytes) -> Keyring:
        known = {
            "format", "version", "vault_id", "revision", "current", "keys", "members", "webhooks", "superblock",
            "revoked", "removed_webhooks",
        }
        try:
            doc = json.loads(data)
            if doc.get("format") != FORMAT:
                raise ValueError
            if doc.get("version", 0) > VERSION:
                raise CryptoError("Trousseau créé par une version plus récente de dscs : mets dscs à jour.")
            keys = [VaultKey(bytes.fromhex(k["key"]), float(k["created"])) for k in doc["keys"]]
            members = [
                Member(base64.b64decode(m["public"]), str(m["name"]), float(m["added"]), str(m.get("role", "device")))
                for m in doc["members"]
            ]
            superblock = doc.get("superblock")
            ring = cls(
                vault_id=str(doc["vault_id"]),
                keys={k.id: k for k in keys},
                current=str(doc["current"]),
                members={m.id: m for m in members},
                revision=int(doc["revision"]),
                webhooks=[str(u) for u in doc.get("webhooks") or []],
                superblock={str(k): str(v) for k, v in superblock.items()} if superblock else None,
                revoked={str(r) for r in doc.get("revoked") or []},
                removed_webhooks={str(k): float(v) for k, v in (doc.get("removed_webhooks") or {}).items()},
                extra={k: v for k, v in doc.items() if k not in known},
            )
        except CryptoError:
            raise
        except (ValueError, KeyError, TypeError, AttributeError):
            raise CryptoError("Trousseau illisible (format invalide).") from None
        if ring.current not in ring.keys:
            raise CryptoError("Trousseau incohérent : la clé courante est absente.")
        return ring


def newest_superblock(a: dict[str, str] | None, b: dict[str, str] | None) -> dict[str, str] | None:
    """Adresse du superbloc la plus récente : un superbloc recréé (« moved ») remplace l'ancien chez tous."""
    if not a or not b:
        return a or b

    def rank(addr: dict[str, str]) -> tuple[float, int]:
        return float(addr.get("moved") or 0), int(addr.get("message_id") or 0)

    return a if rank(a) >= rank(b) else b


def _context(vault_id: str | None) -> bytes:
    return b"dscs:keyring:v1:" + (vault_id or "").encode()


def seal_keyring(keyring: Keyring, sender: DeviceIdentity, recipient_public: bytes) -> bytes:
    """Scelle le trousseau pour un appareil. L'identifiant du coffre est mis en clair devant, pour le contexte."""
    head = keyring.vault_id.encode() + b"\n"
    return head + seal(sender.private, recipient_public, keyring.to_bytes(), _context(keyring.vault_id))


def open_keyring(blob: bytes, recipient: DeviceIdentity) -> tuple[bytes, Keyring]:
    """Renvoie (clé publique de l'appareil qui l'a scellé, trousseau). La confiance envers l'expéditeur
    est vérifiée par l'appelant (membre connu, ou code de vérification comparé lors d'un appairage)."""
    vault_id, sep, sealed = blob.partition(b"\n")
    if not sep:
        raise CryptoError("Trousseau scellé invalide.")
    sender, data = open_sealed(recipient.private, sealed, _context(vault_id.decode(errors="replace")))
    ring = Keyring.from_bytes(data)
    if ring.vault_id.encode() != vault_id:
        raise CryptoError("Trousseau incohérent : identifiant de coffre différent.")
    if not ring.is_member(recipient.public):
        raise CryptoError("Ce trousseau ne compte pas cet appareil parmi ses membres.")
    return sender, ring
