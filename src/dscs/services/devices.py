"""Rejoindre un coffre depuis un nouvel appareil : par invitation (appairage) ou par kit de secours.

Appairage :
    nouvel appareil      dscs device init           -> clé publique + code
    appareil membre      dscs device add <clé>      -> compare le code, scelle le trousseau, donne l'invitation
    nouvel appareil      dscs device join <inv.>    -> compare le code de l'appareil qui l'a ajouté, reçoit le trousseau

Rien de secret ne transite : la clé publique et l'invitation ne permettent pas de lire le coffre. La comparaison
des codes, dans les deux sens, empêche qu'un tiers glisse sa propre clé à la place de celle d'un appareil.

Un appareil déjà membre utilise aussi une invitation pour suivre le coffre quand son superbloc a été recréé
ailleurs (« dscs sync --repair ») : voir `relink_vault`.
"""
from __future__ import annotations

from collections.abc import Callable

from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PrivateKey

from ..config import Paths, load_settings, save_settings
from ..crypto import DeviceIdentity, Keyring, Member, open_keyring, verification_code
from ..crypto.device import PassphraseProvider, device_id
from ..discord import WebhookClient, new_session
from ..errors import ConfigError, CryptoError, DiscordError
from ..index import Index
from ..models import WebhookRef
from ..sync import Invitation, RecoveryKit, RevokedError, Superblock, SyncEngine, merge_trusted
from .keystore import KeyStore

STALE_INVITATION = (
    "Cette invitation pointe vers une adresse du coffre qui n'existe plus (superbloc recréé ailleurs, ou webhook "
    "déplacé vers un autre salon) : demande une invitation à jour (« dscs device invite » sur un appareil membre)."
)
STALE_KIT = (
    "Ce kit de secours pointe vers une adresse du coffre qui n'existe plus (superbloc recréé ailleurs, ou webhook "
    "déplacé vers un autre salon) : utilise un kit plus récent. Si le webhook a été déplacé, le remettre dans son "
    "ancien salon rend ce kit de nouveau utilisable."
)


async def _read_superblock(invitation: Invitation, stale_help: str = STALE_INVITATION) -> Superblock:
    async with new_session() as session:
        client = WebhookClient(session, WebhookRef.parse(invitation.webhook_url))
        try:
            data = await client.download_attachment(invitation.message_id)
        except DiscordError as e:
            if e.status == 404:
                raise ConfigError(stale_help) from None
            raise
        sb = Superblock.from_bytes(data)
    if sb.vault_id != invitation.vault_id:
        raise CryptoError("L'invitation ne correspond pas au coffre trouvé à cette adresse.")
    return sb


def _open_mine(sb: Superblock, identity: DeviceIdentity) -> dict[str, tuple[bytes, Keyring]]:
    """Trousseaux scellés pour cet appareil, par appareil expéditeur : {id: (clé publique, trousseau)}."""
    opened = {}
    for sender_id, blob in sb.keyrings.get(identity.id, {}).items():
        try:
            sender, ring = open_keyring(blob, identity)
        except CryptoError:
            continue
        if device_id(sender) == sender_id and ring.vault_id == sb.vault_id:
            opened[sender_id] = (sender, ring)
    return opened


async def relink_vault(
    paths: Paths, invitation: Invitation, passphrase: PassphraseProvider | None = None
) -> tuple[Keyring, bool]:
    """Appareil déjà membre : suit le coffre à la nouvelle adresse donnée par l'invitation.

    Renvoie (trousseau, adresse changée ?).

    Aucun code à comparer : seuls les trousseaux scellés par des membres déjà connus de cet appareil sont
    acceptés (comme à chaque synchronisation), et l'adresse retenue est celle qu'ils contiennent.
    """
    store = KeyStore(paths, passphrase)
    ring = store.require_keyring()
    if invitation.vault_id != ring.vault_id:
        raise ConfigError(
            f"Cette invitation est celle d'un autre coffre ({invitation.vault_id}) ; cet appareil est membre du "
            f"coffre {ring.vault_id}."
        )
    identity = store.identity()
    sb = await _read_superblock(invitation)
    opened = {sid: r for sid, (_, r) in _open_mine(sb, identity).items()}
    if not opened and sb.keyrings:
        raise RevokedError("Cet appareil n'est plus membre de ce coffre : il en a été retiré.")
    before = dict(ring.superblock or {})
    changed, ignored = merge_trusted(ring, opened, identity.id)
    if len(ignored) == len(opened):
        raise CryptoError(
            "Aucun trousseau d'un appareil de confiance à cette adresse : invitation périmée ou falsifiée. "
            "Rien n'a été modifié."
        )
    if identity.id in ring.revoked or identity.id not in ring.members:
        raise RevokedError("Cet appareil a été retiré de ce coffre depuis un autre appareil.")
    if changed:
        store.save(ring)
    return ring, (ring.superblock or {}) != before


def _ensure_config(paths: Paths) -> None:
    if not paths.config_file.exists():
        save_settings(paths.config_file, [])  # les webhooks viennent du trousseau


async def join_vault(
    paths: Paths,
    invitation: Invitation,
    confirm_sender: Callable[[str, str], bool],
    passphrase: PassphraseProvider | None = None,
) -> Keyring:
    """Rejoint un coffre auquel un appareil membre a ajouté celui-ci (« dscs device add »).

    `confirm_sender(nom, code)` doit renvoyer True seulement si ce code est bien celui de l'appareil qui a fait
    l'ajout (affiché par « dscs device show » ou « dscs device add » sur cet appareil-là).
    """
    store = KeyStore(paths, passphrase)
    if store.has_keyring():
        raise ConfigError(f"Cet appareil a déjà un coffre ({paths.keyring_file}).")
    if not store.has_identity():
        raise ConfigError("Cet appareil n'a pas encore d'identité : lance d'abord « dscs device init ».")
    identity = store.identity()
    opened = _open_mine(await _read_superblock(invitation), identity)
    if not opened:
        raise ConfigError(
            "Ce coffre n'a pas encore ajouté cet appareil : sur un appareil membre, lance « dscs device add » "
            "avec la clé publique affichée par « dscs device show », puis réessaie."
        )
    chosen = None
    for sender_id, (sender, ring) in opened.items():
        member = ring.members.get(sender_id)
        if confirm_sender(member.name if member else "appareil inconnu", verification_code(sender)):
            chosen = sender_id
            break
    if chosen is None:
        raise CryptoError("Aucun appareil confirmé : le coffre n'a pas été rejoint.")
    ring = opened.pop(chosen)[1]
    merge_trusted(ring, {sid: r for sid, (_, r) in opened.items()}, identity.id)
    store.save(ring)
    _ensure_config(paths)
    return ring


class _RescueStore:
    """Trousseau de cet appareil, mais scellé et publié au nom de l'appareil de secours (déjà membre, donc
    reconnu par les autres appareils) : c'est lui qui « présente » le nouvel appareil au coffre."""

    def __init__(self, rescue: DeviceIdentity, store: KeyStore):
        self.rescue = rescue
        self.store = store

    def identity(self) -> DeviceIdentity:
        return self.rescue

    def require_keyring(self) -> Keyring:
        return self.store.require_keyring()

    def save(self, ring: Keyring) -> None:
        self.store.save(ring)


async def recover_vault(
    paths: Paths,
    kit: RecoveryKit,
    device_name: str,
    new_passphrase: str | None,
    confirm_members: Callable[[list[Member]], bool],
    passphrase: PassphraseProvider | None = None,
) -> Keyring:
    """Rejoint un coffre avec un kit de secours, sans aucun autre appareil (tous perdus, par exemple).

    Aucun code à comparer ici : `confirm_members` montre la liste des membres pour que l'utilisateur la
    reconnaisse. Le nouvel appareil devient membre ; l'ajout est publié au nom de l'appareil de secours.
    """
    store = KeyStore(paths, passphrase)
    if store.has_keyring():
        raise ConfigError(f"Cet appareil a déjà un coffre ({paths.keyring_file}).")
    rescue = DeviceIdentity("secours", X25519PrivateKey.from_private_bytes(kit.private_key))
    opened = _open_mine(await _read_superblock(kit.invitation, STALE_KIT), rescue)
    # sans appareil à qui comparer un code : on n'accepte qu'un trousseau scellé par un de ses propres membres
    consistent = {sid: ring for sid, (_, ring) in opened.items() if sid in ring.members}
    if not consistent:
        raise CryptoError("Ce kit de secours n'est plus valide (retiré du coffre, ou coffre introuvable).")
    first = next(iter(consistent))
    ring = consistent.pop(first)
    merge_trusted(ring, consistent, rescue.id)
    if not confirm_members(sorted(ring.members.values(), key=lambda m: m.added)):
        raise CryptoError("Récupération annulée.")
    identity = store.identity() if store.has_identity() else store.create_identity(device_name, new_passphrase)
    if identity.id not in ring.members:
        ring.add_member(identity.public, identity.name)
    store.save(ring)
    _ensure_config(paths)
    index = Index.open(paths.index_file)
    try:
        async with new_session() as session:
            chunk_size = load_settings(paths.config_file).chunk_size
            await SyncEngine(_RescueStore(rescue, store), index, session, chunk_size).push()
    finally:
        index.close()
    return store.require_keyring()
