"""Journal (-v, -vv, --log) : niveaux, fichier, et aucun secret qui sorte."""
import logging
import os

import pytest

from dscs.cli import logs
from dscs.utils import redact_secrets

log = logging.getLogger("dscs.essai")


@pytest.mark.parametrize(
    ("text", "expected"),
    [
        ("https://discord.com/api/webhooks/123/AbC-def_9", "https://discord.com/api/webhooks/123/***"),
        ("POST discord.com/api/v10/webhooks/123/tok?wait=true", "POST discord.com/api/v10/webhooks/123/***?wait=true"),
        ("GET discordapp.com/api/webhooks/1/x/messages/42", "GET discordapp.com/api/webhooks/1/***/messages/42"),
        ("cdn.discordapp.com/a/b.bin?ex=1&is=2&hm=f00d&", "cdn.discordapp.com/a/b.bin?ex=1&is=2&hm=***&"),
        ("rien de secret : 12 fichiers", "rien de secret : 12 fichiers"),
    ],
)
def test_redact_secrets(text, expected):
    assert redact_secrets(text) == expected


def test_silent_by_default(capsys):
    logs.setup(0)
    log.warning("ne doit pas sortir")
    assert capsys.readouterr().err == ""


def test_verbose_levels(capsys):
    logs.setup(1)
    log.info("étape %s", "https://discord.com/api/webhooks/1/tokA")
    log.debug("détail")
    err = capsys.readouterr().err
    assert "essai" in err and "étape" in err and "tokA" not in err and "/webhooks/1/***" in err
    assert "dscs.essai" not in err, "nom du module raccourci"
    assert "détail" not in err, "-v : pas le détail"
    logs.setup(2)
    log.debug("détail")
    assert "détail" in capsys.readouterr().err, "-vv : le détail"


def test_log_file(tmp_path, capsys):
    path = tmp_path / "journaux" / "dscs.log"
    logs.setup(0, path)
    log.info("dans le fichier")
    assert capsys.readouterr().err == ""
    assert "INFO" in path.read_text() and "dans le fichier" in path.read_text()
    assert oct(os.stat(path).st_mode)[-3:] == "600", "il contient des noms de fichiers"
