"""Retirer un webhook du coffre : trousseau, cache, fichiers et morceaux, config.toml, et chez les autres appareils."""
import pytest

from conftest import file_messages
from dscs.config import Paths, load_settings
from dscs.errors import ConfigError
from dscs.services import KeyStore, Vault, join_vault


def device_paths(tmp_path, name: str) -> Paths:
    return Paths(tmp_path / name / "conf", tmp_path / name / "data")


async def pair(vault: Vault, paths: Paths, name: str = "portable") -> None:
    identity = KeyStore(paths).create_identity(name, None)
    async with vault.connected():
        invitation = await vault.add_device(identity.public, name)
    await join_vault(paths, invitation, lambda n, c: c == vault.keystore.identity().code)


async def upload(vault: Vault, path, webhooks=None):
    [rec] = [await vault.upload(i, webhooks=webhooks) for i in vault.plan_upload([path]).items]
    return rec


def drop_from_config(paths: Paths, url: str) -> None:
    """Comme à la main : la ligne du webhook est retirée de config.toml."""
    text = paths.config_file.read_text()
    paths.config_file.write_text("".join(line for line in text.splitlines(True) if url not in line))


async def test_remove_webhook_everywhere(vault, discord, sample_tree, paths):
    async with vault.connected():
        kept = await upload(vault, sample_tree / "notes.md", webhooks=["1"])
        only_2 = await upload(vault, sample_tree / "projet" / "README", webhooks=["2"])
        split = await upload(vault, sample_tree / "gros.bin")  # 3 morceaux répartis sur 1 et 2
        await vault.refresh_webhook_info()
        assert {w for ws in vault.file_webhooks().values() for w in ws} == {"1", "2"}
        result = await vault.remove_webhook("2")

    assert {r.uid for r in result.files} == {only_2.uid, split.uid}, "fichiers qui avaient des morceaux sur 2"
    assert result.leftovers == 0 and result.config_updated
    assert [r.uid for r in vault.files()] == [kept.uid]
    assert vault.interrupted_count() == 0, "les morceaux restés sur 1 ont été supprimés aussi"
    assert [m["wid"] for m in file_messages(discord, paths).values()] == ["1"], "seul le fichier gardé reste"
    assert [i.id for i in vault.webhook_infos()] == ["1"]
    assert "2" not in vault.index.webhook_rows(), "plus rien en cache"
    assert all("/2/" not in u for u in vault.keyring.webhooks)
    assert [w.id for w in load_settings(paths.config_file).webhooks] == ["1"], "retiré de config.toml"

    vault.close()
    with Vault.open(paths) as again:  # rien ne le fait revenir
        assert [i.id for i in again.webhook_infos()] == ["1"]
        async with again.connected():
            out = await again.download(again.files()[0], paths.data_dir.parent / "dl")
        assert out.read_bytes() == (sample_tree / "notes.md").read_bytes()


async def test_remove_webhook_already_deleted_in_discord(vault, discord, sample_tree, paths):
    async with vault.connected():
        split = await upload(vault, sample_tree / "gros.bin")
    discord.control["deleted"].add("2")  # supprimé dans Discord : ses messages sont inaccessibles
    async with vault.connected():
        result = await vault.remove_webhook("2")
    assert [r.uid for r in result.files] == [split.uid]
    assert vault.files() == [] and vault.interrupted_count() == 0
    assert result.leftovers > 0, "les morceaux du webhook supprimé n'ont pas pu être effacés"
    assert all(m["wid"] == "2" for m in file_messages(discord, paths).values()), "ceux de 1 sont effacés"


async def test_removal_reaches_other_devices(vault, discord, sample_tree, tmp_path):
    b_paths = device_paths(tmp_path, "b")
    async with vault.connected():
        kept = await upload(vault, sample_tree / "notes.md", webhooks=["1"])
        await upload(vault, sample_tree / "projet" / "README", webhooks=["2"])
    await pair(vault, b_paths)
    async with vault.connected():
        await vault.remove_webhook("2")

    with Vault.open(b_paths) as b:
        assert any("/2/" in u for u in b.keyring.webhooks), "B ne le sait pas encore"
        async with b.connected():
            assert [i.id for i in b.webhook_infos()] == ["1"]
            assert [r.uid for r in b.files()] == [kept.uid]
            assert "2" not in b.index.webhook_rows()
            await b.download(b.files()[0], tmp_path / "dl-b")
        # B pousse son trousseau (union) : le webhook ne revient pas chez A
    async with vault.connected():
        assert [i.id for i in vault.webhook_infos()] == ["1"]


async def test_removed_webhook_left_in_config_is_ignored(vault, discord, paths):
    url = discord.urls[1]
    async with vault.connected():
        await vault.remove_webhook("2")
    paths.config_file.write_text(paths.config_file.read_text().replace("webhooks = [\n", f'webhooks = [\n  "{url}",\n'))
    vault.close()
    with Vault.open(paths) as again:
        assert [i.id for i in again.webhook_infos()] == ["1"]
        assert any("retiré du coffre" in n for n in again.notices)


async def test_webhook_dropped_from_config_is_flagged(vault, discord, paths):
    vault.close()
    drop_from_config(paths, discord.urls[1])
    with Vault.open(paths) as again:
        assert [i.id for i in again.webhook_infos()] == ["1", "2"], "pas de suppression sans confirmation"
        assert any("dscs webhooks rm 2" in n for n in again.notices)
    with Vault.open(paths) as again:
        assert any("dscs webhooks rm 2" in n for n in again.notices), "rappelé tant que ce n'est pas fait"


async def test_entry_point_webhook(vault, discord, sample_tree, tmp_path, paths):
    assert vault.keyring.superblock is None
    async with vault.connected():
        rec = await upload(vault, sample_tree / "notes.md", webhooks=["2"])
    assert vault.keyring.superblock["webhook_id"] == "1"

    b_paths = device_paths(tmp_path, "b")
    await pair(vault, b_paths)
    with pytest.raises(ConfigError, match="point d'entrée"):
        async with vault.connected():
            await vault.remove_webhook("1")
    assert len(vault.webhook_infos()) == 2, "rien n'a changé"

    async with vault.connected():
        result = await vault.remove_webhook("1", repair=True)
    assert result.relocated and vault.keyring.superblock["webhook_id"] == "2"
    async with vault.connected():
        assert [r.uid for r in vault.files()] == [rec.uid]

    with pytest.raises(ConfigError, match="dernier webhook"):
        async with vault.connected():
            await vault.remove_webhook("2")


async def test_unknown_webhook(vault, discord):
    with pytest.raises(ConfigError, match="inconnu"):
        async with vault.connected():
            await vault.remove_webhook("999")
