"""Journal en -vv sur un parcours complet : tout y est, sauf les secrets."""
import base64
import logging

from dscs.services import KeyStore, Vault
from integration.test_devices import device_paths, pair


async def test_full_journey_logs_steps_but_no_secret(vault, discord, sample_tree, tmp_path, caplog):
    caplog.set_level(logging.DEBUG, logger="dscs")
    async with vault.connected():
        items = vault.plan_upload([sample_tree / "notes.md", sample_tree / "gros.bin"]).items
        results = await vault.upload_many(items)
        notes = next(r for r in results if r.name == "notes.md")
        await vault.download(notes, tmp_path / "dl")
    b_paths = device_paths(tmp_path, "b")
    await pair(vault, b_paths)
    with Vault.open(b_paths) as b:
        async with b.connected():
            assert {r.name for r in b.files()} == {"notes.md", "gros.bin"}
    async with vault.connected():
        await vault.delete(notes)
    text = caplog.text

    for expected in ("envoi de 2 fichier(s)", "morceau 1/3 de gros.bin", "envoi terminé : 2/2",
                     "SHA-256 vérifié", "instantané de l'index publié", "index reçu", "POST ", "DELETE ",
                     "/webhooks/1/***"):
        assert expected in text, f"« {expected} » absent du journal"

    secrets = ["tokA", "tokB"]  # tokens des webhooks du faux Discord
    for ring in (vault.keyring, KeyStore(b_paths).require_keyring()):
        secrets += [k.key.hex() for k in ring.keys.values()]
        secrets += [base64.b64encode(k.key).decode() for k in ring.keys.values()]
    for store in (vault.keystore, KeyStore(b_paths)):
        identity = store.identity()
        secrets.append(identity.private.private_bytes_raw().hex())
        secrets.append(base64.b64encode(identity.private.private_bytes_raw()).decode())  # forme de device.json
    for secret in secrets:
        assert secret not in text, "un secret est sorti dans le journal"
    assert "hm=" not in text or "hm=***" in text, "signatures des liens CDN masquées"


async def test_log_is_silent_without_verbose(vault, discord, sample_tree, capsys):
    async with vault.connected():
        await vault.upload(vault.plan_upload([sample_tree / "notes.md"]).items[0])
    assert capsys.readouterr().err == "", "sans -v, rien ne s'écrit (ni dans l'interface)"
