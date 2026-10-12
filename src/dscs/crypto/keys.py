"""Clés de coffre AES-256 : génération, empreinte, lecture de l'ancienne clé unique (dscs ≤ 0.2)."""
from __future__ import annotations

import hashlib
import re
import secrets
from pathlib import Path

from ..errors import ConfigError

KEY_SIZE = 32


def generate_key() -> bytes:
    return secrets.token_bytes(KEY_SIZE)


def parse_key(text: str) -> bytes:
    """Clé en hexadécimal (64 caractères) ; espaces, retours à la ligne et majuscules acceptés."""
    cleaned = re.sub(r"\s+", "", text)
    try:
        key = bytes.fromhex(cleaned)
    except ValueError:
        key = b""
    if len(key) != KEY_SIZE:
        raise ConfigError("Clé invalide : il faut exactement 64 caractères hexadécimaux (0-9, a-f).")
    return key


def load_legacy_key(path: Path) -> bytes:
    """Ancienne clé unique en clair (fichier `key`), avant son transfert dans le trousseau."""
    try:
        return parse_key(path.read_text())
    except ConfigError:
        raise ConfigError(f"Clé invalide dans {path}") from None


def fingerprint(key: bytes) -> str:
    """Empreinte publique d'une clé de coffre (ne permet pas de la retrouver).

    Enregistrée avec chaque fichier dans l'index : on sait quelle génération de clé l'a chiffré.
    """
    return hashlib.sha256(b"dscs:key-fingerprint:v1:" + key).hexdigest()[:16]
