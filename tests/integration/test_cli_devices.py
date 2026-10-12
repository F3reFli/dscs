"""Appairage complet par la ligne de commande, comme un utilisateur : trois « machines » (DSCS_HOME distincts)."""
import asyncio
import re
import threading

import pytest
from aiohttp import web

from dscs.cli import main
from dscs.config import Paths
from dscs.services import initialize
from fakes.fake_discord import CONTROL, make_app


@pytest.fixture
def cli_discord():
    """Faux Discord dans un thread : main() lance son propre asyncio.run. Donne (URLs, contrôle du serveur)."""
    state, stop = {}, threading.Event()
    app = make_app()

    def serve():
        async def run():
            runner = web.AppRunner(app)
            await runner.setup()
            site = web.TCPSite(runner, "127.0.0.1", 0)
            await site.start()
            state["port"] = site._server.sockets[0].getsockname()[1]
            while not stop.is_set():
                await asyncio.sleep(0.05)
            await runner.cleanup()

        asyncio.run(run())

    thread = threading.Thread(target=serve, daemon=True)
    thread.start()
    while "port" not in state:
        threading.Event().wait(0.02)
    yield [f"http://127.0.0.1:{state['port']}/api/webhooks/{i}/tok{i}" for i in (1, 2)], app[CONTROL]
    stop.set()
    thread.join(5)


@pytest.fixture
def server_urls(cli_discord):
    return cli_discord[0]


@pytest.fixture
def on(tmp_path, monkeypatch, capsys):
    """Lance une commande dscs sur une « machine » : on("A", "sync") -> (code de sortie, sortie)."""
    monkeypatch.delenv("DSCS_PASSPHRASE", raising=False)

    def run(machine: str, *argv: str) -> tuple[int, str]:
        monkeypatch.setenv("DSCS_HOME", str(tmp_path / machine))
        code = main(list(argv))
        captured = capsys.readouterr()
        return code, captured.out + captured.err

    return run


def test_pairing_through_cli(server_urls, tmp_path, on):
    initialize(Paths(tmp_path / "A", tmp_path / "A"), server_urls, device_name="pc-fixe")
    (tmp_path / "photo.jpg").write_bytes(b"\x89PNG" * 5000)
    assert on("A", "put", str(tmp_path / "photo.jpg"))[0] == 0

    code, out = on("B", "device", "init", "--name", "portable")
    assert code == 0
    pub = re.search(r"dscs-pub-\S+", out).group(0)
    b_code = re.search(r"Code de cet appareil : (\S+)", out).group(1)
    a_code = re.search(r"Code\s+: (\S+)", on("A", "device")[1]).group(1)

    code, out = on("A", "device", "add", pub, "--name", "portable", "--code", "0000-0000-0000-0000-0000")
    assert code == 1 and "ne correspond pas" in out, "un mauvais code bloque l'ajout"
    code, out = on("A", "device", "add", pub, "--name", "portable", "--code", b_code)
    assert code == 0
    invitation = re.search(r"dscs-inv1-\S+", out).group(0)

    code, out = on("B", "device", "join", invitation, "--code", "1111-1111-1111-1111-1111")
    assert code == 1 and "Aucun appareil confirmé" in out
    code, out = on("B", "device", "join", invitation, "--code", a_code)
    assert code == 0 and "1 fichier(s)" in out
    assert on("B", "get", "1", "-o", str(tmp_path / "recu"))[0] == 0
    assert (tmp_path / "recu" / "photo.jpg").read_bytes() == (tmp_path / "photo.jpg").read_bytes()

    code, out = on("A", "device", "list")
    assert code == 0 and "pc-fixe  (cet appareil)" in out and "portable" in out
    assert on("A", "key", "rotate")[0] == 0
    code, out = on("B", "sync")
    assert code == 0 and "Trousseau mis à jour" in out

    assert on("A", "key", "recovery", "-o", str(tmp_path / "kit.txt"))[0] == 0
    kit = (tmp_path / "kit.txt").read_text().strip()
    assert oct((tmp_path / "kit.txt").stat().st_mode)[-3:] == "600"
    code, out = on("C", "device", "recover", kit, "--name", "nouveau-pc", "-y")
    assert code == 0 and "Coffre récupéré" in out

    code, out = on("A", "device", "revoke", "portable", "-y")
    assert code == 0 and "génération 3" in out
    code, out = on("B", "sync")
    assert code == 1 and "retiré" in out


def test_repair_after_webhooks_moved(cli_discord, tmp_path, on):
    urls, control = cli_discord
    initialize(Paths(tmp_path / "A", tmp_path / "A"), urls, device_name="pc-fixe")
    (tmp_path / "a.txt").write_text("avant")
    assert on("A", "put", str(tmp_path / "a.txt"))[0] == 0

    control["channels"].update({"1": "9001", "2": "9002"})  # webhooks déplacés dans Discord
    code, out = on("A", "sync")
    assert code == 0 and "introuvable" in out, "seul appareil : réparé tout seul"
    assert "Erreur" not in on("A", "device", "list")[1]

    code, out = on("B", "device", "init", "--name", "portable")
    pub, b_code = re.search(r"dscs-pub-\S+", out).group(0), re.search(r"Code de cet appareil : (\S+)", out).group(1)
    a_code = re.search(r"Code\s+: (\S+)", on("A", "device")[1]).group(1)
    invitation = re.search(r"dscs-inv1-\S+", on("A", "device", "add", pub, "--name", "portable", "--code", b_code)[1])
    assert on("B", "device", "join", invitation.group(0), "--code", a_code)[0] == 0

    control["channels"].update({"1": "9101", "2": "9102"})  # déplacés encore, avec deux appareils
    code, out = on("A", "sync")
    assert code == 1 and "--repair" in out and "dscs device invite" in out
    code, out = on("A", "sync", "--repair")
    assert code == 0 and "introuvable" in out and "dscs device join" in out
    code, out = on("A", "device", "invite")
    new_invitation = re.search(r"dscs-inv1-\S+", out).group(0)
    assert on("B", "sync")[0] == 1
    code, out = on("B", "device", "join", new_invitation)
    assert code == 0 and "Nouvelle adresse" in out
    (tmp_path / "b.txt").write_text("depuis B")
    assert on("B", "put", str(tmp_path / "b.txt"))[0] == 0
    code, out = on("A", "sync")
    assert code == 0 and "1 fichier(s) reçu(s)" in out
    assert "b.txt" in on("A", "ls")[1]
    code, out = on("A", "sync", "--repair")
    assert code == 0 and "rien à réparer" in out


def test_bench_through_cli(server_urls, tmp_path, on):
    initialize(Paths(tmp_path / "A", tmp_path / "A"), server_urls, device_name="pc-fixe")
    code, out = on("A", "bench", "-s", "20")
    assert code == 1 and "-y" in out, "hors terminal, il faut confirmer avec -y"
    code, out = on("A", "bench", "-s", "20", "-y", "--download")
    assert code == 0, out
    assert "Total" in out and "Téléchargement" in out and "3 message(s) de test supprimé(s)" in out
    assert "Stockage 1" in out and "Stockage 2" in out
    code, out = on("A", "bench", "-s", "5", "-y", "-w", "Stockage 2")
    assert code == 0 and "Stockage 1" not in out.split("Webhook")[1]
    assert ".bin" not in on("A", "ls")[1], "rien n'est entré dans le coffre"
