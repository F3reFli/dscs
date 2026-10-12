"""Chiffrement authentifié des morceaux.

Format 1 (StoredFile.format == 1) : blob = nonce (12 o) || ciphertext || tag (16 o),
AES-256-GCM, AAD = "<uid>:<idx>". Un morceau ne peut être ni modifié, ni déplacé dans
le fichier, ni transplanté dans un autre fichier sans échec du déchiffrement.
Tout changement de format doit incrémenter CHUNK_FORMAT, l'ancien restant lisible.
"""
from __future__ import annotations

import secrets

from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

from ..errors import CryptoError

CHUNK_FORMAT = 1
NONCE_SIZE = 12
TAG_SIZE = 16
OVERHEAD = NONCE_SIZE + TAG_SIZE


def chunk_aad(uid: str, idx: int) -> bytes:
    return f"{uid}:{idx}".encode()


class Cipher:
    def __init__(self, key: bytes):
        self._aes = AESGCM(key)

    def encrypt(self, data: bytes, ad: bytes) -> bytes:
        nonce = secrets.token_bytes(NONCE_SIZE)
        return nonce + self._aes.encrypt(nonce, data, ad)

    def decrypt(self, blob: bytes, ad: bytes) -> bytes:
        try:
            return self._aes.decrypt(blob[:NONCE_SIZE], blob[NONCE_SIZE:], ad)
        except InvalidTag:
            raise CryptoError("Morceau corrompu ou mauvaise clé (échec du déchiffrement)") from None
