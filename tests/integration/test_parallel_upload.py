"""Envoi de plusieurs fichiers : tous les webhooks choisis travaillent en même temps, comme « dscs bench »."""
import asyncio
import os
from collections import Counter

import pytest

from conftest import MiB, file_messages
from dscs.errors import DSCSError


def small_files(root, n: int, size: int = 3 * MiB):
    root.mkdir()
    for i in range(n):
        (root / f"f{i}.bin").write_bytes(os.urandom(size))
    return root


def chunks_by_webhook(vault) -> Counter:
    return Counter(c.webhook_id for rec in vault.files() for c in vault.index.chunks(rec.id))


async def test_small_files_use_every_selected_webhook(vault, discord, tmp_path):
    discord.control.update(latency=0.1, rate_limit=False)
    items = vault.plan_upload([small_files(tmp_path / "src", 8)]).items
    progress, done = [], []
    async with vault.connected():
        results = await vault.upload_many(
            items, on_progress=progress.append, on_result=lambda item, res: done.append(item.path.name),
            webhooks=["1", "2"],
        )
    assert [r.name for r in results] == [i.path.name for i in items], "un résultat par fichier, dans l'ordre"
    assert sorted(done) == sorted(i.path.name for i in items)
    assert sum(progress) == sum(i.size for i in items)
    assert chunks_by_webhook(vault) == {"1": 4, "2": 4}, "les deux webhooks travaillent, pas seulement le premier"
    assert discord.stats["max_inflight"] == 2, "un envoi à la fois par webhook, les deux en même temps"
    assert discord.stats["max_inflight_by_webhook"] == {"1": 1, "2": 1}


async def test_files_follow_each_other_without_pause(vault, discord, tmp_path):
    """Un gros fichier puis des petits : le webhook libéré enchaîne sur le fichier suivant sans attendre."""
    discord.control.update(latency=0.1, rate_limit=False)
    src = small_files(tmp_path / "src", 3)
    (src / "a_big.bin").write_bytes(os.urandom(19 * MiB))  # 3 morceaux : le dernier n'occupe qu'un webhook
    items = vault.plan_upload([src]).items
    async with vault.connected():
        loop = asyncio.get_running_loop()
        t0 = loop.time()
        await vault.upload_many(items, webhooks=["1", "2"])
        elapsed = loop.time() - t0
    assert chunks_by_webhook(vault).total() == 6
    assert elapsed < 0.1 * 4.5, f"6 morceaux sur 2 webhooks : ~3 tours, pas 4 ou plus ({elapsed:.2f} s)"


async def test_one_failed_file_does_not_stop_the_others(vault, discord, tmp_path):
    items = vault.plan_upload([small_files(tmp_path / "src", 4)]).items
    items[1].path.unlink()  # disparu entre la confirmation et l'envoi
    failed = []
    async with vault.connected():
        results = await vault.upload_many(
            items, on_result=lambda item, res: isinstance(res, Exception) and failed.append(item.path.name)
        )
    assert isinstance(results[1], OSError) and failed == ["f1.bin"]
    assert sorted(r.name for r in vault.files()) == ["f0.bin", "f2.bin", "f3.bin"]
    assert vault.interrupted_count() == 0


async def test_file_modified_during_upload_is_cleaned_up(vault, discord, tmp_path, monkeypatch):
    from dscs.crypto import Cipher

    src = small_files(tmp_path / "src", 2, size=20 * MiB)
    items = vault.plan_upload([src]).items
    real_encrypt, grown = Cipher.encrypt, []

    def encrypt(self, data, aad):
        if not grown:  # f0 grossit pendant la lecture de son premier morceau
            grown.append(True)
            with (src / "f0.bin").open("ab") as f:
                f.write(b"plus")
        return real_encrypt(self, data, aad)

    async with vault.connected():
        monkeypatch.setattr(Cipher, "encrypt", encrypt)  # après la synchronisation, qui chiffre aussi l'index
        results = await vault.upload_many(items)
    assert isinstance(results[0], DSCSError) and "modifié" in str(results[0])
    assert [r.name for r in vault.files()] == ["f1.bin"]
    assert vault.interrupted_count() == 0, "les morceaux déjà envoyés du fichier modifié sont supprimés"


async def test_cancelled_batch_keeps_finished_files_only(vault, discord, tmp_path, paths):
    discord.control["latency"] = 0.05
    items = vault.plan_upload([small_files(tmp_path / "src", 10)]).items
    finished = []
    async with vault.connected():
        task = asyncio.create_task(vault.upload_many(items, on_result=lambda item, res: finished.append(res)))
        while len(finished) < 3:
            await asyncio.sleep(0.01)
        task.cancel()
        with pytest.raises(asyncio.CancelledError):
            await task
    kept = {r.id for r in vault.files()}
    assert kept and len(kept) < 10 and vault.interrupted_count() == 0
    assert {m for m in file_messages(discord, paths)} == {
        c.message_id for fid in kept for c in vault.index.chunks(fid)
    }, "rien d'autre sur Discord que les fichiers terminés"
