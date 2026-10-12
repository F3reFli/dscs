"""Clés de cet appareil : identité (device.json) et trousseau du coffre scellé pour lui (keyring.sealed).

Rien de secret n'est stocké en clair : la clé privée de l'appareil peut être protégée par une phrase de passe,
et le trousseau (clés de coffre) n'est lisible qu'avec cette clé privée.
"""
from __future__ import annotations

import socket

from ..config import Paths
from ..crypto import (
    DeviceIdentity,
    Keyring,
    PassphraseProvider,
    VaultKey,
    dump_identity,
    generate_identity,
    is_protected,
    load_identity,
    load_legacy_key,
    open_keyring,
    read_public,
    seal_keyring,
)
from ..errors import ConfigError, CryptoError
from ..utils import shred_file, write_private

NO_KEYRING_HELP = "Crée un coffre avec « dscs init », ou rejoins un coffre existant avec « dscs device join »."


def default_device_name() -> str:
    return socket.gethostname() or "appareil"


class KeyStore:
    def __init__(self, paths: Paths, passphrase: PassphraseProvider | None = None):
        self.paths = paths
        self._passphrase = passphrase
        self._identity: DeviceIdentity | None = None
        self._keyring: Keyring | None = None
        self.migrated_legacy_key = False

    # ------------------------------------------------------------------ identité de l'appareil

    def has_identity(self) -> bool:
        return self.paths.device_file.exists()

    def is_protected(self) -> bool:
        return self.has_identity() and is_protected(self.paths.device_file.read_bytes())

    def public_info(self) -> tuple[str, bytes]:
        """Nom et clé publique de l'appareil, sans demander la phrase de passe."""
        if not self.has_identity():
            raise ConfigError(f"Aucune identité d'appareil ({self.paths.device_file}). {NO_KEYRING_HELP}")
        return read_public(self.paths.device_file.read_bytes())

    def identity(self) -> DeviceIdentity:
        if self._identity is None:
            if not self.has_identity():
                raise ConfigError(f"Aucune identité d'appareil ({self.paths.device_file}). {NO_KEYRING_HELP}")
            self._identity = load_identity(self.paths.device_file.read_bytes(), self._passphrase)
        return self._identity

    def create_identity(self, name: str, passphrase: str | None) -> DeviceIdentity:
        if self.has_identity():
            raise ConfigError(f"Cet appareil a déjà une identité ({self.paths.device_file}).")
        identity = generate_identity(name)
        write_private(self.paths.device_file, dump_identity(identity, passphrase))
        self._identity = identity
        return identity

    def set_passphrase(self, passphrase: str | None) -> None:
        """Ajoute, change (passphrase) ou retire (None) la phrase de passe. Demande l'actuelle si besoin."""
        write_private(self.paths.device_file, dump_identity(self.identity(), passphrase))

    # ------------------------------------------------------------------ trousseau

    def has_keyring(self) -> bool:
        return self.paths.keyring_file.exists()

    def keyring(self) -> Keyring | None:
        """Trousseau de cet appareil ; None s'il n'y en a pas (et pas d'ancienne clé à migrer)."""
        if self._keyring is None:
            if self.has_keyring():
                identity = self.identity()
                sender, ring = open_keyring(self.paths.keyring_file.read_bytes(), identity)
                if sender != identity.public:
                    raise CryptoError("Trousseau local scellé par un autre appareil : refusé.")
                self._keyring = ring
            elif self.paths.key_file.exists():
                self._keyring = self._create(default_device_name(), None)
        return self._keyring

    def require_keyring(self) -> Keyring:
        ring = self.keyring()
        if ring is None:
            raise ConfigError(f"Aucune clé de coffre sur cet appareil. {NO_KEYRING_HELP}")
        return ring

    def save(self, ring: Keyring) -> None:
        identity = self.identity()
        write_private(self.paths.keyring_file, seal_keyring(ring, identity, identity.public))
        self._keyring = ring

    def create_vault(self, name: str, passphrase: str | None, webhooks: list[str] | None = None) -> Keyring:
        """Nouveau coffre : identité de l'appareil (si absente) + trousseau avec une clé neuve
        (ou l'ancienne clé unique de dscs ≤ 0.2 si elle existe)."""
        if self.has_keyring():
            raise ConfigError(f"Un trousseau existe déjà sur cet appareil ({self.paths.keyring_file}).")
        return self._create(name, passphrase, webhooks)

    def _create(self, name: str, passphrase: str | None, webhooks: list[str] | None = None) -> Keyring:
        if not self.has_identity():
            self.create_identity(name, passphrase)
        legacy = load_legacy_key(self.paths.key_file) if self.paths.key_file.exists() else None
        ring = Keyring.create(self.identity(), legacy, webhooks)
        self.save(ring)
        if legacy is not None:
            # l'ancienne clé en clair n'est détruite qu'une fois le trousseau relu et vérifié
            _, check = open_keyring(self.paths.keyring_file.read_bytes(), self.identity())
            if check.current_key().key != legacy:
                raise CryptoError(
                    "Transfert de l'ancienne clé dans le trousseau : vérification échouée, clé conservée."
                )
            shred_file(self.paths.key_file)
            self.migrated_legacy_key = True
        return ring

    def rotate(self) -> VaultKey:
        ring = self.require_keyring()
        key = ring.rotate()
        self.save(ring)
        return key
