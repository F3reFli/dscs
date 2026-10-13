"""Bandeau d'accueil de la TUI : l'animation de `dscs -h`, sans jamais bloquer l'interface."""
import pytest
from textual.widgets import Static

from dscs.tui import DSCSApp, VimTree
from dscs.tui.widgets import BannerStrip

pytestmark = pytest.mark.tui


async def wait_for(pilot, condition, timeout=5.0):
    for _ in range(int(timeout * 20)):
        if condition():
            return
        await pilot.pause(0.05)
    raise AssertionError("délai dépassé")


async def test_banner_plays_while_the_interface_answers(vault, discord, sample_tree):
    tui = DSCSApp(sample_tree, vault)
    async with tui.run_test(size=(110, 40)) as pilot:
        strip, tree = tui.query_one(BannerStrip), tui.query_one(VimTree)
        assert strip.display and strip.playing, "l'animation démarre avec l'interface"
        assert tree.has_focus, "le clavier va à l'arbre, pas au bandeau"
        assert strip.region.y >= 0 and tui.screen.max_scroll_y == 0, "bandeau visible : l'écran ne défile pas"
        await pilot.press("j")
        assert strip.playing, "touche pressée pendant l'animation"
        assert tree.cursor_node.data.path.name == "projet", "… et l'arbre a bougé tout de suite"
        await wait_for(pilot, lambda: not strip.playing)
        assert "██████╗" in str(tui.query_one("#banner-art", Static).render()), "la bannière au repos s'affiche"
        await wait_for(pilot, lambda: not strip.display)
        await wait_for(pilot, lambda: vault.shared)  # la synchronisation a tourné en même temps
        assert tree.has_focus


async def test_no_banner_when_disabled_or_too_small(vault, discord, sample_tree, monkeypatch):
    async with DSCSApp(sample_tree, vault).run_test(size=(110, 25)) as pilot:
        assert not pilot.app.query_one(BannerStrip).display, "terminal trop bas : la place va à l'interface"
    monkeypatch.setenv("DSCS_NO_ANIMATION", "1")
    async with DSCSApp(sample_tree, vault).run_test(size=(110, 40)) as pilot:
        assert not pilot.app.query_one(BannerStrip).display


async def test_banner_error_never_breaks_the_interface(vault, discord, sample_tree, monkeypatch):
    import dscs.tui.widgets.banner_strip as module

    def broken(*args, **kwargs):
        raise RuntimeError("rendu impossible")

    monkeypatch.setattr(module.Text, "from_ansi", broken)
    tui = DSCSApp(sample_tree, vault)
    async with tui.run_test(size=(110, 40)) as pilot:
        strip = tui.query_one(BannerStrip)
        await wait_for(pilot, lambda: not strip.display)
        await pilot.press("j")
        assert tui.query_one(VimTree).cursor_node.data.path.name == "projet"
        assert tui.is_running
