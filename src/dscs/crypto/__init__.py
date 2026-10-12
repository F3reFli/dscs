"""Chiffrement : morceaux (AES-256-GCM), clés de coffre, identité d'appareil, scellement, trousseau."""
from .cipher import CHUNK_FORMAT, OVERHEAD, Cipher, chunk_aad
from .device import (
    DeviceIdentity,
    PassphraseProvider,
    dump_identity,
    generate_identity,
    is_protected,
    load_identity,
    parse_public_key,
    public_key_to_text,
    read_public,
    verification_code,
)
from .keyring import Keyring, Member, VaultKey, open_keyring, seal_keyring
from .keys import KEY_SIZE, fingerprint, generate_key, load_legacy_key, parse_key
from .sealing import open_sealed, seal

__all__ = [
    "CHUNK_FORMAT",
    "KEY_SIZE",
    "OVERHEAD",
    "Cipher",
    "DeviceIdentity",
    "Keyring",
    "Member",
    "PassphraseProvider",
    "VaultKey",
    "chunk_aad",
    "dump_identity",
    "fingerprint",
    "generate_identity",
    "generate_key",
    "is_protected",
    "load_identity",
    "load_legacy_key",
    "open_keyring",
    "open_sealed",
    "parse_key",
    "parse_public_key",
    "public_key_to_text",
    "read_public",
    "seal",
    "seal_keyring",
    "verification_code",
]
