"""Scellement, identité d'appareil et trousseau."""
import json
import re

import pytest
from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PrivateKey

from dscs.crypto import (
    Keyring,
    dump_identity,
    generate_identity,
    is_protected,
    load_identity,
    open_keyring,
    open_sealed,
    parse_public_key,
    public_key_to_text,
    seal,
    seal_keyring,
    verification_code,
)
from dscs.crypto.sealing import public_bytes
from dscs.errors import ConfigError, CryptoError

# ---------------------------------------------------------------------- scellement


def test_seal_roundtrip_reveals_sender():
    alice, bob = X25519PrivateKey.generate(), X25519PrivateKey.generate()
    blob = seal(alice, public_bytes(bob), b"secret", b"ctx")
    sender, data = open_sealed(bob, blob, b"ctx")
    assert data == b"secret" and sender == public_bytes(alice)
    assert b"secret" not in blob


def test_seal_only_for_recipient_and_context():
    alice, bob, eve = (X25519PrivateKey.generate() for _ in range(3))
    blob = seal(alice, public_bytes(bob), b"secret", b"ctx")
    with pytest.raises(CryptoError):
        open_sealed(eve, blob, b"ctx")  # pas le destinataire
    with pytest.raises(CryptoError):
        open_sealed(bob, blob, b"autre usage")  # autre contexte


def test_seal_detects_tampering_and_forged_sender():
    alice, bob, eve = (X25519PrivateKey.generate() for _ in range(3))
    blob = bytearray(seal(alice, public_bytes(bob), b"secret", b"ctx"))
    tampered = bytes(blob[:-1]) + bytes([blob[-1] ^ 1])
    with pytest.raises(CryptoError):
        open_sealed(bob, tampered, b"ctx")
    # remplacer la clé publique de l'expéditeur par celle d'eve casse l'authentification
    forged = bytes(blob[:8]) + public_bytes(eve) + bytes(blob[40:])
    with pytest.raises(CryptoError):
        open_sealed(bob, forged, b"ctx")


# ---------------------------------------------------------------------- identité


def test_identity_without_passphrase():
    ident = generate_identity("pc")
    data = dump_identity(ident, None)
    assert not is_protected(data)
    again = load_identity(data)
    assert again.public == ident.public and again.name == "pc"


def test_identity_with_passphrase():
    ident = generate_identity("portable")
    data = dump_identity(ident, "correct horse battery")
    assert is_protected(data)
    assert ident.private.private_bytes_raw() not in data
    assert load_identity(data, lambda: "correct horse battery").public == ident.public
    with pytest.raises(CryptoError, match="incorrecte"):
        load_identity(data, lambda: "mauvaise phrase")
    with pytest.raises(ConfigError, match="phrase de passe"):
        load_identity(data)


def test_identity_inconsistent_public_key_is_refused():
    data = json.loads(dump_identity(generate_identity("pc"), None))
    data["public"] = json.loads(dump_identity(generate_identity("autre"), None))["public"]
    with pytest.raises(CryptoError, match="incohérente"):
        load_identity(json.dumps(data).encode())


def test_public_key_text_and_code():
    ident = generate_identity("pc")
    text = public_key_to_text(ident.public)
    assert text.startswith("dscs-pub-")
    assert parse_public_key(f"  {text.upper()}\n") == ident.public
    assert re.fullmatch(r"\d{4}(-\d{4}){4}", ident.code)
    assert verification_code(ident.public) == ident.code
    with pytest.raises(ConfigError):
        parse_public_key("dscs-pub-abc")
    with pytest.raises(ConfigError):
        parse_public_key("n'importe quoi")


# ---------------------------------------------------------------------- trousseau

HOOK_1 = "https://discord.com/api/webhooks/1/tokA"
HOOK_2 = "https://discord.com/api/webhooks/2/tokB"


def test_keyring_rotation_keeps_old_keys():
    ring = Keyring.create(generate_identity("pc"))
    first = ring.current
    new = ring.rotate()
    assert ring.current == new.id != first
    assert set(ring.keys) == {first, new.id}
    assert ring.generation_of(first) == 1 and ring.generation_of(new.id) == 2
    assert ring.revision == 2
    assert set(ring.ciphers()) == {first, new.id}


def test_keyring_serialization_roundtrip():
    owner = generate_identity("pc")
    ring = Keyring.create(owner, bytes(32))
    ring.rotate()
    again = Keyring.from_bytes(ring.to_bytes())
    assert again.vault_id == ring.vault_id and again.current == ring.current
    assert {k: v.key for k, v in again.keys.items()} == {k: v.key for k, v in ring.keys.items()}
    assert again.is_member(owner.public)


def test_sealed_keyring_for_member_only():
    owner, other = generate_identity("pc"), generate_identity("autre")
    ring = Keyring.create(owner)
    sender, opened = open_keyring(seal_keyring(ring, owner, owner.public), owner)
    assert sender == owner.public and opened.current == ring.current
    with pytest.raises(CryptoError):
        open_keyring(seal_keyring(ring, owner, owner.public), other)  # pas le destinataire
    with pytest.raises(CryptoError, match="membres"):
        open_keyring(seal_keyring(ring, owner, other.public), other)  # destinataire, mais pas membre


def test_keyring_keeps_newest_superblock_address_and_roles():
    owner = generate_identity("pc")
    old = Keyring.create(owner)
    old.superblock = {"webhook_id": "1", "message_id": "500"}
    rescue = old.add_member(generate_identity("secours").public, "secours", role="recovery")
    moved = Keyring.from_bytes(old.to_bytes())
    moved.superblock = {"webhook_id": "2", "message_id": "100", "moved": "1700000000.5"}
    a, b = Keyring.from_bytes(old.to_bytes()), Keyring.from_bytes(moved.to_bytes())
    a.merge(moved)
    b.merge(old)
    assert a.superblock == b.superblock == moved.superblock, "l'adresse recréée l'emporte, dans les deux sens"
    assert Keyring.from_bytes(a.to_bytes()).members[rescue.id].role == "recovery"
    assert "role" not in json.dumps(Keyring.create(owner)._doc()), "format inchangé pour les appareils normaux"


def test_removed_webhook_stays_removed():
    a = Keyring.create(generate_identity("pc"), webhooks=[HOOK_1, HOOK_2])
    b = Keyring.from_bytes(a.to_bytes())  # autre appareil, qui ne sait pas encore
    assert "removed_webhooks" not in json.dumps(a._doc()), "format inchangé tant que rien n'est retiré"
    revision = a.revision
    assert a.remove_webhook("2") and not a.remove_webhook("2")
    assert a.webhooks == [HOOK_1] and a.revision == revision + 1
    assert not a.add_webhooks([HOOK_2]), "un webhook retiré ne revient pas par config.toml"
    assert Keyring.from_bytes(a.to_bytes()).removed_webhooks.keys() == {"2"}
    b.merge(a)
    a.merge(Keyring.from_bytes(b.to_bytes()))
    assert a.webhooks == b.webhooks == [HOOK_1], "la suppression l'emporte sur l'union, dans les deux sens"
    assert a.same_content(b)
