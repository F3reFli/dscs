"""« dscs bench » : mesure du débit avec des données de test, toujours supprimées, sans toucher au coffre."""
import pytest

from dscs.errors import DiscordError

MiB = 1024 * 1024


async def bench(vault, *args, **kwargs):
    await vault.start()
    try:
        return await vault.benchmark(*args, **kwargs)
    finally:
        await vault.stop()


async def test_bench_measures_then_deletes_everything(vault, discord):
    seen = []
    report = await bench(vault, 25 * MiB, download=True, on_progress=seen.append)
    assert report.sent == 25 * MiB == sum(seen) == report.received
    split = {w.info.id: w.chunks for w in report.webhooks}
    assert sum(split.values()) == 3 and sorted(split.values()) == [1, 2], "morceaux répartis entre webhooks"
    assert all(w.up_rate > 0 and w.channel_id for w in report.webhooks)
    assert report.up_rate > 0 and report.down_rate > 0
    assert report.deleted == 3 and report.leftovers == 0
    assert discord.store == {}, "plus aucun message de test sur Discord"
    assert vault.files() == [] and not vault.shared, "le coffre n'est pas touché"


async def test_bench_on_one_webhook(vault, discord):
    report = await bench(vault, 20 * MiB, webhooks=["2"], per_webhook=2)
    assert [(w.info.id, w.chunks) for w in report.webhooks] == [("2", 3)]
    assert discord.store == {}


async def test_bench_counts_messages_it_could_not_delete(vault, discord):
    discord.control["deny_deletes"] = True
    report = await bench(vault, 10 * MiB)
    assert report.leftovers == 2 and report.deleted == 0 and report.errors
    discord.control["deny_deletes"] = False


async def test_bench_cleans_up_after_an_error(vault, discord, monkeypatch):
    from dscs.discord import WebhookClient

    real_upload, calls = WebhookClient.upload, []

    async def flaky(self, blob, filename):
        calls.append(filename)
        if len(calls) > 1:
            raise DiscordError("coupure simulée")
        return await real_upload(self, blob, filename)

    monkeypatch.setattr(WebhookClient, "upload", flaky)
    with pytest.raises(DiscordError, match="coupure simulée"):
        await bench(vault, 30 * MiB, webhooks=["1"])
    assert len(calls) == 2 and discord.store == {}, "le morceau déjà envoyé a été supprimé"
