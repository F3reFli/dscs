"""Fixtures communes : faux serveur Discord, dossiers isolés, Vault prêt à l'emploi."""
import os

os.environ["NO_PROXY"] = os.environ["no_proxy"] = "127.0.0.1,localhost"

from dataclasses import dataclass  # noqa: E402
from pathlib import Path  # noqa: E402

import pytest  # noqa: E402
from aiohttp import web  # noqa: E402

from dscs.config import Paths  # noqa: E402
from dscs.services import Vault, initialize  # noqa: E402
from fakes.fake_discord import CONTROL, STATS, STORE, make_app  # noqa: E402

MiB = 1024 * 1024


@dataclass
class FakeDiscord:
    app: web.Application
    urls: list[str]

    @property
    def store(self) -> dict:
        return self.app[STORE]

    @property
    def stats(self) -> dict:
        return self.app[STATS]

    @property
    def control(self) -> dict:
        return self.app[CONTROL]


def vault_messages(discord: FakeDiscord, paths: Paths) -> set[str]:
    """Messages du coffre lui-même (superbloc + instantané d'index), à distinguer des morceaux de fichiers."""
    from dscs.services import KeyStore
    from dscs.sync import Superblock

    ring = KeyStore(paths).keyring()
    if ring is None or ring.superblock is None or ring.superblock["message_id"] not in discord.store:
        return set()
    sb_id = ring.superblock["message_id"]
    sb = Superblock.from_bytes(discord.store[sb_id]["data"])
    return {sb_id} | {m for _, m in (sb.index.parts if sb.index else [])}


def file_messages(discord: FakeDiscord, paths: Paths) -> dict:
    """Messages qui portent des morceaux de fichiers."""
    meta = vault_messages(discord, paths)
    return {mid: m for mid, m in discord.store.items() if mid not in meta}


@pytest.fixture
async def discord():
    app = make_app()
    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, "127.0.0.1", 0)
    await site.start()
    port = site._server.sockets[0].getsockname()[1]
    base = f"http://127.0.0.1:{port}/api/webhooks"
    yield FakeDiscord(app, [f"{base}/1/tokA", f"{base}/2/tokB"])
    await runner.cleanup()


@pytest.fixture(autouse=True)
def fast_scrypt(monkeypatch):
    """scrypt allégé : les phrases de passe des tests n'ont pas besoin de résister à une attaque."""
    monkeypatch.setattr("dscs.crypto.device.SCRYPT_N", 2**10)


@pytest.fixture
def paths(tmp_path) -> Paths:
    return Paths(config_dir=tmp_path / "conf", data_dir=tmp_path / "data")


@pytest.fixture
def vault(paths, discord):
    initialize(paths, discord.urls)
    v = Vault.open(paths)
    v.settings.download_dir = paths.data_dir.parent / "dl"
    yield v
    v.close()


@pytest.fixture
def sample_tree(tmp_path) -> Path:
    root = tmp_path / "src"
    root.mkdir()
    (root / "vide.txt").write_bytes(b"")
    (root / "notes.md").write_text("# bonjour\n" * 50)
    (root / "gros.bin").write_bytes(os.urandom(25 * MiB + 123))  # 3 morceaux
    (root / "exact.bin").write_bytes(os.urandom(9 * MiB))  # pile 1 morceau
    (root / "projet" / "src").mkdir(parents=True)
    (root / "projet" / "README").write_text("readme")
    (root / "projet" / "src" / "main.py").write_text("print('hi')\n")
    (root / ".cache").write_text("caché")
    return root
