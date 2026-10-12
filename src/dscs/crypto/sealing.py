"""Scellement authentifié pour un appareil : X25519 + HKDF-SHA256 + AES-256-GCM (dans l'esprit de HPKE, mode Auth).

    blob = MAGIC (8) ‖ pub. expéditeur (32) ‖ pub. éphémère (32) ‖ nonce (12) ‖ chiffré ‖ tag (16)

    clé = HKDF(DH(éphémère, destinataire) ‖ DH(expéditeur, destinataire),
               salt = pub. éphémère ‖ pub. expéditeur ‖ pub. destinataire, info = contexte)

- confidentialité : seul le détenteur de la clé privée du destinataire peut recalculer les deux échanges DH ;
- authenticité : seul le détenteur de la clé privée de l'expéditeur a pu calculer DH(expéditeur, destinataire).
  `open_sealed` renvoie la clé publique de l'expéditeur : à l'appelant de vérifier qu'elle est de confiance.
- le contexte (usage + identifiant du coffre) empêche de réutiliser un bloc scellé pour un autre usage.
"""
from __future__ import annotations

import secrets

from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PrivateKey, X25519PublicKey
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.hkdf import HKDF

from ..errors import CryptoError

MAGIC = b"DSCSSL01"
PUBLIC_SIZE = 32
_HEADER = len(MAGIC) + 2 * PUBLIC_SIZE
_NONCE = 12
_MIN_SIZE = _HEADER + _NONCE + 16


def public_bytes(key: X25519PrivateKey | X25519PublicKey) -> bytes:
    pub = key.public_key() if isinstance(key, X25519PrivateKey) else key
    return pub.public_bytes(serialization.Encoding.Raw, serialization.PublicFormat.Raw)


def _derive(shared: bytes, eph: bytes, sender: bytes, recipient: bytes, context: bytes) -> bytes:
    return HKDF(
        algorithm=hashes.SHA256(), length=32, salt=eph + sender + recipient, info=b"dscs:seal:v1:" + context
    ).derive(shared)


def _exchange(private: X25519PrivateKey, public: bytes) -> bytes:
    try:
        return private.exchange(X25519PublicKey.from_public_bytes(public))
    except ValueError:  # clé publique invalide ou de petit ordre
        raise CryptoError("Clé publique invalide dans un bloc scellé.") from None


def seal(sender: X25519PrivateKey, recipient_public: bytes, plaintext: bytes, context: bytes) -> bytes:
    if len(recipient_public) != PUBLIC_SIZE:
        raise CryptoError("Clé publique du destinataire invalide.")
    eph = X25519PrivateKey.generate()
    eph_pub, sender_pub = public_bytes(eph), public_bytes(sender)
    shared = _exchange(eph, recipient_public) + _exchange(sender, recipient_public)
    key = _derive(shared, eph_pub, sender_pub, recipient_public, context)
    header = MAGIC + sender_pub + eph_pub
    nonce = secrets.token_bytes(_NONCE)
    return header + nonce + AESGCM(key).encrypt(nonce, plaintext, header)


def open_sealed(recipient: X25519PrivateKey, blob: bytes, context: bytes) -> tuple[bytes, bytes]:
    """Renvoie (clé publique de l'expéditeur, contenu). Lève CryptoError si le bloc n'est pas lisible."""
    if len(blob) < _MIN_SIZE or not blob.startswith(MAGIC):
        raise CryptoError("Bloc scellé invalide.")
    sender_pub = blob[len(MAGIC) : len(MAGIC) + PUBLIC_SIZE]
    eph_pub = blob[len(MAGIC) + PUBLIC_SIZE : _HEADER]
    nonce, ciphertext = blob[_HEADER : _HEADER + _NONCE], blob[_HEADER + _NONCE :]
    recipient_pub = public_bytes(recipient)
    shared = _exchange(recipient, eph_pub) + _exchange(recipient, sender_pub)
    key = _derive(shared, eph_pub, sender_pub, recipient_pub, context)
    try:
        return sender_pub, AESGCM(key).decrypt(nonce, ciphertext, blob[:_HEADER])
    except InvalidTag:
        raise CryptoError(
            "Bloc scellé illisible : il n'est pas destiné à cet appareil, ou il a été modifié."
        ) from None
