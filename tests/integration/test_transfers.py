"""Bout en bout contre le faux serveur Discord : envoi, téléchargement, altération, annulation, gc."""
import asyncio
import hashlib
from pathlib import Path

import pytest

from conftest import MiB, file_messages, vault_messages
from dscs.errors import ConfigError, CryptoError, DSCSError, IntegrityError
from dscs.services import KeyStore, Vault


def sha(p: Path) -> str:
    return hashlib.sha256(p.read_bytes()).hexdigest()


def selection(tree: Path) -> list[Path]:
    return [tree / "gros.bin", tree / "projet", tree / "vide.txt", tree / "exact.bin", tree / "notes.md"]


async def upload_all(vault: Vault, paths: list[Path]):
    recs = []
    for item in vault.plan_upload(paths).items:
        got: list[int] = []
        recs.append(await vault.upload(item, on_progress=got.append))
        assert sum(got) == item.size, f"progression complète pour {item.path.name}"
    return recs


async def test_roundtrip(vault, discord, sample_tree, tmp_path):
    async with vault.connected():
        recs = await upload_all(vault, selection(sample_tree))
        by_name = {r.name: r for r in recs}
        store = discord.store  # tout ce qui part sur Discord : morceaux, superbloc, instantané d'index

        assert by_name["gros.bin"].chunks == 3, "25 Mo -> 3 morceaux"
        assert by_name["exact.bin"].chunks == 1, "9 Mio pile -> 1 morceau"
        assert by_name["main.py"].folder == "projet/src"
        assert all(len(m["data"]) <= 10 * MiB for m in store.values()), "aucune pièce jointe > 10 Mio"
        assert all(m["name"].endswith(".bin") and len(m["name"]) == 20 for m in store.values()), "noms aléatoires"
        assert all(m["flags"] == 4096 for m in store.values()), "messages sans notification"
        big_hooks = {c.webhook_id for c in vault.index.chunks(by_name["gros.bin"].id)}
        assert big_hooks == {"1", "2"}, "répartis sur 2 webhooks"
        plain = (sample_tree / "notes.md").read_bytes()
        assert all(plain[:40] not in m["data"] for m in store.values()), "contenu chiffré sur Discord"
        assert discord.stats["429"] > 0, "rate limits 429 subis et gérés"
        channels = vault.index.conn.execute("SELECT id, channel_id FROM webhooks ORDER BY id").fetchall()
        assert channels == [("1", "421"), ("2", "422")], "salon mémorisé pour chaque webhook"

        out = tmp_path / "out"
        for r in recs:
            t = await vault.download(r, out)
            assert t == out / r.folder / r.name and sha(t) == sha(sample_tree / r.folder / r.name), r.name
        assert discord.stats["expired_hits"] == 0 and discord.stats["fresh_fetch"] > 0, "lien frais à chaque fois"

        again = await vault.download(by_name["notes.md"], out)
        assert again.name == "notes (1).md", "jamais d'écrasement"


async def test_no_secret_in_index(vault, discord, sample_tree):
    async with vault.connected():
        await upload_all(vault, [sample_tree / "notes.md"])
    vault.index.conn.execute("PRAGMA wal_checkpoint(TRUNCATE)")
    assert b"tokA" not in vault.paths.index_file.read_bytes()


async def test_tampered_chunk_is_rejected(vault, discord, sample_tree, tmp_path):
    async with vault.connected():
        [big] = await upload_all(vault, [sample_tree / "gros.bin"])
        mid = vault.index.chunks(big.id)[1].message_id
        data = bytearray(discord.store[mid]["data"])
        data[100] ^= 1
        discord.store[mid]["data"] = bytes(data)
        with pytest.raises(CryptoError):
            await vault.download(big, tmp_path / "out")
    assert not list((tmp_path / "out").rglob("*.part")), "pas de .part laissé"


async def test_missing_chunk_record_is_detected(vault, discord, sample_tree, tmp_path):
    async with vault.connected():
        [big] = await upload_all(vault, [sample_tree / "gros.bin"])
        vault.index.delete_chunk(big.id, 2)
        with pytest.raises(IntegrityError):
            await vault.download(big, tmp_path / "out")


async def test_cancelled_upload_leaves_nothing(vault, discord, sample_tree, paths):
    async with vault.connected():
        task = asyncio.create_task(vault.upload(vault.plan_upload([sample_tree / "gros.bin"]).items[0]))
        while not file_messages(discord, paths):
            await asyncio.sleep(0.01)
        task.cancel()
        with pytest.raises(asyncio.CancelledError):
            await task
    assert file_messages(discord, paths) == {}, "morceaux déjà envoyés supprimés de Discord"
    assert vault.files() == [] and vault.interrupted_count() == 0, "rien dans l'index"


async def test_failed_cleanup_is_retried_by_gc(vault, discord, sample_tree, paths):
    async with vault.connected():
        task = asyncio.create_task(vault.upload(vault.plan_upload([sample_tree / "gros.bin"]).items[0]))
        while not file_messages(discord, paths):
            await asyncio.sleep(0.01)
        discord.control["deny_deletes"] = True  # nettoyage impossible : réseau coupé, droits…
        task.cancel()
        with pytest.raises(asyncio.CancelledError):
            await task
        assert file_messages(discord, paths), "des morceaux restent sur Discord"
        assert vault.interrupted_count() == 1, "l'envoi interrompu est gardé dans l'index, pas oublié"

        discord.control["deny_deletes"] = False
        report = await vault.collect_garbage()
    assert (report.cleaned, report.remaining) == (1, 0)
    assert file_messages(discord, paths) == {} and vault.interrupted_count() == 0


async def test_gc_refused_while_another_instance_runs(vault, discord, paths):
    other = Vault.open(paths)
    try:
        async with vault.connected():
            with pytest.raises(DSCSError, match="autre instance"):
                await vault.collect_garbage()
    finally:
        other.close()


async def test_delete(vault, discord, sample_tree, paths):
    async with vault.connected():
        recs = await upload_all(vault, [sample_tree / "gros.bin", sample_tree / "notes.md"])
        big = next(r for r in recs if r.name == "gros.bin")
        ids = [c.message_id for c in vault.index.chunks(big.id)]
        await vault.delete(big)
        assert vault.file(big.id) is None and not set(ids) & set(discord.store)
        for r in vault.files():
            await vault.delete(r)
    assert file_messages(discord, paths) == {} and vault.files() == [], "tous les morceaux supprimés"


async def test_unknown_webhook_is_reported(vault, discord, sample_tree, tmp_path):
    async with vault.connected():
        [big] = await upload_all(vault, [sample_tree / "gros.bin"])
        vault.index.conn.execute("INSERT INTO webhooks (id) VALUES ('999')")
        vault.index.conn.execute("UPDATE chunks SET webhook_id = '999' WHERE file_id = ? AND idx = 1", (big.id,))
        with pytest.raises(ConfigError, match="webhook 999 est inconnu"):
            await vault.download(big, tmp_path / "out")
        with pytest.raises(ConfigError):
            await vault.delete(big)
    assert vault.file(big.id) is not None, "un refus ne touche pas à l'index"


async def test_rotation_keeps_old_files_readable(vault, discord, sample_tree, tmp_path):
    async with vault.connected():
        [old] = await upload_all(vault, [sample_tree / "notes.md"])
    first_key = vault.key_id
    vault.close()
    KeyStore(vault.paths).rotate()
    with Vault.open(vault.paths) as v2:
        assert v2.key_id != first_key
        async with v2.connected():
            [new] = await upload_all(v2, [sample_tree / "projet" / "README"])
            assert v2.file(new.id).key_id == v2.key_id
            assert v2.file(old.id).key_id == first_key
            assert (await v2.download(v2.file(old.id), tmp_path / "out")).read_bytes() == (
                sample_tree / "notes.md"
            ).read_bytes()
            await v2.download(v2.file(new.id), tmp_path / "out")
        assert v2.files_per_key() == {first_key: (1, old.size), v2.key_id: (1, new.size)}


async def test_file_with_unknown_key_is_refused_early(vault, discord, sample_tree, tmp_path):
    async with vault.connected():
        [rec] = await upload_all(vault, [sample_tree / "notes.md"])
        vault.index.conn.execute("UPDATE files SET key_id = 'cle-inconnue' WHERE id = ?", (rec.id,))
        rec = vault.file(rec.id)
        assert not vault.can_read(rec) and vault.unreadable_count() == 1
        with pytest.raises(CryptoError, match="absente du trousseau"):
            await vault.download(rec, tmp_path / "out")
        await vault.delete(rec)  # supprimer ne demande aucune clé
    assert file_messages(discord, vault.paths) == {}


async def test_vault_without_keyring(vault, discord, sample_tree):
    async with vault.connected():
        await upload_all(vault, [sample_tree / "notes.md"])
    meta = vault_messages(discord, vault.paths)  # superbloc et index, connus seulement avec le trousseau
    vault.close()
    vault.paths.keyring_file.unlink()
    with Vault.open(vault.paths) as v2:
        assert not v2.has_key and len(v2.files()) == 1  # lister reste possible
        async with v2.connected():
            with pytest.raises(ConfigError, match="dscs init"):
                await v2.upload(v2.plan_upload([sample_tree / "vide.txt"]).items[0])
            with pytest.raises(ConfigError, match="device join"):
                await v2.download(v2.files()[0])
            await v2.delete(v2.files()[0])
    assert set(discord.store) == meta


async def test_no_key_material_in_clear(vault, discord, sample_tree):
    async with vault.connected():
        await upload_all(vault, [sample_tree / "notes.md"])
    key_hex = vault.keyring.current_key().key.hex().encode()
    for f in (vault.paths.device_file, vault.paths.keyring_file, vault.paths.config_file):
        assert key_hex not in f.read_bytes()
    assert not vault.paths.key_file.exists()


# ---------------------------------------------------------------------- webhooks, destination

async def test_webhook_info_and_labels(vault, discord):
    vault.settings.server_names["4242"] = "Perso"
    vault.settings.channel_names["421"] = "stockage-1"
    async with vault.connected():
        infos = {i.id: i for i in await vault.refresh_webhook_info()}
    one, two = infos["1"], infos["2"]
    assert (one.display_name, one.guild_id, one.channel_id) == ("Stockage 1", "4242", "421")
    assert (one.server, one.channel, one.server_detail) == ("Perso", "#stockage-1", "Perso (4242)")
    assert (two.server, two.channel) == ("Perso", "422"), "sans nom lisible : l'identifiant"
    assert two.link == "https://discord.com/channels/4242/422"
    vault.close()
    with Vault.open(vault.paths) as again:  # le cache survit hors ligne
        assert {i.id: i.channel_id for i in again.webhook_infos()} == {"1": "421", "2": "422"}


async def test_upload_on_selected_webhooks(vault, discord, sample_tree):
    async with vault.connected():
        await vault.refresh_webhook_info()
        assert vault.resolve_webhooks(["stockage 2"]) == ["2"], "par nom, sans tenir compte de la casse"
        assert vault.resolve_webhooks(["1", "Stockage 1"]) == ["1"], "sans doublon"
        with pytest.raises(ConfigError, match="Webhook inconnu"):
            vault.resolve_webhooks(["nope"])
        [item] = vault.plan_upload([sample_tree / "gros.bin"]).items
        rec = await vault.upload(item, webhooks=["2"])
        assert {c.webhook_id for c in vault.index.chunks(rec.id)} == {"2"}
        assert vault.file_webhooks()[rec.id] == ["2"]


async def test_download_destination_options(vault, discord, sample_tree, tmp_path):
    async with vault.connected():
        [rec] = await upload_all(vault, [sample_tree / "projet" / "src"])  # dossier distant « src »
        flat = await vault.download(rec, tmp_path / "plat", keep_folder=False)
        assert flat == tmp_path / "plat" / "main.py"
        nested = await vault.download(rec, tmp_path / "arbo")
        assert nested == tmp_path / "arbo" / "src" / "main.py"
        (tmp_path / "fichier").write_text("x")
        with pytest.raises(DSCSError, match="pas un dossier"):
            await vault.download(rec, tmp_path / "fichier")
