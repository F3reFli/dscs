from pathlib import Path

import pytest

from dscs.errors import DSCSError
from dscs.models import UploadItem, UploadPlan, chunk_count
from dscs.utils import expand, human, safe_join, unique_path


def test_human():
    assert human(0) == "0 o"
    assert human(1536) == "1.5 Ko"
    assert human(25 * 1024**2) == "25.0 Mo"


def test_chunk_count():
    mib = 1024 * 1024
    assert chunk_count(0, 9 * mib) == 1  # fichier vide : 1 morceau
    assert chunk_count(9 * mib, 9 * mib) == 1
    assert chunk_count(25 * mib + 123, 9 * mib) == 3


def test_plan_totals(tmp_path):
    plan = UploadPlan([UploadItem(tmp_path / "a", "", 10), UploadItem(tmp_path / "b", "", 25)], chunk_size=10)
    assert plan.total_bytes == 35
    assert plan.total_chunks == 1 + 3


def test_expand_folders_with_relative_paths(sample_tree):
    items = expand([sample_tree / "projet", sample_tree / "notes.md", sample_tree / "notes.md"])
    folders = {p.name: f for p, f in items}
    assert folders == {"README": "projet", "main.py": "projet/src", "notes.md": ""}
    assert len(items) == 3  # doublons retirés


def test_expand_ignores_symlinks_inside_folders(tmp_path):
    (tmp_path / "d").mkdir()
    (tmp_path / "d" / "real").write_text("x")
    (tmp_path / "d" / "link").symlink_to(tmp_path / "d" / "real")
    assert [p.name for p, _ in expand([tmp_path / "d"])] == ["real"]


def test_unique_path(tmp_path):
    p = tmp_path / "notes.md"
    assert unique_path(p) == p
    p.write_text("x")
    assert unique_path(p).name == "notes (1).md"


@pytest.mark.parametrize("folder", ["../evil", "/etc", "a/../../b"])
def test_safe_join_refuses_escape(tmp_path, folder):
    with pytest.raises(DSCSError):
        safe_join(tmp_path / "dl", folder, "x")


def test_safe_join_ok(tmp_path):
    assert safe_join(tmp_path, "", "x") == tmp_path / "x"
    assert safe_join(tmp_path, "a/b", "x") == Path(tmp_path, "a", "b", "x")


def test_tilde_shortens_home(monkeypatch, tmp_path):
    from dscs.utils import tilde

    monkeypatch.setenv("HOME", str(tmp_path))
    assert tilde(tmp_path) == "~"
    assert tilde(tmp_path / "Images" / "a.jpg") == "~/Images/a.jpg"
    assert tilde("/srv/ailleurs") == "/srv/ailleurs"
