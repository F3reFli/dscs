"""Clés de l'appareil sur disque : création, migration de l'ancienne clé, rotation, phrase de passe."""
import pytest

from dscs.crypto import fingerprint
from dscs.errors import ConfigError, CryptoError
from dscs.services import KeyStore


def perms(path) -> str:
    return oct(path.stat().st_mode)[-3:]


def test_create_vault(paths):
    store = KeyStore(paths)
    assert store.keyring() is None and not store.has_identity()
    ring = store.create_vault("pc", None)
    assert perms(paths.device_file) == perms(paths.keyring_file) == "600"
    reopened = KeyStore(paths).keyring()
    assert reopened.current == ring.current and reopened.vault_id == ring.vault_id
    raw_key = ring.current_key().key
    assert raw_key.hex().encode() not in paths.keyring_file.read_bytes()
    with pytest.raises(ConfigError, match="existe déjà"):
        KeyStore(paths).create_vault("pc", None)


def test_legacy_key_is_migrated_then_erased(paths):
    legacy = bytes(range(32))
    paths.config_dir.mkdir(parents=True)
    paths.key_file.write_text(legacy.hex() + "\n")
    store = KeyStore(paths)
    ring = store.keyring()  # migration automatique
    assert store.migrated_legacy_key
    assert ring.current == fingerprint(legacy) and ring.current_key().key == legacy
    assert not paths.key_file.exists(), "l'ancienne clé en clair est effacée"
    assert KeyStore(paths).keyring().current_key().key == legacy


def test_rotation_persists(paths):
    store = KeyStore(paths)
    first = store.create_vault("pc", None).current
    new = store.rotate()
    ring = KeyStore(paths).keyring()
    assert ring.current == new.id and first in ring.keys


def test_passphrase_protects_keyring(paths, monkeypatch):
    KeyStore(paths).create_vault("pc", "phrase de passe solide")
    assert KeyStore(paths).is_protected()
    with pytest.raises(ConfigError):
        KeyStore(paths).keyring()  # aucune phrase fournie
    with pytest.raises(CryptoError, match="incorrecte"):
        KeyStore(paths, lambda: "fausse").keyring()
    store = KeyStore(paths, lambda: "phrase de passe solide")
    assert store.keyring() is not None
    store.set_passphrase(None)
    assert not KeyStore(paths).is_protected() and KeyStore(paths).keyring() is not None


def test_public_info_needs_no_passphrase(paths):
    KeyStore(paths).create_vault("portable", "phrase de passe solide")
    name, public = KeyStore(paths).public_info()
    assert name == "portable" and len(public) == 32


def test_local_keyring_sealed_by_another_device_is_refused(paths, tmp_path):
    from dscs.config import Paths
    from dscs.crypto import seal_keyring

    store = KeyStore(paths)
    ring = store.create_vault("pc", None)
    other = KeyStore(Paths(tmp_path / "o", tmp_path / "o"))
    other.create_vault("autre", None)
    ring.members.update(other.keyring().members)  # l'autre appareil se déclare membre…
    paths.keyring_file.write_bytes(seal_keyring(ring, other.identity(), store.identity().public))
    with pytest.raises(CryptoError, match="autre appareil"):
        KeyStore(paths).keyring()
