"""Première configuration (`dscs init`)."""
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

from ..config import Paths, save_settings
from ..crypto import DeviceIdentity, Keyring, PassphraseProvider
from ..discord import WebhookClient, new_session
from ..models import WebhookRef
from .keystore import KeyStore, default_device_name


@dataclass(frozen=True)
class InitResult:
    config_file: Path
    device_file: Path
    keyring_file: Path
    index_file: Path
    identity: DeviceIdentity
    keyring: Keyring
    keyring_created: bool  # False : trousseau existant conservé
    migrated_legacy_key: bool  # l'ancienne clé unique (dscs ≤ 0.2) a été transférée dans le trousseau


def initialize(
    paths: Paths,
    webhook_urls: list[str],
    *,
    device_name: str | None = None,
    new_passphrase: str | None = None,
    passphrase: PassphraseProvider | None = None,
) -> InitResult:
    """Écrit config.toml ; crée l'identité de l'appareil (protégée par `new_passphrase` si donnée) et le
    trousseau du coffre s'ils n'existent pas. Un trousseau existant n'est jamais remplacé ; `passphrase`
    sert à l'ouvrir si l'appareil est déjà protégé."""
    refs = [WebhookRef.parse(u) for u in webhook_urls]  # valide avant d'écrire quoi que ce soit
    save_settings(paths.config_file, [r.url for r in refs])
    store = KeyStore(paths, passphrase)
    name = device_name or default_device_name()
    if not store.has_identity() and not store.has_keyring():
        store.create_identity(name, new_passphrase)
    existing = store.keyring()  # trousseau existant, ou ancienne clé transférée dans un trousseau neuf
    ring = existing or store.create_vault(name, new_passphrase, [r.url for r in refs])
    return InitResult(
        config_file=paths.config_file,
        device_file=paths.device_file,
        keyring_file=paths.keyring_file,
        index_file=paths.index_file,
        identity=store.identity(),
        keyring=ring,
        keyring_created=existing is None or store.migrated_legacy_key,
        migrated_legacy_key=store.migrated_legacy_key,
    )


async def probe_webhook(url: str) -> str | None:
    """Nom du webhook si Discord le reconnaît, None sinon."""
    try:
        ref = WebhookRef.parse(url)
    except Exception:
        return None
    async with new_session() as session:
        try:
            info = await WebhookClient(session, ref).info()
        except Exception:
            return None
    return str(info.get("name") or "webhook")
