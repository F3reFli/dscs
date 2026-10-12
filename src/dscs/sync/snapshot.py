"""Instantané de l'index : l'état partageable (fichiers + pierres tombales), compressé puis chiffré
avec la clé de coffre courante. Le numéro de séquence est authentifié (AAD) : pas de rejeu d'un vieil état."""
from __future__ import annotations

import hashlib
import hmac
import json
import zlib

from ..crypto import Cipher
from ..errors import CryptoError

FORMAT = "dscs-index"
VERSION = 1


def canonical(state: dict) -> bytes:
    return json.dumps(state, sort_keys=True, separators=(",", ":")).encode()


def state_digest(state: dict, key: bytes) -> str:
    """Empreinte de l'état, liée à la clé : ne révèle rien du contenu à qui n'a pas la clé."""
    return hmac.new(key, b"dscs:index-digest:v1:" + canonical(state), hashlib.sha256).hexdigest()[:32]


def _aad(vault_id: str, seq: int) -> bytes:
    return f"dscs:index:v1:{vault_id}:{seq}".encode()


def seal_state(state: dict, cipher: Cipher, vault_id: str, seq: int) -> bytes:
    doc = {"format": FORMAT, "version": VERSION, "vault_id": vault_id, "seq": seq, "state": state}
    return cipher.encrypt(zlib.compress(canonical(doc), 9), _aad(vault_id, seq))


def open_state(blob: bytes, cipher: Cipher, vault_id: str, seq: int) -> dict:
    data = cipher.decrypt(blob, _aad(vault_id, seq))
    try:
        doc = json.loads(zlib.decompress(data))
    except (zlib.error, ValueError):
        raise CryptoError("Instantané d'index illisible.") from None
    if doc.get("format") != FORMAT or doc.get("vault_id") != vault_id or doc.get("seq") != seq:
        raise CryptoError("Instantané d'index incohérent.")
    if doc.get("version", 0) > VERSION:
        raise CryptoError("Index publié par une version plus récente de dscs : mets dscs à jour.")
    return doc["state"]
