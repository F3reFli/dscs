"""Coffre partagé entre plusieurs appareils : appairage, synchronisation, révocation, secours."""
import pytest

from dscs.config import Paths
from dscs.crypto import Keyring, generate_identity, seal_keyring
from dscs.errors import ConfigError, CryptoError
from dscs.services import KeyStore, RevokedError, Vault, join_vault, recover_vault
from dscs.sync import Superblock


def device_paths(tmp_path, name: str) -> Paths:
    return Paths(tmp_path / name / "conf", tmp_path / name / "data")


async def pair(vault: Vault, paths: Paths, name: str = "portable") -> None:
    """Ajoute un nouvel appareil au coffre de `vault`, puis le fait rejoindre (codes vérifiés)."""
    identity = KeyStore(paths).create_identity(name, None)
    async with vault.connected():
        invitation = await vault.add_device(identity.public, name)
    inviter = vault.keystore.identity()
    seen = []

    def confirm(sender_name: str, code: str) -> bool:
        seen.append(sender_name)
        return code == inviter.code

    await join_vault(paths, invitation, confirm)
    assert seen == [inviter.name], "l'appareil qui rejoint voit qui l'a ajouté"


def superblock(discord, vault: Vault) -> Superblock:
    return Superblock.from_bytes(discord.store[vault.keyring.superblock["message_id"]]["data"])


async def test_pair_and_share_both_ways(vault, discord, sample_tree, tmp_path):
    async with vault.connected():
        [rec] = [await vault.upload(i) for i in vault.plan_upload([sample_tree / "notes.md"]).items]
    b_paths = device_paths(tmp_path, "b")
    await pair(vault, b_paths)

    with Vault.open(b_paths) as b:
        assert b.keyring.vault_id == vault.keyring.vault_id
        assert set(b.keyring.webhooks) == set(vault.keyring.webhooks), "les webhooks viennent du trousseau"
        assert b.settings.webhooks == [], "rien à configurer sur le nouvel appareil"
        async with b.connected():
            [got] = b.files()
            assert got.uid == rec.uid
            out = await b.download(got, tmp_path / "dl-b")
            assert out.read_bytes() == (sample_tree / "notes.md").read_bytes()
            [mine] = [await b.upload(i) for i in b.plan_upload([sample_tree / "projet" / "README"]).items]

    async with vault.connected():  # A reçoit le fichier envoyé par B
        assert {r.uid for r in vault.files()} == {rec.uid, mine.uid}
        readme = next(r for r in vault.files() if r.uid == mine.uid)
        assert (await vault.download(readme, tmp_path / "dl-a")).read_text() == "readme"
    assert {m.name for m in vault.members()} == {vault.keystore.identity().name, "portable"}


async def test_join_needs_confirmation_and_invitation(vault, discord, tmp_path):
    b_paths = device_paths(tmp_path, "b")
    identity = KeyStore(b_paths).create_identity("portable", None)
    async with vault.connected():
        early = vault.invitation()
    with pytest.raises(ConfigError, match="pas encore ajouté"):
        await join_vault(b_paths, early, lambda n, c: True)
    async with vault.connected():
        invitation = await vault.add_device(identity.public, "portable")
    with pytest.raises(CryptoError, match="Aucun appareil confirmé"):
        await join_vault(b_paths, invitation, lambda n, c: False)
    assert not b_paths.keyring_file.exists(), "rien n'est enregistré sans confirmation"
    with pytest.raises(ConfigError, match="déjà membre"):
        async with vault.connected():
            await vault.add_device(identity.public, "encore")


async def test_rotation_and_deletion_propagate(vault, discord, sample_tree, tmp_path):
    b_paths = device_paths(tmp_path, "b")
    async with vault.connected():
        [old] = [await vault.upload(i) for i in vault.plan_upload([sample_tree / "notes.md"]).items]
    await pair(vault, b_paths)
    async with vault.connected():
        new_key = await vault.rotate_key()
        [new] = [await vault.upload(i) for i in vault.plan_upload([sample_tree / "projet" / "README"]).items]
        await vault.delete(vault.file(old.id))
    with Vault.open(b_paths) as b:
        async with b.connected():
            assert b.key_id == new_key.id, "la nouvelle génération de clé est reçue"
            assert [r.uid for r in b.files()] == [new.uid], "la suppression est reçue (pierre tombale)"
            await b.download(b.files()[0], tmp_path / "dl")


async def test_revoked_device_is_cut_off(vault, discord, sample_tree, tmp_path):
    b_paths = device_paths(tmp_path, "b")
    await pair(vault, b_paths)
    b_id = KeyStore(b_paths).identity().id
    old_key = vault.key_id
    async with vault.connected():
        with pytest.raises(ConfigError, match="lui-même"):
            await vault.revoke_device(vault.find_member(vault.keystore.identity().name))
        new_key = await vault.revoke_device(vault.find_member("portable"))
        [await vault.upload(i) for i in vault.plan_upload([sample_tree / "notes.md"]).items]
    assert new_key.id != old_key and b_id in vault.keyring.revoked
    sb = superblock(discord, vault)
    assert b_id not in sb.keyrings and all(b_id not in s for s in sb.keyrings.values()), "ses trousseaux sont retirés"
    with Vault.open(b_paths) as b:
        with pytest.raises(RevokedError, match="retiré"):
            async with b.connected():
                pass
        assert new_key.id not in b.keyring.keys, "il n'a jamais reçu la nouvelle clé"


async def test_concurrent_changes_are_merged(vault, discord, sample_tree, tmp_path):
    b_paths = device_paths(tmp_path, "b")
    await pair(vault, b_paths)
    with Vault.open(b_paths) as b:
        async with b.connected():  # B a reçu l'état avant les envois de A
            async with vault.connected():
                [a_file] = [await vault.upload(i) for i in vault.plan_upload([sample_tree / "notes.md"]).items]
            [b_file] = [await b.upload(i) for i in b.plan_upload([sample_tree / "projet" / "README"]).items]
        assert {r.uid for r in b.files()} == {a_file.uid, b_file.uid}
    async with vault.connected():
        assert {r.uid for r in vault.files()} == {a_file.uid, b_file.uid}, "aucun envoi perdu"


async def test_keyring_from_unknown_device_is_ignored(vault, discord, tmp_path):
    async with vault.connected():
        pass  # coffre publié
    me = vault.keystore.identity()
    intruder = generate_identity("intrus")
    forged = Keyring.from_bytes(vault.keyring.to_bytes())
    forged.add_member(intruder.public, "intrus")
    forged.rotate()  # une clé que l'intrus connaît
    sb = superblock(discord, vault)
    sb.keyrings.setdefault(me.id, {})[intruder.id] = seal_keyring(forged, intruder, me.public)
    discord.store[vault.keyring.superblock["message_id"]]["data"] = sb.to_bytes()
    keys_before = set(vault.keyring.keys)
    async with vault.connected():
        report = vault.last_sync
    assert set(vault.keyring.keys) == keys_before and intruder.id not in vault.keyring.members
    assert any("inconnu" in w for w in report.warnings)


async def test_rollback_of_index_is_ignored(vault, discord, sample_tree, tmp_path):
    async with vault.connected():
        pass
    sb_id = vault.keyring.superblock["message_id"]
    old_superblock = bytes(discord.store[sb_id]["data"])
    async with vault.connected():
        [rec] = [await vault.upload(i) for i in vault.plan_upload([sample_tree / "notes.md"]).items]
    discord.store[sb_id]["data"] = old_superblock  # Discord (ou un tiers) remet un vieil état
    async with vault.connected():
        assert [r.uid for r in vault.files()] == [rec.uid], "un état plus ancien n'efface rien"


async def test_recovery_kit(vault, discord, sample_tree, tmp_path):
    async with vault.connected():
        [rec] = [await vault.upload(i) for i in vault.plan_upload([sample_tree / "notes.md"]).items]
        kit = await vault.create_recovery()
    c_paths = device_paths(tmp_path, "c")
    shown = []
    await recover_vault(c_paths, kit, "nouveau-pc", None, lambda members: shown.extend(members) or True)
    assert {m.name for m in shown} >= {vault.keystore.identity().name}
    with Vault.open(c_paths) as c:
        async with c.connected():
            [got] = c.files()
            assert (await c.download(got, tmp_path / "dl")).read_bytes() == (sample_tree / "notes.md").read_bytes()
    async with vault.connected():  # les autres appareils acceptent le nouvel appareil, présenté par le secours
        assert "nouveau-pc" in {m.name for m in vault.members()}
    with pytest.raises(CryptoError):
        await recover_vault(device_paths(tmp_path, "d"), kit.__class__(kit.invitation, bytes(32)), "x", None,
                            lambda m: True)
