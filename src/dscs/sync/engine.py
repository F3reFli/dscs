"""Synchronisation du coffre entre appareils, via le superbloc.

- pull : fusionne les trousseaux scellés pour cet appareil (seulement ceux d'appareils de confiance), puis le
  dernier instantané d'index s'il est plus récent que ce qu'on connaît ;
- push : publie l'état local (fusionné avec le distant) et, si le trousseau a changé, le re-scelle pour chaque
  membre. Concurrence optimiste : si le superbloc a bougé pendant la préparation, on recommence.

Confiance : un trousseau n'est accepté que s'il a été scellé par un membre connu (ou par un membre introduit par
un membre connu, de proche en proche). Un appareil révoqué ne peut donc ni se ré-ajouter ni injecter de clé.
"""
from __future__ import annotations

import secrets
import time
from dataclasses import dataclass, field
from typing import Protocol

import aiohttp

from ..crypto import Cipher, DeviceIdentity, Keyring, open_keyring, seal_keyring
from ..crypto.device import device_id
from ..discord import WebhookPool
from ..errors import ConfigError, CryptoError, DiscordError, DSCSError
from ..index import Index
from ..models import WebhookRef
from .snapshot import open_state, seal_state, state_digest
from .superblock import IndexPointer, Superblock, random_filename

MAX_ATTEMPTS = 4
SEQ_KEY = "sync_seq"
PUSHED_KEY = "keyring_pushed"


class RingStore(Protocol):
    def identity(self) -> DeviceIdentity: ...
    def require_keyring(self) -> Keyring: ...
    def save(self, ring: Keyring) -> None: ...


@dataclass
class SyncReport:
    published: bool = False  # superbloc créé (premier envoi du coffre)
    relocated: bool = False  # superbloc introuvable, recréé ailleurs
    keyring_updated: bool = False
    files_added: int = 0
    pushed: bool = False
    warnings: list[str] = field(default_factory=list)


class RevokedError(CryptoError):
    """Cet appareil a été retiré du coffre."""


class SuperblockLostError(DSCSError):
    """Le superbloc n'existe plus à son adresse (message supprimé, ou webhook déplacé vers un autre salon)."""


LOST_HELP = (
    "Point d'entrée du coffre (superbloc) introuvable sur Discord : son webhook a été déplacé vers un autre "
    "salon, ou le message a été supprimé. Si le webhook a été déplacé, le remettre dans son ancien salon suffit. "
    "Sinon, sur UN seul appareil : « dscs sync --repair », puis sur chacun des autres : « dscs device join "
    "<invitation> », avec l'invitation affichée par « dscs device invite » sur le premier."
)


def merge_trusted(ring: Keyring, opened: dict[str, Keyring], self_id: str) -> tuple[bool, list[str]]:
    """Fusionne dans `ring` les trousseaux reçus, en n'acceptant que ceux scellés par un appareil de confiance.

    La confiance part des membres de `ring` (et de cet appareil), et s'étend aux membres introduits par un membre
    de confiance. Renvoie (trousseau modifié ?, expéditeurs ignorés).
    """
    pending = dict(opened)
    changed, progress = False, True
    while progress:
        progress = False
        trusted = (set(ring.members) | {self_id}) - ring.revoked
        for sender_id in [s for s in pending if s in trusted]:
            changed |= ring.merge(pending.pop(sender_id))
            progress = True
    return changed, list(pending)


class SyncEngine:
    def __init__(
        self,
        store: RingStore,
        index: Index,
        session: aiohttp.ClientSession,
        chunk_size: int,
        repair: bool = False,
    ):
        self.store = store
        self.index = index
        self.session = session
        self.chunk_size = chunk_size
        # superbloc introuvable : le recréer même si d'autres appareils l'utilisent (« dscs sync --repair »)
        self.repair = repair

    # ------------------------------------------------------------------ outils

    @property
    def ring(self) -> Keyring:
        return self.store.require_keyring()

    def pool(self) -> WebhookPool:
        return WebhookPool(self.session, [WebhookRef.parse(u) for u in self.ring.webhooks])

    def _seq(self) -> int:
        return int(self.index.get_meta(SEQ_KEY) or 0)

    async def read(self) -> Superblock | None:
        addr = self.ring.superblock
        if not addr:
            return None
        try:
            data = await self.pool().get(addr["webhook_id"]).download_attachment(addr["message_id"])
        except DiscordError as e:
            if e.status == 404:  # message supprimé, webhook déplacé (ou supprimé)
                raise SuperblockLostError(LOST_HELP) from None
            raise
        sb = Superblock.from_bytes(data)
        if sb.vault_id != self.ring.vault_id:
            raise CryptoError("Le superbloc appartient à un autre coffre.")
        return sb

    async def _write(self, sb: Superblock) -> None:
        addr = self.ring.superblock
        client = self.pool().get(addr["webhook_id"])
        await client.replace_attachment(addr["message_id"], sb.to_bytes(), random_filename())

    async def _upload_parts(self, blob: bytes) -> list[tuple[str, str]]:
        clients = self.pool().all()
        parts = []
        for n, start in enumerate(range(0, max(len(blob), 1), self.chunk_size)):
            client = clients[n % len(clients)]
            msg = await client.upload(blob[start : start + self.chunk_size], f"{secrets.token_hex(8)}.bin")
            parts.append((client.id, msg.id))
        return parts

    async def _download_parts(self, parts: list[tuple[str, str]]) -> bytes:
        pool = self.pool()
        return b"".join([await pool.get(w).download_attachment(m) for w, m in parts])

    async def _delete_parts(self, parts: list[tuple[str, str]]) -> None:
        pool = self.pool()
        for w, m in parts:
            try:
                await pool.get(w).delete_message(m, missing_ok=True)
            except DSCSError:
                pass  # un instantané orphelin ne gêne personne

    # ------------------------------------------------------------------ création du superbloc

    async def _create_superblock(self, moved: bool) -> str:
        """Poste un superbloc vide sur le premier webhook qui répond ; renvoie l'identifiant de ce webhook."""
        ring = self.ring
        last: DSCSError | None = None
        for client in self.pool().all():
            try:
                msg = await client.upload(Superblock(ring.vault_id).to_bytes(), random_filename())
            except DiscordError as e:
                last = e
                continue
            ring.superblock = {"webhook_id": client.id, "message_id": msg.id}
            if moved:
                ring.superblock["moved"] = repr(time.time())
            ring.revision += 1
            self.store.save(ring)
            return client.id
        raise last or ConfigError("Aucun webhook dans le trousseau du coffre.")

    async def publish(self, report: SyncReport) -> None:
        """Crée le superbloc du coffre s'il n'existe pas encore (premier appareil, ou coffre migré)."""
        if self.ring.superblock:
            return
        await self._create_superblock(moved=False)
        report.published = True

    def sole_device(self) -> bool:
        """Cet appareil est-il le seul à utiliser le coffre ? (les appareils de secours ne synchronisent pas)"""
        active = [m.id for m in self.ring.members.values() if m.role != "recovery"]
        return active == [self.store.identity().id]

    async def relocate(self, report: SyncReport) -> None:
        """Recrée le superbloc à une nouvelle adresse ; le prochain push y publie index et trousseaux.

        Les autres appareils ne la connaissent pas : ils doivent la recevoir par une invitation
        (« dscs device join »). Les kits de secours existants pointent vers l'ancienne adresse.
        """
        await self._create_superblock(moved=True)
        report.relocated = True
        report.warnings.append(
            "Point d'entrée du coffre introuvable sur Discord (webhook déplacé vers un autre salon, ou message "
            "supprimé) : recréé. Les fichiers envoyés avant un déplacement restent dans l'ancien salon : remets-y "
            "le webhook pour les relire."
        )
        if any(m.role == "recovery" for m in self.ring.members.values()):
            report.warnings.append(
                "Tes kits de secours pointent vers l'ancienne adresse : crée-en un nouveau (dscs key recovery), "
                "puis retire l'ancien appareil de secours (dscs device list, puis dscs device revoke)."
            )

    async def _read_or_repair(self, report: SyncReport) -> Superblock | None:
        try:
            return await self.read()
        except SuperblockLostError:
            if not (self.repair or self.sole_device()):
                raise
        await self.relocate(report)
        self.repair = False
        return await self.read()

    # ------------------------------------------------------------------ réception

    def _merge_keyrings(self, sb: Superblock, report: SyncReport) -> None:
        identity = self.store.identity()
        ring = self.ring
        mine = sb.keyrings.get(identity.id, {})
        if not mine:
            if sb.keyrings:
                raise RevokedError(
                    "Cet appareil n'a plus accès à ce coffre : il en a été retiré depuis un autre appareil."
                )
            return
        opened: dict[str, Keyring] = {}
        for sender_id, blob in mine.items():
            try:
                sender, remote = open_keyring(blob, identity)
            except CryptoError as e:
                report.warnings.append(f"trousseau illisible ignoré ({sender_id}) : {e}")
                continue
            if device_id(sender) != sender_id:
                report.warnings.append(f"trousseau ignoré : expéditeur incohérent ({sender_id})")
                continue
            opened[sender_id] = remote
        changed, ignored = merge_trusted(ring, opened, identity.id)
        for sender_id in ignored:
            report.warnings.append(f"trousseau scellé par un appareil inconnu ignoré ({sender_id})")
        if identity.id in ring.revoked or identity.id not in ring.members:
            raise RevokedError("Cet appareil a été retiré de ce coffre depuis un autre appareil.")
        if changed:
            self.store.save(ring)
            report.keyring_updated = True

    async def pull(self, report: SyncReport | None = None) -> Superblock | None:
        report = report or SyncReport()
        sb = await self._read_or_repair(report)
        if sb is None:
            return None
        self._merge_keyrings(sb, report)
        for _ in range(2):
            if sb.index is None or sb.index.seq <= self._seq():
                return sb
            key = self.ring.keys.get(sb.index.key_id)
            if key is None:
                raise CryptoError(
                    "L'index du coffre est chiffré avec une clé que cet appareil n'a pas encore reçue : "
                    "réessaie après une synchronisation de l'appareil qui l'a créée."
                )
            try:
                blob = await self._download_parts(sb.index.parts)
            except DiscordError:
                sb = await self.read()  # un autre appareil vient de publier : on relit
                continue
            state = open_state(blob, Cipher(key.key), self.ring.vault_id, sb.index.seq)
            report.files_added += self.index.import_state(state)
            self.index.set_meta(SEQ_KEY, str(sb.index.seq))
            return sb
        raise DSCSError("Index du coffre momentanément illisible (publication en cours ailleurs) : réessaie.")

    # ------------------------------------------------------------------ publication

    def _keyrings_up_to_date(self, sb: Superblock, ring: Keyring, me: str) -> bool:
        if self.index.get_meta(PUSHED_KEY) != ring.content_digest():
            return False
        if any(rid not in ring.members for rid in sb.keyrings):
            return False
        return all(me in sb.keyrings.get(mid, {}) for mid in ring.members)

    def _reseal(self, sb: Superblock, ring: Keyring, identity: DeviceIdentity) -> dict[str, dict[str, bytes]]:
        out: dict[str, dict[str, bytes]] = {}
        for rid, senders in sb.keyrings.items():
            if rid in ring.members:  # destinataires révoqués ou inconnus : retirés
                out[rid] = {sid: b for sid, b in senders.items() if sid in ring.members and sid != identity.id}
        for mid, member in ring.members.items():
            out.setdefault(mid, {})[identity.id] = seal_keyring(ring, identity, member.public)
        return out

    async def push(self, report: SyncReport | None = None) -> SyncReport:
        report = report or SyncReport()
        await self.publish(report)
        identity = self.store.identity()
        for _ in range(MAX_ATTEMPTS):
            sb = await self.pull(report)
            ring = self.ring
            state = self.index.export_state()
            key = ring.current_key()
            digest = state_digest(state, key.key)
            index_ok = sb.index is not None and sb.index.key_id == key.id and sb.index.digest == digest
            keyrings_ok = self._keyrings_up_to_date(sb, ring, identity.id)
            if index_ok and keyrings_ok:
                return report
            new = Superblock(sb.vault_id, dict(sb.keyrings), sb.index, dict(sb.extra))
            uploaded: list[tuple[str, str]] = []
            if not index_ok:
                seq = max(self._seq(), sb.index.seq if sb.index else 0) + 1
                uploaded = await self._upload_parts(seal_state(state, Cipher(key.key), ring.vault_id, seq))
                new.index = IndexPointer(seq, key.id, digest, uploaded)
            if not keyrings_ok:
                new.keyrings = self._reseal(sb, ring, identity)
            current = await self.read()
            if current is None or current.to_bytes() != sb.to_bytes():
                await self._delete_parts(uploaded)  # un autre appareil a publié entre-temps : on recommence
                continue
            await self._write(new)
            if not index_ok:
                self.index.set_meta(SEQ_KEY, str(new.index.seq))
                if sb.index:
                    await self._delete_parts(sb.index.parts)
            self.index.set_meta(PUSHED_KEY, ring.content_digest())
            report.pushed = True
            return report
        raise DSCSError("Synchronisation impossible : le coffre est modifié en continu ailleurs. Réessaie.")

    async def sync(self) -> SyncReport:
        report = SyncReport()
        if not self.ring.webhooks:
            raise ConfigError("Aucun webhook dans le trousseau du coffre.")
        await self.push(report)
        return report
