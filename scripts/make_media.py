"""Captures et GIF du README, générés contre le faux serveur Discord des tests : rien ne part sur Discord.

    pip install -e '.[media]'
    python scripts/make_media.py media        # ou : make media

- tui.gif et tui-*.png : l'interface pilotée au clavier (envoi, onglet Distant, téléchargement) ;
- banner.gif : la bannière animée de `dscs -h`.

Tout se passe dans un dossier temporaire (dossier personnel factice, coffre neuf) : rien de la machine n'apparaît.
Les écrans sont rendus par Firefox (sans interface) pour les émojis et symboles ; sans Firefox, par CairoSVG, qui
les affiche en carrés.
"""
from __future__ import annotations

import asyncio
import functools
import html
import http.server
import io
import os
import re
import shutil
import subprocess
import sys
import tempfile
import threading
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "tests"))  # faux serveur Discord

import cairosvg  # noqa: E402
from aiohttp import web  # noqa: E402
from PIL import Image, ImageDraw, ImageFont  # noqa: E402

from dscs.config import Paths  # noqa: E402
from dscs.services import Vault, initialize  # noqa: E402
from dscs.tui import DSCSApp, VimTree  # noqa: E402
from dscs.utils import banner  # noqa: E402
from fakes.fake_discord import make_app  # noqa: E402

SIZE = (120, 38)  # colonnes, lignes de l'interface
WIDTH = 1200  # largeur des images, en pixels
REAL_HOME = Path.home()  # avant de remplacer HOME par le dossier personnel factice

DEMO_FILES = {  # chemin dans ~/Documents -> taille en octets
    "photos/vacances-2026/plage.jpg": 2_400_000,
    "photos/vacances-2026/coucher-de-soleil.jpg": 3_100_000,
    "photos/anniversaire.png": 1_250_000,
    "factures/2026-09-electricite.pdf": 182_000,
    "factures/2026-10-internet.pdf": 96_000,
    "musique/mix-ete.flac": 13_400_000,
    "projets/budget.ods": 41_000,
    "projets/notes.md": 4_200,
    "cv.pdf": 240_000,
    "passeport-scan.pdf": 1_800_000,
}
ALREADY_SENT = ["photos", "factures", "musique", "cv.pdf"]


# ---------------------------------------------------------------- SVG de Textual -> PNG


def scrub(svg: str, home: Path) -> str:
    """Remplace le dossier personnel factice par « ~ » dans le texte, en gardant l'alignement des colonnes."""
    prefix = str(home)

    def fix(match: re.Match) -> str:
        head, length, rest, content = match.group(1), float(match.group(2)), match.group(3), match.group(4)
        text = html.unescape(content)
        if prefix not in text:
            return match.group(0)
        new = text.replace(prefix, "~")
        new_length = length * len(new) / len(text)
        escaped = html.escape(new, quote=False).replace(" ", "&#160;")
        return f'{head}textLength="{new_length:.1f}"{rest}>{escaped}</text>'

    return re.sub(r'(<text[^>]*?)textLength="([\d.]+)"([^>]*)>([^<]*)</text>', fix, svg)


def _svg_height(svg: str) -> int:
    _, _, w, h = (float(v) for v in re.search(r'viewBox="([\d. ]+)"', svg).group(1).split())
    return round(WIDTH * h / w)


class _QuietHandler(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *args) -> None:
        pass


def _firefox_shot(firefox: str, work: Path, svgs: list[str], heights: list[int]) -> list[Image.Image]:
    """Une page avec les écrans les uns sous les autres, une fenêtre aussi haute que la page, une capture."""
    for f in work.glob("*.svg"):
        f.unlink()
    for n, svg in enumerate(svgs):
        (work / f"{n}.svg").write_text(svg)
    imgs = "".join(
        f'<img src="{n}.svg" style="display:block;width:{WIDTH}px;height:{h}px">' for n, h in enumerate(heights)
    )
    (work / "index.html").write_text(
        f'<!doctype html><meta charset="utf-8"><body style="margin:0;background:#000">{imgs}</body>'
    )
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), functools.partial(_QuietHandler, directory=work))
    threading.Thread(target=server.serve_forever, daemon=True).start()
    try:
        subprocess.run(
            [firefox, "--headless", "--no-remote", "--profile", str(work / "profile"),
             f"--window-size={WIDTH},{sum(heights)}", "--screenshot", str(work / "page.png"),
             f"http://127.0.0.1:{server.server_address[1]}/index.html"],
            check=True, capture_output=True, timeout=180,
        )
    finally:
        server.shutdown()
    page = Image.open(work / "page.png").convert("RGB")
    out, top = [], 0
    for h in heights:
        out.append(page.crop((0, top, WIDTH, top + h)))
        top += h
    return out


def render_svgs(svgs: list[str]) -> list[Image.Image]:
    """SVG de Textual -> images, rendus par Firefox sans interface (repli des polices pour émojis et symboles)."""
    firefox = shutil.which("firefox")
    if not firefox:
        print("Firefox introuvable : rendu CairoSVG (émojis et symboles en carrés).", file=sys.stderr)
        return [
            Image.open(io.BytesIO(cairosvg.svg2png(bytestring=svg.encode(), output_width=WIDTH))).convert("RGB")
            for svg in svgs
        ]
    # Firefox en snap ne lit que son propre dossier : profil, page et capture y vont
    snap = REAL_HOME / "snap" / "firefox" / "common"
    work = Path(tempfile.mkdtemp(prefix="dscs-media-", dir=snap if snap.is_dir() else None))
    try:
        (work / "profile").mkdir()
        images: list[Image.Image] = []
        batch: list[tuple[str, int]] = []
        for svg in svgs + [None]:  # par lots : une fenêtre de moins de 30 000 pixels de haut
            h = _svg_height(svg) if svg else 0
            if batch and (svg is None or sum(b for _, b in batch) + h > 30_000):
                images += _firefox_shot(firefox, work, [s for s, _ in batch], [b for _, b in batch])
                batch = []
            if svg:
                batch.append((svg, h))
        return images
    finally:
        shutil.rmtree(work, ignore_errors=True)


def save_gif(frames: list[tuple[Image.Image, float]], path: Path) -> None:
    images = [im.convert("P", palette=Image.ADAPTIVE, colors=128) for im, _ in frames]
    images[0].save(
        path, save_all=True, append_images=images[1:], duration=[round(d * 1000) for _, d in frames],
        loop=0, optimize=True, disposal=1,
    )


# ---------------------------------------------------------------- interface


async def capture_tui(work: Path, out: Path) -> None:
    home = work / "home"
    docs = home / "Documents"
    for rel, size in DEMO_FILES.items():
        f = docs / rel
        f.parent.mkdir(parents=True, exist_ok=True)
        f.write_bytes(os.urandom(size))
    for d in ("Images", "Musique", "Téléchargements", "Vidéos"):
        (home / d).mkdir(exist_ok=True)
    os.environ["HOME"] = str(home)  # l'arbre de destination part de ce dossier personnel factice

    app = make_app(rate_limit_every=0)
    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, "127.0.0.1", 0)
    await site.start()
    port = site._server.sockets[0].getsockname()[1]
    urls = [f"http://127.0.0.1:{port}/api/webhooks/{i}/tok{i}" for i in (1, 2)]

    paths = Paths(work / "dscs" / "conf", work / "dscs" / "data")
    initialize(paths, urls, device_name="pc-fixe")
    config = paths.config_file.read_text()  # noms lisibles du serveur et des salons
    config = config.replace("[servers]\n", '[servers]\n"4242" = "Coffre perso"\n', 1)
    config = config.replace("[channels]\n", '[channels]\n"421" = "stockage-1"\n"422" = "stockage-2"\n', 1)
    paths.config_file.write_text(config)

    shots: list[tuple[str, float, str | None]] = []  # (SVG, durée, nom de la capture fixe)
    with Vault.open(paths) as vault:
        vault.settings.download_dir = home / "Téléchargements"
        async with vault.connected():
            for item in vault.plan_upload([docs / p for p in ALREADY_SENT]).items:
                await vault.upload(item)

        tui = DSCSApp(docs, vault)
        async with tui.run_test(size=SIZE) as pilot:

            async def settle(delay: float = 0.25) -> None:
                await pilot.pause(delay)
                for _ in range(300):
                    if all(w.is_finished for w in tui.workers if w.group in ("transfer", "sync")):
                        break
                    await pilot.pause(0.05)
                await pilot.pause(0.15)

            def shot(duration: float, still: str | None = None) -> None:
                shots.append((scrub(tui.export_screenshot(title="dscs"), home), duration, still))

            async def press(*keys: str, duration: float = 0.45, still: str | None = None) -> None:
                await pilot.press(*keys)
                await settle()
                shot(duration, still)

            await settle(0.5)
            tui.clear_notifications()
            tree = tui.query_one(VimTree)
            shot(1.6)
            # ordre : factures/, musique/, photos/, projets/, cv.pdf, passeport-scan.pdf
            await press("j", "j", "j")
            await press("l", duration=0.8)  # déplie photos/
            await press("l", "l", duration=1.2)  # entre dans vacances-2026/ et le déplie
            await press("h", "h", "h", duration=0.5)  # replie, remonte, replie
            await press("j")
            await press("space", duration=0.6)  # marque projets/
            await press("j")
            await press("space", duration=1.2, still="local")  # marque passeport-scan.pdf
            await press("u", duration=2.4, still="upload")
            await press("y", duration=2.2, still="transfer")
            await press("escape", duration=0.6)
            assert not tree.marked
            await press("tab", duration=2.0, still="remote")
            await press("j", "j")
            await press("space", duration=0.5)
            await press("space", duration=0.9)
            await press("enter", duration=1.6)
            await press("down", duration=0.6)
            await press("j", duration=0.5)
            await press("j", "j", "j", duration=2.0, still="download")
            await press("escape", duration=1.2)
            await press("tab", duration=1.0)

    await runner.cleanup()
    images = render_svgs([svg for svg, _, _ in shots])
    for image, (_, _, still) in zip(images, shots, strict=True):
        if still:
            image.save(out / f"tui-{still}.png", optimize=True)
    save_gif([(image, duration) for image, (_, duration, _) in zip(images, shots, strict=True)], out / "tui.gif")


# ---------------------------------------------------------------- bannière de dscs -h


def _font(size: int) -> ImageFont.FreeTypeFont:
    for name in ("DejaVuSansMono.ttf", "NotoSansMono-Regular.ttf", "Menlo.ttc", "consola.ttf"):
        for base in ("/usr/share/fonts/truetype/dejavu", "/usr/share/fonts/truetype/noto", "/System/Library/Fonts", ""):
            try:
                return ImageFont.truetype(str(Path(base) / name) if base else name, size)
            except OSError:
                continue
    return ImageFont.load_default()


def render_terminal(lines: list[str], columns: int, rows: int) -> Image.Image:
    """Mini terminal : couleurs 24 bits, demi-blocs dessinés au pixel, fenêtre façon capture de Textual."""
    cw, ch, pad, bar = 11, 22, 24, 40
    bg, fg0 = (14, 14, 17), (215, 215, 220)
    font = _font(17)
    image = Image.new("RGB", (columns * cw + 2 * pad, rows * ch + 2 * pad + bar), (6, 6, 8))
    draw = ImageDraw.Draw(image)
    draw.rounded_rectangle([0, 0, image.width - 1, image.height - 1], radius=12, fill=bg)
    for i, color in enumerate(((255, 95, 87), (254, 188, 46), (40, 200, 64))):
        draw.ellipse([18 + i * 22, 14, 30 + i * 22, 26], fill=color)
    draw.text((image.width / 2, 20), "dscs -h", fill=(150, 150, 160), font=font, anchor="mm")
    for row, line in enumerate(lines):
        fg, back, dim, col = fg0, None, False, 0
        for token in re.findall(r"\x1b\[[\d;]*m|.", line):
            if token.startswith("\x1b["):
                codes = [int(c) for c in token[2:-1].split(";") if c] or [0]
                i = 0
                while i < len(codes):
                    code = codes[i]
                    if code == 0:
                        fg, back, dim = fg0, None, False
                    elif code == 2:
                        dim = True
                    elif code in (38, 48) and codes[i + 1 : i + 2] == [2]:
                        rgb = tuple(codes[i + 2 : i + 5])
                        fg, back = (rgb, back) if code == 38 else (fg, rgb)
                        i += 4
                    i += 1
                continue
            x, y = pad + col * cw, bar + pad + row * ch
            color = tuple(v // 2 for v in fg) if dim else fg
            if back:
                draw.rectangle([x, y, x + cw - 1, y + ch - 1], fill=back)
            if token == "█":
                draw.rectangle([x, y, x + cw - 1, y + ch - 1], fill=color)
            elif token == "▀":
                draw.rectangle([x, y, x + cw - 1, y + ch // 2 - 1], fill=color)
            elif token == "▄":
                draw.rectangle([x, y + ch // 2, x + cw - 1, y + ch - 1], fill=color)
            elif token != " ":
                draw.text((x, y + 2), token, fill=color, font=font)
            col += 1
    return image


def capture_banner(out: Path) -> None:
    columns = 80
    steps = banner.animation(columns)
    prompt = "\x1b[38;2;120;120;130m$\x1b[0m dscs -h"
    help_lines = ["", "usage: dscs [-h] [-V] COMMANDE ...", "", "Stockage chiffré sur un serveur Discord."]
    rows = 1 + len(steps[0][0]) + len(help_lines)
    frames = [(render_terminal([prompt], columns, rows), 0.6)]
    for n, (lines, delay) in enumerate(steps):
        last = n == len(steps) - 1
        shown = [prompt] + lines + (help_lines if last else [])
        frames.append((render_terminal(shown, columns, rows), 3.5 if last else max(delay, 0.03)))
    frames[-1][0].save(out / "banner.png", optimize=True)
    save_gif(frames, out / "banner.gif")


def main() -> None:
    out = Path(sys.argv[1] if len(sys.argv) > 1 else "media").resolve()
    out.mkdir(parents=True, exist_ok=True)
    capture_banner(out)
    with tempfile.TemporaryDirectory(prefix="dscs-media-") as work:
        asyncio.run(capture_tui(Path(work), out))
    for f in sorted(out.iterdir()):
        print(f"{f.name:22} {f.stat().st_size / 1024:8.0f} Kio")


if __name__ == "__main__":
    main()
