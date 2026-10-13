"""Vault : façade qui assemble config, trousseau de clés, index et Discord.

Cycle de vie :
    with Vault.open() as vault:          # config + trousseau + index (+ verrou partagé)
        vault.files()                    # lecture locale, sans réseau
        async with vault.connected():    # session HTTP + webhooks
            await vault.upload(item)

Sans trousseau (appareil pas encore dans un coffre), lister, supprimer et nettoyer restent possibles ;
envoyer et télécharger demandent une clé.
"""
from __future__ import annotations

import json
import time
from collections.abc import AsyncIterator, Callable, Iterable
from contextlib import asynccontextmanager
from dataclasses import dataclass, replace
from pathlib import Path

import aiohttp

from .. import transfer
from ..config import Paths, Settings, load_settings, remove_webhook_from_config
from ..crypto import Cipher, Keyring, Member, PassphraseProvider, VaultKey, generate_identity
from ..crypto.device import device_id, verification_code
from ..discord import WebhookClient, WebhookPool, new_session
from ..errors import ConfigError, CryptoError, DiscordError, DSCSError
from ..index import Index, ProcessLock
from ..models import StoredFile, UploadItem, UploadPlan, WebhookInfo, WebhookRef
from ..sync import Invitation, RecoveryKit, SyncEngine, SyncReport
from ..transfer import ProgressCB, ResultCB, UploadResult
from .bench import BenchReport, run_benchmark
from .keystore import NO_KEYRING_HELP, KeyStore


@dataclass(frozen=True)
class GcReport:
    cleaned: int  # envois interrompus entièrement supprimés
    remaining: int  # encore incomplets (morceaux impossibles à supprimer pour l'instant)


@dataclass(frozen=True)
class WebhookRemoval:
    files: list[StoredFile]  # fichiers supprimés du coffre : ils avaient des morceaux sur ce webhook
    leftovers: int  # messages restés sur Discord (webhook déjà supprimé dans Discord, réseau…)
    relocated: bool  # le point d'entrée du coffre était sur ce webhook : recréé sur un autre
    config_updated: bool  # son URL a été retirée de config.toml


CONFIG_WEBHOOKS_KEY = "config_webhooks"  # webhooks de config.toml à la dernière ouverture (identifiants)

ENTRY_HELP = (
    "Le point d'entrée du coffre (superbloc) est sur ce webhook et d'autres appareils l'utilisent : il sera recréé "
    "sur un autre webhook, puis chaque autre appareil devra suivre la nouvelle adresse (dscs device invite ici, "
    "dscs device join là-bas). Relance avec --repair pour le faire."
)


class Vault:
    def __init__(
        self,
        paths: Paths,
        settings: Settings,
        index: Index,
        keyring: Keyring | None,
        lock: ProcessLock | None = None,
        keystore: KeyStore | None = None,
    ):
        self.paths = paths
        self.settings = settings
        self.index = index
        self.keyring = keyring
        self.keystore = keystore
        self._ciphers: dict[str, Cipher] = keyring.ciphers() if keyring else {}
        self._lock = lock
        self._session: aiohttp.ClientSession | None = None
        self._pool: WebhookPool | None = None
        self._engine: SyncEngine | None = None
        self._dirty = False  # modifications locales pas encore publiées sur le coffre partagé
        self.last_sync: SyncReport | None = None
        # avertissements à montrer (synchronisation, webhook changé de salon…) ; l'interface le branche
        self.notify: Callable[[str], None] | None = None
        self.notices: list[str] = []  # avertissements de l'ouverture (écarts entre config.toml et le coffre)

    # ------------------------------------------------------------------ ouverture / fermeture

    @classmethod
    def open(cls, paths: Paths | None = None, passphrase: PassphraseProvider | None = None) -> Vault:
        paths = paths or Paths.from_env()
        settings = load_settings(paths.config_file)
        keystore = KeyStore(paths, passphrase)
        keyring = keystore.keyring()
        lock = ProcessLock(paths.lock_file)
        lock.acquire_shared()
        try:
            index = Index.open(paths.index_file)
        except BaseException:
            lock.release()
            raise
        notices: list[str] = []
        if keyring is not None:
            index.adopt_unknown_files(keyring.current)
            notices = _config_notices(settings, keyring, index)
            # webhooks ajoutés à la main dans config.toml : ils rejoignent le coffre (et seront partagés)
            if keyring.add_webhooks([w.url for w in settings.webhooks]):
                keystore.save(keyring)
        vault = cls(paths, settings, index, keyring, lock, keystore)
        vault.notices = notices
        vault._forget_removed_webhooks()
        return vault

    def close(self) -> None:
        self.index.close()
        if self._lock:
            self._lock.release()

    def __enter__(self) -> Vault:
        return self

    def __exit__(self, *exc) -> None:
        self.close()

    # ------------------------------------------------------------------ clés

    @property
    def has_key(self) -> bool:
        return self.keyring is not None

    @property
    def key_id(self) -> str | None:
        """Empreinte de la clé courante (celle des prochains envois)."""
        return self.keyring.current if self.keyring else None

    def can_read(self, rec: StoredFile) -> bool:
        """Le trousseau de cet appareil contient-il la clé qui a chiffré ce fichier ?"""
        return self.keyring is not None and (rec.key_id or self.keyring.current) in self._ciphers

    def unreadable_count(self) -> int:
        return sum(1 for rec in self.files() if not self.can_read(rec))

    def _require_keyring(self) -> Keyring:
        if self.keyring is None:
            raise ConfigError(f"Aucune clé de coffre sur cet appareil. {NO_KEYRING_HELP}")
        return self.keyring

    # ------------------------------------------------------------------ réseau

    def _webhook_refs(self) -> list[WebhookRef]:
        if self.keyring is not None:
            return [WebhookRef.parse(u) for u in self.keyring.webhooks]
        return list(self.settings.webhooks)

    def _refresh(self) -> None:
        """Après une synchronisation : nouvelles clés, nouveaux webhooks (et webhooks retirés ailleurs)."""
        if self.keyring is not None:
            self._ciphers = self.keyring.ciphers()
            self._forget_removed_webhooks()
        if self._session is not None:
            self._pool = WebhookPool(self._session, self._webhook_refs())

    async def start(self) -> None:
        """Ouvre la session HTTP (sans contacter Discord)."""
        if self._session is None:
            self._session = new_session()
            self._pool = WebhookPool(self._session, self._webhook_refs())
            if self.keyring is not None and self.keystore is not None:
                self._engine = SyncEngine(self.keystore, self.index, self._session, self.settings.chunk_size)

    async def stop(self) -> None:
        if self._session is not None:
            await self._session.close()
        self._session = self._pool = self._engine = None

    @asynccontextmanager
    async def connected(self) -> AsyncIterator[Vault]:
        """Session HTTP + coffre à jour (pull) ; les modifications sont publiées (push) en sortie."""
        await self.start()
        try:
            await self.pull()
            yield self
            await self.flush()
        finally:
            await self.stop()

    # ------------------------------------------------------------------ synchronisation

    def _record(self, report: SyncReport) -> SyncReport:
        self.last_sync = report
        if self.notify is not None:
            for warning in report.warnings:
                self.notify(warning)
        return report

    @property
    def shared(self) -> bool:
        """Le coffre est-il publié sur Discord (superbloc) ?"""
        return self.keyring is not None and self.keyring.superblock is not None

    async def pull(self) -> SyncReport | None:
        """Récupère trousseau et index publiés par les autres appareils (et publie le coffre s'il est neuf)."""
        if self._engine is None or not self.keyring.webhooks:
            return None
        report = SyncReport()
        if not self.shared:
            await self._engine.push(report)  # premier contact : création du superbloc
        else:
            await self._engine.pull(report)
        self._refresh()
        return self._record(report)

    async def flush(self) -> SyncReport | None:
        """Publie les modifications locales (envois, suppressions) s'il y en a."""
        if self._engine is None or not self._dirty:
            return None
        report = await self._engine.push()
        self._dirty = False
        self._refresh()
        return self._record(report)

    async def sync(self, repair: bool = False) -> SyncReport:
        """Synchronisation complète : réception puis publication.

        `repair` : si le superbloc est introuvable, le recréer même si d'autres appareils utilisent le coffre
        (ils devront ensuite recevoir la nouvelle adresse par « dscs device join »). Seul, c'est automatique.
        """
        if self._engine is None:
            raise ConfigError(f"Aucune clé de coffre sur cet appareil. {NO_KEYRING_HELP}")
        self._engine.repair = repair
        report = await self._engine.sync()
        self._dirty = False
        self._refresh()
        return self._record(report)

    @property
    def pool(self) -> WebhookPool:
        if self._pool is None:
            raise RuntimeError("Vault non connecté : utiliser `async with vault.connected()`")
        return self._pool

    # ------------------------------------------------------------------ webhooks

    def _forget_removed_webhooks(self) -> None:
        """Webhooks retirés du coffre (ici ou sur un autre appareil) : plus rien d'eux dans l'index."""
        if self.keyring is None:
            return
        for wid in self.keyring.removed_webhooks.keys() & self.index.webhook_rows().keys():
            if self.index.forget_webhook(wid):
                self._dirty = True  # pierres tombales à publier ; morceaux restants : dscs gc

    def files_on_webhook(self, webhook_id: str) -> list[StoredFile]:
        """Fichiers qui ont au moins un morceau sur ce webhook (ceux que son retrait supprimerait)."""
        ids = {fid for fid, hooks in self.index.webhooks_by_file().items() if webhook_id in hooks}
        return [rec for rec in self.files() if rec.id in ids]

    async def remove_webhook(self, webhook_id: str, repair: bool = False) -> WebhookRemoval:
        """Retire un webhook du coffre, partout : trousseau (donc chez les autres appareils), cache, config.toml.

        Ses morceaux sont perdus : les fichiers qui en avaient sont supprimés du coffre, avec leurs morceaux
        restés sur les autres webhooks. Les messages qu'il portait sont supprimés de Discord s'il existe encore.
        `repair` : s'il porte le point d'entrée du coffre, le recréer ailleurs même si d'autres appareils l'utilisent.
        """
        ring = self._require_keyring()
        engine = self._require_engine()
        sb = await engine.pull()
        self._refresh()
        known = [r.id for r in self._webhook_refs()]
        if webhook_id not in known:
            raise ConfigError(f"Webhook inconnu dans le coffre : {webhook_id}.")
        if len(known) == 1:
            raise ConfigError("C'est le dernier webhook du coffre : ajoute d'abord un autre webhook dans config.toml.")
        entry = ring.superblock is not None and ring.superblock["webhook_id"] == webhook_id
        if entry and not (repair or engine.sole_device()):
            raise ConfigError(ENTRY_HELP)

        client = self.pool.get(webhook_id)  # gardé : ses messages sont supprimés une fois le coffre à jour
        doomed = self.index.chunks_on_webhook(webhook_id)
        if sb is not None and sb.index is not None:
            doomed += [m for w, m in sb.index.parts if w == webhook_id]
        if entry:
            doomed.append(ring.superblock["message_id"])
        ring.remove_webhook(webhook_id)
        self.keystore.save(ring)
        lost = self.index.forget_webhook(webhook_id)
        self._refresh()
        if entry:
            report = SyncReport()
            await engine.relocate(report)
            self._record(report)
        for rec in lost:
            await transfer.purge_file(rec.id, index=self.index, pool=self.pool)  # échec : repris par dscs gc
        await engine.push()
        self._dirty = False
        self._refresh()

        leftovers = await _delete_messages(client, doomed)
        config_updated = remove_webhook_from_config(self.paths.config_file, webhook_id)
        self.settings = replace(self.settings, webhooks=[w for w in self.settings.webhooks if w.id != webhook_id])
        return WebhookRemoval(files=lost, leftovers=leftovers, relocated=entry, config_updated=config_updated)

    def webhook_infos(self) -> list[WebhookInfo]:
        """Webhooks du coffre avec serveur et salon (cache local + noms lisibles de config.toml), sans réseau."""
        rows = self.index.webhook_rows()
        chunks = self.index.chunks_per_webhook()
        infos = []
        for ref in self._webhook_refs():
            name, guild, channel = rows.get(ref.id, (None, None, None))
            infos.append(
                WebhookInfo(
                    id=ref.id,
                    name=name,
                    guild_id=guild,
                    channel_id=channel,
                    server_label=self.settings.server_names.get(guild or ""),
                    channel_label=self.settings.channel_names.get(channel or ""),
                    chunks=chunks.get(ref.id, 0),
                )
            )
        return infos

    async def refresh_webhook_info(self) -> list[WebhookInfo]:
        """Redemande à Discord le nom, le serveur et le salon de chaque webhook (les injoignables gardent le cache).

        Un webhook qui a changé de salon ne retrouve plus les messages restés dans l'ancien : on prévient.
        """
        known = self.index.webhook_rows()
        chunks = self.index.chunks_per_webhook()
        for client in self.pool.all():
            try:
                info = await client.info()
            except Exception:
                continue
            before = known.get(client.id, (None, None, None))[2]
            channel = str(info["channel_id"]) if info.get("channel_id") else None
            if before and channel and channel != before and chunks.get(client.id) and self.notify is not None:
                self.notify(
                    f"Le webhook « {info.get('name') or client.id} » a changé de salon : les morceaux envoyés avant "
                    f"restent dans l'ancien salon ({before}), illisibles tant qu'il n'y est pas remis."
                )
            self.index.update_webhook_info(
                client.id,
                str(info["name"]) if info.get("name") else None,
                str(info["guild_id"]) if info.get("guild_id") else None,
                str(info["channel_id"]) if info.get("channel_id") else None,
            )
        return self.webhook_infos()

    async def webhook_names(self) -> list[str]:
        return [i.display_name for i in await self.refresh_webhook_info()]

    def resolve_webhooks(self, wanted: list[str]) -> list[str]:
        """Identifiants des webhooks désignés par identifiant ou par nom (sans tenir compte de la casse)."""
        infos = self.webhook_infos()
        out: list[str] = []
        for w in wanted:
            matches = [i.id for i in infos if i.id == w] or [
                i.id for i in infos if i.name and i.name.casefold() == w.casefold()
            ]
            if not matches:
                known = ", ".join(f"{i.display_name} ({i.id})" for i in infos) or "aucun"
                raise ConfigError(f"Webhook inconnu : « {w} ». Webhooks du coffre : {known}.")
            if len(matches) > 1:
                raise ConfigError(f"Plusieurs webhooks s'appellent « {w} » : désigne-le par son identifiant.")
            if matches[0] not in out:
                out.append(matches[0])
        return out

    async def benchmark(
        self,
        total: int,
        webhooks: list[str] | None = None,
        per_webhook: int = 1,
        download: bool = False,
        on_progress: ProgressCB | None = None,
    ) -> BenchReport:
        """Mesure le débit avec `total` octets de données aléatoires, supprimées ensuite ; le coffre n'est pas touché.

        `webhooks` : identifiants à utiliser (défaut : tous). Ne demande ni clé ni synchronisation.
        """
        infos = {i.id: i for i in self.webhook_infos()}
        ids = webhooks or list(infos)
        clients = [(self.pool.get(wid), infos[wid]) for wid in ids]
        return await run_benchmark(
            clients, total, self.settings.chunk_size, per_webhook=per_webhook, download=download,
            on_progress=on_progress,
        )

    def file_webhooks(self) -> dict[int, list[str]]:
        return self.index.webhooks_by_file()

    # ------------------------------------------------------------------ lecture (locale)

    def files(self) -> list[StoredFile]:
        return self.index.list_files()

    def file(self, file_id: int) -> StoredFile | None:
        return self.index.get_file(file_id)

    def files_per_key(self) -> dict[str | None, tuple[int, int]]:
        return self.index.files_per_key()

    def interrupted_count(self) -> int:
        return len(self.index.incomplete_files())

    def plan_upload(self, paths: Iterable[Path]) -> UploadPlan:
        return transfer.plan_upload(paths, self.settings.chunk_size)

    # ------------------------------------------------------------------ appareils membres

    @property
    def device_id(self) -> str | None:
        return self.keystore.identity().id if self.keystore and self.keystore.has_identity() else None

    def members(self) -> list[Member]:
        ring = self._require_keyring()
        return sorted(ring.members.values(), key=lambda m: m.added)

    def find_member(self, query: str) -> Member:
        """Un membre désigné par son nom (sans tenir compte de la casse), son identifiant ou son code."""
        digits = "".join(ch for ch in query if ch.isdigit())
        found = [
            m
            for m in self.members()
            if m.id == query or m.name.casefold() == query.casefold()
            or (len(digits) == 20 and verification_code(m.public).replace("-", "") == digits)
        ]
        if not found:
            names = ", ".join(m.name for m in self.members())
            raise ConfigError(f"Aucun appareil « {query} » dans le coffre. Membres : {names}.")
        if len(found) > 1:
            raise ConfigError(
                f"Plusieurs appareils s'appellent « {query} » : désigne-le par son code ou son identifiant."
            )
        return found[0]

    def invitation(self) -> Invitation:
        """Adresse du coffre, à donner à un nouvel appareil (après « dscs device add »)."""
        ring = self._require_keyring()
        if not ring.superblock:
            raise ConfigError("Le coffre n'est pas encore publié sur Discord : lance « dscs sync ».")
        wid = ring.superblock["webhook_id"]
        url = next((u for u in ring.webhooks if WebhookRef.parse(u).id == wid), None)
        if url is None:
            raise ConfigError(f"Le webhook {wid} du superbloc est absent du trousseau.")
        return Invitation(ring.vault_id, url, ring.superblock["message_id"])

    def _require_engine(self) -> SyncEngine:
        if self._engine is None:
            raise RuntimeError("Vault non connecté : utiliser `async with vault.connected()`")
        return self._engine

    async def _publish_keyring(self, ring: Keyring) -> None:
        self.keystore.save(ring)
        await self._require_engine().push()
        self._refresh()

    async def add_device(self, public: bytes, name: str) -> Invitation:
        """Ajoute un appareil au coffre : le trousseau est scellé pour lui et publié."""
        ring = self._require_keyring()
        engine = self._require_engine()
        await engine.pull()
        if device_id(public) in ring.members:
            raise ConfigError("Cet appareil est déjà membre du coffre.")
        ring.add_member(public, name)
        await self._publish_keyring(ring)
        return self.invitation()

    async def revoke_device(self, member: Member) -> VaultKey:
        """Retire un appareil et passe à une nouvelle génération de clé, qu'il ne recevra pas."""
        ring = self._require_keyring()
        if member.id == self.device_id:
            raise ConfigError("Un appareil ne peut pas se retirer lui-même : fais-le depuis un autre appareil membre.")
        await self._require_engine().pull()
        if member.id not in ring.members:
            raise ConfigError(f"« {member.name} » n'est plus membre du coffre.")
        ring.revoke(member.id)
        await self._publish_keyring(ring)
        return ring.current_key()

    async def rotate_key(self) -> VaultKey:
        """Nouvelle génération de clé pour les prochains envois, publiée pour tous les membres."""
        ring = self._require_keyring()
        await self._require_engine().pull()
        key = ring.rotate()
        await self._publish_keyring(ring)
        return key

    async def create_recovery(self) -> RecoveryKit:
        """Crée un appareil « de secours » membre du coffre et renvoie son kit (clé privée + adresse)."""
        ring = self._require_keyring()
        await self._require_engine().pull()
        rescue = generate_identity(f"secours {time.strftime('%Y-%m-%d %H:%M')}")
        ring.add_member(rescue.public, rescue.name, role="recovery")
        await self._publish_keyring(ring)
        return RecoveryKit(self.invitation(), rescue.private.private_bytes_raw())

    # ------------------------------------------------------------------ opérations (réseau)

    async def upload(
        self, item: UploadItem, on_progress: ProgressCB | None = None, webhooks: list[str] | None = None
    ) -> StoredFile:
        """Envoie un fichier ; lève son erreur en cas d'échec. Plusieurs fichiers : upload_many (plus rapide)."""
        [result] = await self.upload_many([item], on_progress=on_progress, webhooks=webhooks)
        if isinstance(result, Exception):
            raise result
        return result

    async def upload_many(
        self,
        items: list[UploadItem],
        on_progress: ProgressCB | None = None,
        on_result: ResultCB | None = None,
        webhooks: list[str] | None = None,
    ) -> list[UploadResult]:
        """Envoie des fichiers en un seul flux : chaque webhook choisi (défaut : tous) enchaîne les morceaux de tous
        les fichiers. Renvoie, dans l'ordre, le fichier stocké ou l'erreur de chacun (voir transfer.upload_files)."""
        ring = self._require_keyring()
        clients = [self.pool.get(w) for w in webhooks] if webhooks else None
        self._dirty = True
        return await transfer.upload_files(
            items,
            index=self.index,
            cipher=self._ciphers[ring.current],
            key_id=ring.current,
            pool=self.pool,
            chunk_size=self.settings.chunk_size,
            on_progress=on_progress,
            on_result=on_result,
            clients=clients,
        )

    async def download(
        self,
        rec: StoredFile,
        dest_dir: Path | None = None,
        on_progress: ProgressCB | None = None,
        keep_folder: bool = True,
    ) -> Path:
        """Télécharge dans `dest_dir` (défaut : download_dir), en recréant le dossier distant si `keep_folder`."""
        ring = self._require_keyring()
        key_id = rec.key_id or ring.current
        cipher = self._ciphers.get(key_id)
        if cipher is None:
            raise CryptoError(
                f"{rec.path} a été chiffré avec une clé absente du trousseau de cet appareil (empreinte {key_id})."
            )
        return await transfer.download_file(
            rec,
            dest_dir or self.settings.download_dir,
            index=self.index,
            cipher=cipher,
            key_id=key_id,
            pool=self.pool,
            on_progress=on_progress,
            keep_folder=keep_folder,
        )

    async def delete(self, rec: StoredFile) -> None:
        self._dirty = True
        await transfer.delete_file(rec, index=self.index, pool=self.pool)

    async def collect_garbage(self) -> GcReport:
        """Supprime de Discord les morceaux des envois interrompus. Exige d'être la seule instance."""
        if self._lock:
            self._lock.upgrade_exclusive()
        try:
            cleaned = 0
            for rec in self.index.incomplete_files():
                if await transfer.purge_file(rec.id, index=self.index, pool=self.pool):
                    cleaned += 1
            return GcReport(cleaned=cleaned, remaining=len(self.index.incomplete_files()))
        finally:
            if self._lock:
                self._lock.downgrade_shared()


async def _delete_messages(client: WebhookClient, message_ids: list[str]) -> int:
    """Supprime des messages d'un webhook retiré du coffre ; renvoie le nombre de ceux restés sur Discord."""
    try:
        await client.info()
    except DiscordError as e:
        if e.status == 404:  # supprimé dans Discord : ses messages restent dans le salon, hors de portée
            return len(message_ids)
    leftovers = 0
    for mid in message_ids:
        try:
            await client.delete_message(mid, missing_ok=True)
        except DSCSError:
            leftovers += 1
    return leftovers


def _config_notices(settings: Settings, keyring: Keyring, index: Index) -> list[str]:
    """Écarts entre config.toml et le coffre. Rien n'est supprimé sans confirmation : on indique la commande."""
    notices = [
        f"Le webhook {ref.id} de config.toml a été retiré du coffre : il est ignoré, retire-le de config.toml."
        for ref in settings.webhooks
        if ref.id in keyring.removed_webhooks
    ]
    current = {ref.id for ref in settings.webhooks}
    previous = set(json.loads(index.get_meta(CONFIG_WEBHOOKS_KEY) or "[]"))
    in_vault = {WebhookRef.parse(u).id for u in keyring.webhooks}
    names = index.webhook_rows()
    gone = sorted((previous - current) & in_vault)  # rappelés à chaque ouverture tant qu'ils sont dans le coffre
    for wid in gone:
        name = names.get(wid, (None, None, None))[0]
        label = f"« {name} » ({wid})" if name else wid
        notices.append(
            f"Le webhook {label} n'est plus dans config.toml mais reste dans le coffre (partagé entre appareils). "
            f"Pour le retirer partout, avec les fichiers qui ont des morceaux dessus : dscs webhooks rm {wid}"
        )
    remembered = json.dumps(sorted(current | set(gone)))
    if index.get_meta(CONFIG_WEBHOOKS_KEY) != remembered:
        index.set_meta(CONFIG_WEBHOOKS_KEY, remembered)
    return notices
