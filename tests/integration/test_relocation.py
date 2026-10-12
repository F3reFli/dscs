"""Superbloc introuvable : webhook déplacé vers un autre salon (ou message supprimé) puis coffre réparé."""
import pytest

from dscs.config import Paths
from dscs.crypto import Keyring, generate_identity, seal_keyring
from dscs.errors import ConfigError, CryptoError, DiscordError
from dscs.services import KeyStore, SuperblockLostError, Vault, join_vault, recover_vault, relink_vault
from dscs.sync import Invitation, Superblock


def device_paths(tmp_path, name: str) -> Paths:
    return Paths(tmp_path / name / "conf", tmp_path / name / "data")


def move_webhooks(discord) -> None:
    """Comme dans les paramètres Discord : chaque webhook passe dans un nouveau salon."""
    discord.control["channels"].update({"1": "9001", "2": "9002"})


async def upload(vault: Vault, path):
    [rec] = [await vault.upload(i) for i in vault.plan_upload([path]).items]
    return rec


async def pair(vault: Vault, paths: Paths, name: str = "portable") -> None:
    identity = KeyStore(paths).create_identity(name, None)
    async with vault.connected():
        invitation = await vault.add_device(identity.public, name)
    await join_vault(paths, invitation, lambda n, c: c == vault.keystore.identity().code)


async def test_single_device_repairs_itself(vault, discord, sample_tree, tmp_path):
    notices = []
    vault.notify = notices.append
    async with vault.connected():
        before = await upload(vault, sample_tree / "notes.md")
        old_kit = await vault.create_recovery()  # un appareil de secours ne compte pas comme appareil actif
        await vault.refresh_webhook_info()
    old_address = dict(vault.keyring.superblock)
    move_webhooks(discord)

    async with vault.connected():  # plus de 404 : le superbloc est recréé dans le salon actuel
        report = vault.last_sync
        assert report.relocated and any("introuvable" in w for w in report.warnings)
        assert any("kits de secours" in n for n in notices), "l'utilisateur est prévenu, pas seulement dscs sync"
        await vault.refresh_webhook_info()
        assert any("a changé de salon" in n and "(421)" in n for n in notices)
        after = await upload(vault, sample_tree / "projet" / "README")
        assert (await vault.download(after, tmp_path / "dl")).read_text() == "readme"
        with pytest.raises(DiscordError, match="déplacé vers un autre salon"):
            await vault.download(before, tmp_path / "dl")  # resté dans l'ancien salon
    assert vault.keyring.superblock["message_id"] != old_address["message_id"]
    assert discord.store[vault.keyring.superblock["message_id"]]["channel"] == "9001"

    async with vault.connected():
        assert not vault.last_sync.relocated, "une seule réparation"
        report = await vault.sync()
        assert not report.relocated and not report.pushed

    with pytest.raises(ConfigError, match="kit de secours pointe"):
        await recover_vault(device_paths(tmp_path, "c"), old_kit, "pc", None, lambda m: True)
    async with vault.connected():
        new_kit = await vault.create_recovery()
    await recover_vault(device_paths(tmp_path, "c"), new_kit, "pc", None, lambda m: True)
    with Vault.open(device_paths(tmp_path, "c")) as c:
        async with c.connected():
            assert after.uid in {r.uid for r in c.files()}


async def test_repair_with_several_devices(vault, discord, sample_tree, tmp_path):
    b_paths = device_paths(tmp_path, "b")
    await pair(vault, b_paths)
    move_webhooks(discord)

    with pytest.raises(SuperblockLostError, match="--repair"):
        async with vault.connected():  # pas de réparation automatique : B pourrait réparer de son côté
            pass
    await vault.start()
    try:
        report = await vault.sync(repair=True)
    finally:
        await vault.stop()
    assert report.relocated and report.pushed
    async with vault.connected():
        rec = await upload(vault, sample_tree / "notes.md")
        invitation = vault.invitation()

    b = Vault.open(b_paths)
    try:
        with pytest.raises(SuperblockLostError):
            async with b.connected():
                pass
    finally:
        b.close()
    ring, moved = await relink_vault(b_paths, invitation)
    assert moved and ring.superblock == vault.keyring.superblock
    with Vault.open(b_paths) as b:
        async with b.connected():
            assert [r.uid for r in b.files()] == [rec.uid]
            mine = await upload(b, sample_tree / "projet" / "README")
    async with vault.connected():
        assert {r.uid for r in vault.files()} == {rec.uid, mine.uid}
    _, moved = await relink_vault(b_paths, invitation)
    assert not moved, "déjà à jour"


async def test_relink_only_trusts_known_devices(vault, discord, tmp_path):
    b_paths = device_paths(tmp_path, "b")
    await pair(vault, b_paths)
    b_ring = KeyStore(b_paths).require_keyring()
    b_identity = KeyStore(b_paths).identity()

    # un tiers qui connaît un webhook publie un faux superbloc avec un trousseau scellé pour B
    attacker = generate_identity("pirate")
    forged = Keyring.create(attacker)
    forged.vault_id = b_ring.vault_id
    forged.add_member(b_identity.public, "portable")
    forged.superblock = {"webhook_id": "1", "message_id": "1", "moved": "9e99"}
    sb = Superblock(b_ring.vault_id, {b_identity.id: {attacker.id: seal_keyring(forged, attacker, b_identity.public)}})
    async with vault.connected():
        msg = await vault.pool.get("1").upload(sb.to_bytes(), "x.bin")
    fake = Invitation(b_ring.vault_id, discord.urls[0], msg.id)
    with pytest.raises(CryptoError, match="confiance"):
        await relink_vault(b_paths, fake)
    assert KeyStore(b_paths).require_keyring().superblock == b_ring.superblock, "rien n'a changé"

    other = Invitation("0" * 16, discord.urls[0], msg.id)
    with pytest.raises(ConfigError, match="autre coffre"):
        await relink_vault(b_paths, other)
