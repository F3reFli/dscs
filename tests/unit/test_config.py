from pathlib import Path

import pytest

from dscs.config import Paths, load_settings, save_settings
from dscs.discord import is_official_url
from dscs.errors import ConfigError
from dscs.models import WebhookRef

URL = "https://discord.com/api/webhooks/123456/AbC-dEf_9"


def test_paths_from_env():
    p = Paths.from_env({"DSCS_HOME": "/tmp/x"})
    assert p.config_dir == p.data_dir == Path("/tmp/x")
    p = Paths.from_env({"XDG_CONFIG_HOME": "/c", "XDG_DATA_HOME": "/d"})
    assert p.config_file == Path("/c/dscs/config.toml")
    assert p.key_file == Path("/c/dscs/key")
    assert p.index_file == Path("/d/dscs/index.db")


def test_webhook_ref_parse_and_redact():
    ref = WebhookRef.parse(URL + "/")
    assert ref.id == "123456"
    assert ref.url == URL
    assert "AbC-dEf_9" not in ref.redacted and "AbC-dEf_9" not in repr(ref)
    with pytest.raises(ConfigError):
        WebhookRef.parse("https://example.com/nope")


def test_official_url_check():
    assert is_official_url(URL)
    assert not is_official_url("http://127.0.0.1:8080/api/webhooks/1/tok")


def test_save_then_load(tmp_path):
    f = tmp_path / "config.toml"
    save_settings(f, [URL, "https://discord.com/api/webhooks/999/zzz"])
    assert oct(f.stat().st_mode)[-3:] == "600"
    s = load_settings(f)
    assert [w.id for w in s.webhooks] == ["123456", "999"]
    assert s.chunk_size == 9 * 1024 * 1024
    assert not s.show_hidden


def test_duplicate_webhooks_are_merged(tmp_path):
    f = tmp_path / "config.toml"
    f.write_text(f'webhooks = ["{URL}", "{URL}/"]\n')
    assert len(load_settings(f).webhooks) == 1


@pytest.mark.parametrize(
    "content",
    [
        f'webhooks = ["{URL}"]\nchunk_size_mb = 0\n',
        f'webhooks = ["{URL}"]\nchunk_size_mb = 600\n',
        f'webhooks = ["{URL}"]\nchunk_size_mb = "beaucoup"\n',
        "webhooks = [\n",
    ],
)
def test_invalid_settings(tmp_path, content):
    f = tmp_path / "config.toml"
    f.write_text(content)
    with pytest.raises(ConfigError):
        load_settings(f)


def test_webhooks_are_optional(tmp_path):
    """Un appareil qui rejoint un coffre reçoit les webhooks par le trousseau : config.toml peut n'en avoir aucun."""
    f = tmp_path / "config.toml"
    f.write_text("webhooks = []\n")
    assert load_settings(f).webhooks == []
    f.write_text("show_hidden = true\n")
    assert load_settings(f).show_hidden


def test_missing_config(tmp_path):
    with pytest.raises(ConfigError, match="dscs init"):
        load_settings(tmp_path / "absent.toml")


def test_server_and_channel_names(tmp_path):
    f = tmp_path / "config.toml"
    save_settings(f, [URL])
    assert load_settings(f).server_names == {}, "le modèle initial ne nomme rien"
    f.write_text(f'webhooks = ["{URL}"]\n[servers]\n"4242" = "Perso"\n[channels]\n421 = "#photos"\n')
    s = load_settings(f)
    assert s.server_names == {"4242": "Perso"} and s.channel_names == {"421": "#photos"}
    f.write_text('servers = "pas une table"\n')
    with pytest.raises(ConfigError, match="servers"):
        load_settings(f)
