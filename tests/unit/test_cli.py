import re

import pytest

from dscs import __version__
from dscs.cli import commands, main
from dscs.cli.parser import COMMANDS, build_parser


@pytest.mark.parametrize(
    ("argv", "command"),
    [(["ls"], "ls"), (["gc"], "gc"), (["get", "3", "-o", "/tmp"], "get"), (["rm", "3", "-y"], "rm"),
     (["webhooks", "rm", "papa", "-y"], "webhooks")],
)
def test_subcommands(argv, command):
    assert build_parser().parse_args(argv).command == command


def test_version(capsys):
    with pytest.raises(SystemExit):
        main(["--version"])
    assert __version__ in capsys.readouterr().out


@pytest.mark.parametrize("argv", [[], ["DOSSIER"]])
def test_default_command_is_tui(tmp_path, monkeypatch, argv):
    seen = []

    def fake_tui(args):
        seen.append(args.folder)
        return 0

    monkeypatch.setattr(commands, "cmd_tui", fake_tui)
    argv = [str(tmp_path) if a == "DOSSIER" else a for a in argv]
    assert main(argv) == 0
    assert seen == [tmp_path if argv else None]


def test_missing_config_message(tmp_path, monkeypatch, capsys):
    monkeypatch.setenv("DSCS_HOME", str(tmp_path))
    assert main(["ls"]) == 1
    assert "dscs init" in capsys.readouterr().err


# ---------------------------------------------------------------------- device / key

@pytest.fixture
def home(tmp_path, monkeypatch):
    monkeypatch.setenv("DSCS_HOME", str(tmp_path / "home"))
    monkeypatch.delenv("DSCS_PASSPHRASE", raising=False)
    from dscs.config import Paths
    from dscs.services import initialize

    paths = Paths.from_env()
    initialize(paths, ["https://discord.com/api/webhooks/1/tok"], device_name="pc-test")
    return paths


def test_device_show(home, capsys):
    assert main(["device"]) == 0
    out = capsys.readouterr().out
    assert "pc-test" in out and "dscs-pub-" in out and "aucune" in out


def test_device_show_without_identity(tmp_path, monkeypatch, capsys):
    monkeypatch.setenv("DSCS_HOME", str(tmp_path))
    assert main(["device"]) == 1
    assert "dscs init" in capsys.readouterr().out


def test_key_list(home, capsys):
    """Lister les clés est local ; la rotation (publiée sur Discord) est testée dans test_cli_devices."""
    assert main(["key"]) == 0
    out = capsys.readouterr().out
    current = [line for line in out.splitlines() if line.startswith("*  ")]
    assert "révision 1" in out and len(current) == 1


def test_device_passphrase_set_then_required(home, monkeypatch, capsys):
    answers = iter(["phrase solide 123", "phrase solide 123"])
    monkeypatch.setattr("sys.stdin.isatty", lambda: True, raising=False)
    monkeypatch.setattr("getpass.getpass", lambda prompt="": next(answers))
    assert main(["device", "passphrase"]) == 0
    capsys.readouterr()
    assert main(["device"]) == 0
    assert "Protection     : phrase de passe" in capsys.readouterr().out
    monkeypatch.setattr("sys.stdin.isatty", lambda: False, raising=False)
    assert main(["key"]) == 1  # protégé, pas de terminal, pas de DSCS_PASSPHRASE
    assert "DSCS_PASSPHRASE" in capsys.readouterr().err
    monkeypatch.setenv("DSCS_PASSPHRASE", "phrase solide 123")
    assert main(["key"]) == 0


def test_put_webhooks_and_get_flat_options():
    a = build_parser().parse_args(["put", "x", "-w", "1", "--webhook", "Stockage 2"])
    assert a.webhooks == ["1", "Stockage 2"]
    assert build_parser().parse_args(["put", "x"]).webhooks is None
    assert build_parser().parse_args(["get", "3", "--flat"]).flat
    assert build_parser().parse_args(["webhooks"]).command == "webhooks"


@pytest.mark.parametrize("columns", [40, 69, 70, 80, 120])
def test_banner_never_wider_than_the_terminal(columns):
    """Une ligne repliée par le terminal décalerait l'animation (redessin par « remonter de N lignes »)."""
    import re

    from dscs.utils import banner

    steps = banner.animation(columns)
    heights = {len(lines) for lines, _ in steps}
    assert len(heights) == 1, "toutes les étapes ont la même hauteur"
    for lines, _ in steps:
        for line in lines:
            assert len(re.sub(r"\x1b\[[\d;]*m", "", line)) <= columns


def _help(argv: list[str], capsys) -> str:
    with pytest.raises(SystemExit):
        main([*argv, "-h"])
    return capsys.readouterr().out


def _all_commands() -> list[list[str]]:
    """Chaque commande et sous-commande de dscs, comme on la tape."""
    import argparse

    found = []

    def walk(parser, prefix):
        for action in parser._actions:
            if isinstance(action, argparse._SubParsersAction):
                for name, sub in action.choices.items():
                    found.append([*prefix, name])
                    walk(sub, [*prefix, name])

    walk(build_parser(), [])
    return found


@pytest.mark.parametrize("argv", [[], *_all_commands()], ids=lambda a: " ".join(a) or "dscs")
def test_help_is_in_english_without_real_names(argv, capsys, monkeypatch):
    monkeypatch.setenv("COLUMNS", "80")
    out = _help(argv, capsys)
    assert max(len(line) for line in out.splitlines()) <= 80, "lisible dans un terminal de 80 colonnes"
    assert not re.search(r"[àâçéèêëîïôûùüœ«»]", out), "aide en anglais (pas d'accents ni de guillemets français)"
    for word in ("coffre", "fichier", "dossier", "défaut", "appareil", "papa", "Stockage"):
        assert word not in out


def test_main_help_is_grouped(capsys):
    out = _help([], capsys)
    sections = ["Getting started:", "Files:", "Webhooks:", "Sharing & keys:", "Options:", "Examples:", "TUI keys:"]
    positions = [out.index(title) for title in sections]
    assert positions == sorted(positions)
    for command in COMMANDS:
        assert re.search(rf"^  {command}\b", out, re.M), f"{command} est listée"
    assert "dscs <command> -h" in out
    assert max(len(line) for line in out.splitlines()) <= 80, "lisible dans un terminal de 80 colonnes"


def test_webhooks_help_explains_removal(capsys):
    out = _help(["webhooks"], capsys)
    assert "dscs webhooks rm <webhook_name>" in out and "config.toml" in out
    out = _help(["webhooks", "rm"], capsys)
    assert "--repair" in out and "at least one chunk on it are deleted" in out and "Examples:" in out
