"""Identité d'un appareil : paire de clés X25519 et nom, clé privée éventuellement protégée par phrase de passe.

La clé privée ne quitte jamais l'appareil. Seule la clé publique se partage (pour se faire ajouter à un coffre),
avec un code de vérification à comparer à voix haute ou à l'écran pour déjouer toute substitution.
"""
from __future__ import annotations

import base64
import hashlib
import json
import secrets
from collections.abc import Callable
from dataclasses import dataclass

from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PrivateKey
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.scrypt import Scrypt

from ..errors import ConfigError, CryptoError
from .sealing import PUBLIC_SIZE, public_bytes

FORMAT = "dscs-device"
VERSION = 1
PUBLIC_PREFIX = "dscs-pub-"
# scrypt : ~64 Mio de mémoire et une fraction de seconde par ouverture (modifiable par les tests)
SCRYPT_N, SCRYPT_R, SCRYPT_P = 2**16, 8, 1

PassphraseProvider = Callable[[], str]


def device_id(public: bytes) -> str:
    return hashlib.sha256(b"dscs:device-id:v1:" + public).hexdigest()[:16]


def verification_code(public: bytes) -> str:
    """20 chiffres (≈ 66 bits) dérivés de la clé publique, à comparer entre deux écrans."""
    digest = hashlib.sha256(b"dscs:device-code:v1:" + public).digest()
    digits = f"{int.from_bytes(digest[:12], 'big') % 10**20:020d}"
    return "-".join(digits[i : i + 4] for i in range(0, 20, 4))


def public_key_to_text(public: bytes) -> str:
    return PUBLIC_PREFIX + base64.b32encode(public).decode().rstrip("=").lower()


def parse_public_key(text: str) -> bytes:
    text = "".join(text.split()).lower()
    if not text.startswith(PUBLIC_PREFIX):
        raise ConfigError(f"Clé publique d'appareil invalide : elle doit commencer par « {PUBLIC_PREFIX} ».")
    body = text[len(PUBLIC_PREFIX) :].upper()
    try:
        public = base64.b32decode(body + "=" * (-len(body) % 8))
    except ValueError:
        public = b""
    if len(public) != PUBLIC_SIZE:
        raise ConfigError("Clé publique d'appareil invalide (copie incomplète ?).")
    return public


@dataclass
class DeviceIdentity:
    name: str
    private: X25519PrivateKey

    @property
    def public(self) -> bytes:
        return public_bytes(self.private)

    @property
    def id(self) -> str:
        return device_id(self.public)

    @property
    def code(self) -> str:
        return verification_code(self.public)


def generate_identity(name: str) -> DeviceIdentity:
    return DeviceIdentity(name=name, private=X25519PrivateKey.generate())


def _b64(data: bytes) -> str:
    return base64.b64encode(data).decode()


def _unb64(text: str) -> bytes:
    return base64.b64decode(text.encode())


def _kdf(passphrase: str, salt: bytes, n: int, r: int, p: int) -> bytes:
    return Scrypt(salt=salt, length=32, n=n, r=r, p=p).derive(passphrase.encode())


def dump_identity(identity: DeviceIdentity, passphrase: str | None) -> bytes:
    raw = identity.private.private_bytes(
        serialization.Encoding.Raw, serialization.PrivateFormat.Raw, serialization.NoEncryption()
    )
    doc: dict = {"format": FORMAT, "version": VERSION, "name": identity.name, "public": _b64(identity.public)}
    if passphrase:
        salt, nonce = secrets.token_bytes(16), secrets.token_bytes(12)
        key = _kdf(passphrase, salt, SCRYPT_N, SCRYPT_R, SCRYPT_P)
        doc |= {
            "protection": "scrypt",
            "kdf": {"n": SCRYPT_N, "r": SCRYPT_R, "p": SCRYPT_P, "salt": _b64(salt)},
            "nonce": _b64(nonce),
            "private": _b64(AESGCM(key).encrypt(nonce, raw, b"dscs:device-key:v1:" + identity.public)),
        }
    else:
        doc |= {"protection": "none", "private": _b64(raw)}
    return (json.dumps(doc, indent=2) + "\n").encode()


def is_protected(data: bytes) -> bool:
    return _parse(data).get("protection") == "scrypt"


def _parse(data: bytes) -> dict:
    try:
        doc = json.loads(data)
    except (ValueError, UnicodeDecodeError):
        doc = None
    if not isinstance(doc, dict) or doc.get("format") != FORMAT:
        raise ConfigError("Fichier d'identité d'appareil illisible.")
    if doc.get("version", 0) > VERSION:
        raise ConfigError("Identité d'appareil créée par une version plus récente de dscs : mets dscs à jour.")
    return doc


def read_public(data: bytes) -> tuple[str, bytes]:
    """Nom et clé publique, lisibles sans phrase de passe."""
    doc = _parse(data)
    return str(doc.get("name") or "appareil"), _unb64(doc["public"])


def load_identity(data: bytes, passphrase: PassphraseProvider | None = None) -> DeviceIdentity:
    doc = _parse(data)
    public = _unb64(doc["public"])
    if doc.get("protection") == "scrypt":
        if passphrase is None:
            raise ConfigError("Cet appareil est protégé par une phrase de passe : elle est nécessaire pour continuer.")
        kdf = doc["kdf"]
        key = _kdf(passphrase(), _unb64(kdf["salt"]), kdf["n"], kdf["r"], kdf["p"])
        try:
            raw = AESGCM(key).decrypt(_unb64(doc["nonce"]), _unb64(doc["private"]), b"dscs:device-key:v1:" + public)
        except InvalidTag:
            raise CryptoError("Phrase de passe incorrecte.") from None
    else:
        raw = _unb64(doc["private"])
    identity = DeviceIdentity(name=str(doc.get("name") or "appareil"), private=X25519PrivateKey.from_private_bytes(raw))
    if identity.public != public:
        raise CryptoError("Identité d'appareil incohérente (clé publique et clé privée ne correspondent pas).")
    return identity
