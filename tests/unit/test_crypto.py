import pytest

from dscs.crypto import OVERHEAD, Cipher, chunk_aad, fingerprint, load_legacy_key, parse_key
from dscs.errors import ConfigError, CryptoError


def test_parse_key_accepts_spaces_and_case():
    raw = bytes(range(32))
    assert parse_key(raw.hex()) == raw
    assert parse_key("  " + raw.hex().upper()[:30] + "\n " + raw.hex().upper()[30:]) == raw


@pytest.mark.parametrize("text", ["", "abc", "zz" * 32, "00" * 31, "00" * 33])
def test_parse_key_rejects_invalid(text):
    with pytest.raises(ConfigError, match="64 caractères"):
        parse_key(text)


def test_legacy_key(tmp_path):
    f = tmp_path / "key"
    f.write_text("00" * 32 + "\n")
    assert load_legacy_key(f) == bytes(32)
    f.write_text("pas de l'hexa")
    with pytest.raises(ConfigError, match="Clé invalide"):
        load_legacy_key(f)


def test_roundtrip_and_overhead():
    c = Cipher(bytes(32))
    blob = c.encrypt(b"secret", chunk_aad("uid", 0))
    assert len(blob) == len(b"secret") + OVERHEAD
    assert b"secret" not in blob
    assert c.decrypt(blob, chunk_aad("uid", 0)) == b"secret"


def test_tamper_and_position_binding():
    c = Cipher(bytes(32))
    blob = bytearray(c.encrypt(b"secret", chunk_aad("uid", 0)))
    with pytest.raises(CryptoError):
        c.decrypt(bytes(blob), chunk_aad("uid", 1))  # déplacé dans le fichier
    with pytest.raises(CryptoError):
        c.decrypt(bytes(blob), chunk_aad("autre", 0))  # transplanté dans un autre fichier
    blob[20] ^= 1
    with pytest.raises(CryptoError):
        c.decrypt(bytes(blob), chunk_aad("uid", 0))  # modifié


def test_wrong_key():
    blob = Cipher(bytes(32)).encrypt(b"x", b"ad")
    with pytest.raises(CryptoError):
        Cipher(b"\x01" * 32).decrypt(blob, b"ad")


def test_fingerprint_is_stable_and_distinct():
    assert fingerprint(bytes(32)) == fingerprint(bytes(32))
    assert fingerprint(bytes(32)) != fingerprint(b"\x01" * 32)
    assert len(fingerprint(bytes(32))) == 16
