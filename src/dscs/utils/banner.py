"""Bannière de `dscs -h` : tête de mort et cadenas en pixel art rouge, « DSCS » à côté, petite animation.

Le dessin est une grille de pixels rendue avec des demi-blocs (▀ ▄ █) : deux pixels par caractère, couleur du haut
en avant-plan, couleur du bas en arrière-plan. Animation seulement dans un terminal (pas dans un tube ni un fichier),
désactivable avec NO_COLOR (ni couleur ni animation) ou DSCS_NO_ANIMATION.
"""
from __future__ import annotations

import os
import shutil
import sys
import time
from typing import TextIO

RGB = tuple[int, int, int]
Pixels = dict[tuple[int, int], RGB]

# '#' os, 'e' orbite, 'n' nez, 'c' fêlure, '.' vide
SKULL = [
    "..........##c###..........",
    "......#######c######......",
    "....#########c########....",
    "...###########c########...",
    "..############c#########..",
    ".##############c#########.",
    ".#############c##########.",
    "###############c##########",
    "################c#########",
    "##########################",
    "###eee##############eee###",
    "###eeeeee########eeeeee###",
    "###eeeeeeee####eeeeeeee###",
    "###eeeeeeeee##eeeeeeeee###",
    "####eeeeeeee##eeeeeeee####",
    ".####eeeeee####eeeeee####.",
    ".######eeee####eeee######.",
    ".###########nn###########.",
    "..#########nnnn#########..",
    "...########nnnn########...",
    "....##################....",
    ".....################.....",
    "......##############......",
    "......##.##.##.##.##......",
    "......##.##.##.##.##......",
    "......##.##.##.##.##......",
]

# 'H' reflet, 'F' face, 'D' ombre (relief), 'k' trou de serrure, 'o' jour de l'anse (vide, mais devant la tête)
SHACKLE = [
    "...HHHHHH...",
    "..HFFFFFFD..",
    ".HFDDDDDHFD.",
    ".HFDooooHFD.",
    ".HFDooooHFD.",
    ".HFDooooHFD.",
]
BODY = [
    "HHHHHHHHHHHH.",
    "HFFFFFFFFFFFD",
    "HFFFFkkkFFFFD",
    "HFFFkkkkkFFFD",
    "HFFFFkkkFFFFD",
    "HFFFFFkFFFFFD",
    "HFFFFkkkFFFFD",
    "HFFFFFFFFFFFD",
    "HFFFFFFFFFFFD",
    ".DDDDDDDDDDDD",
]
LOCK_X, LOCK_Y = 17, 24  # coin haut gauche du corps du cadenas

DSCS = [
    "██████╗ ███████╗ ██████╗███████╗",
    "██╔══██╗██╔════╝██╔════╝██╔════╝",
    "██║  ██║███████╗██║     ███████╗",
    "██║  ██║╚════██║██║     ╚════██║",
    "██████╔╝███████║╚██████╗███████║",
    "╚═════╝ ╚══════╝ ╚═════╝╚══════╝",
]
TAGLINE = ("Discord Server Cloud Storage", "chiffré de bout en bout")  # sous le titre, pas plus large que lui

BONE_TOP, BONE_BOTTOM = (255, 59, 59), (150, 0, 0)
LOCK = {"H": (255, 140, 140), "F": (235, 20, 30), "D": (95, 0, 0), "k": (30, 0, 0)}
GLOW = (255, 225, 200)
LETTER, LETTER_SHADOW, LETTER_FLASH = (255, 40, 40), (120, 0, 0), (255, 200, 200)
DIM = (90, 10, 10)


def _mix(a: RGB, b: RGB, t: float) -> RGB:
    return tuple(round(x + (y - x) * t) for x, y in zip(a, b, strict=True))  # type: ignore[return-value]


def pixels(shackle_lift: int = 0, glow: bool = False) -> Pixels:
    """Une image de l'emblème. `shackle_lift` : anse du cadenas levée de n pixels (ouvert)."""
    lock: dict[tuple[int, int], RGB | None] = {}
    for y, row in enumerate(SHACKLE):
        for x, ch in enumerate(row):
            if ch != ".":
                lock[(LOCK_X + x, LOCK_Y - len(SHACKLE) + y - shackle_lift)] = LOCK.get(ch)
    for y, row in enumerate(BODY):
        for x, ch in enumerate(row):
            if ch != ".":
                lock[(LOCK_X + x, LOCK_Y + y)] = LOCK[ch]
    out: Pixels = {}
    height = len(SKULL)
    for y, row in enumerate(SKULL):
        shade = _mix(BONE_TOP, BONE_BOTTOM, y / (height - 1))
        for x, ch in enumerate(row):
            near_lock = any((x + dx, y + dy) in lock for dx in (-1, 0, 1) for dy in (-1, 0, 1))
            if near_lock:
                continue  # un pixel de vide autour du cadenas, qui se détache de la tête
            if ch == "#":
                out[(x, y)] = shade
            elif ch == "e" and glow:
                out[(x, y)] = GLOW
    out.update({xy: c for xy, c in lock.items() if c is not None})
    return out


def _size() -> tuple[int, int]:
    height = max(len(SKULL), LOCK_Y + len(BODY))
    return LOCK_X + len(BODY[0]), height + height % 2


def _sgr(rgb: RGB, background: bool, truecolor: bool) -> str:
    if truecolor:
        return f"\x1b[{48 if background else 38};2;{rgb[0]};{rgb[1]};{rgb[2]}m"
    r, g, b = (round(c / 255 * 5) for c in rgb)  # cube 6×6×6 des terminaux 256 couleurs
    return f"\x1b[{48 if background else 38};5;{16 + 36 * r + 6 * g + b}m"


def render_emblem(px: Pixels, color: bool, truecolor: bool = True) -> list[str]:
    width, height = _size()
    lines = []
    for y in range(0, height, 2):
        cells = []
        for x in range(width):
            top, bottom = px.get((x, y)), px.get((x, y + 1))
            if not color:  # sans couleur, le trou de serrure n'existe que s'il est vide
                top, bottom = (None if c == LOCK["k"] else c for c in (top, bottom))
            if top is None and bottom is None:
                cells.append(" ")
            elif not color:
                cells.append("█" if top and bottom else "▀" if top else "▄")
            elif top == bottom:
                cells.append(_sgr(top, False, truecolor) + "█\x1b[0m")
            elif bottom is None:
                cells.append(_sgr(top, False, truecolor) + "▀\x1b[0m")
            elif top is None:
                cells.append(_sgr(bottom, False, truecolor) + "▄\x1b[0m")
            else:
                cells.append(_sgr(top, False, truecolor) + _sgr(bottom, True, truecolor) + "▀\x1b[0m")
        lines.append("".join(cells))
    return lines


def render_title(color: bool, truecolor: bool = True, face: RGB = LETTER, shadow: RGB = LETTER_SHADOW) -> list[str]:
    if not color:
        return list(DSCS)
    lines = []
    for row in DSCS:
        out, current = [], None
        for ch in row:
            want = None if ch == " " else face if ch == "█" else shadow  # traits ╗║╝ : ombre portée, effet 3D
            if want != current:
                out.append("\x1b[0m" if want is None else _sgr(want, False, truecolor))
                current = want
            out.append(ch)
        lines.append("".join(out) + "\x1b[0m")
    return lines


def compose(emblem: list[str], title: list[str], tagline: list[str], columns: int) -> list[str]:
    """Emblème à gauche, titre centré verticalement à droite (ou dessous si le terminal est étroit).

    Aucune ligne ne dépasse `columns` : une ligne repliée par le terminal décalerait l'animation."""
    emblem_width = _size()[0]
    title_width = max(len(DSCS[0]), *(len(t) for t in TAGLINE))
    margin = "  "
    under = [""] + [margin + line for line in tagline]
    if columns >= len(margin) + emblem_width + 3 + title_width:
        top = (len(emblem) - len(title) - 1 - len(tagline)) // 2
        right = [""] * top + title + [""] + tagline
        right += [""] * (len(emblem) - len(right))
        return [f"{margin}{left}   {r}".rstrip() for left, r in zip(emblem, right, strict=True)]
    return [margin + line for line in emblem] + [""] + [margin + line for line in title] + under


def _color_mode(stream: TextIO) -> tuple[bool, bool]:
    color = stream.isatty() and "NO_COLOR" not in os.environ and os.environ.get("TERM") != "dumb"
    truecolor = os.environ.get("COLORTERM", "").lower() in {"truecolor", "24bit"}
    return color, truecolor


def animation(columns: int, color: bool = True, truecolor: bool = True) -> list[tuple[list[str], float]]:
    """Étapes (image, durée), toutes de la même hauteur : la bannière apparaît ligne par ligne anse ouverte, l'anse
    claque, les orbites s'allument, le titre flashe puis se pose. La dernière étape est la bannière au repos."""

    def frame(lift: int, glow: bool, face: RGB, shadow: RGB, tag: list[str]) -> list[str]:
        return compose(
            render_emblem(pixels(lift, glow), color, truecolor),
            render_title(color, truecolor, face, shadow),
            tag,
            columns,
        )

    tagline = [f"\x1b[2m{t}\x1b[0m" if color else t for t in TAGLINE]
    blank = [""] * len(TAGLINE)
    start = frame(3, False, DIM, (40, 0, 0), blank)
    reveal = [(start[:n] + [""] * (len(start) - n), 0.018) for n in range(1, len(start))]
    return reveal + [
        (start, 0.12),
        (frame(2, False, DIM, (40, 0, 0), blank), 0.05),
        (frame(1, False, _mix(DIM, LETTER, 0.5), LETTER_SHADOW, blank), 0.05),
        (frame(0, True, LETTER_FLASH, LETTER, blank), 0.09),
        (frame(0, True, LETTER, LETTER_SHADOW, tagline), 0.12),
        (frame(0, False, LETTER, LETTER_SHADOW, tagline), 0.0),
    ]


def fits(columns: int) -> bool:
    return columns >= _size()[0] + 2


def show(stream: TextIO | None = None) -> None:
    """Affiche la bannière (animée dans un terminal) ; rien hors terminal, pour ne pas polluer un tube."""
    stream = stream or sys.stdout
    if not stream.isatty():
        return
    color, truecolor = _color_mode(stream)
    columns = shutil.get_terminal_size((80, 24)).columns
    if not fits(columns):
        return
    steps = animation(columns, color, truecolor)
    if not color or os.environ.get("DSCS_NO_ANIMATION"):
        steps = steps[-1:]
    height = len(steps[0][0])
    stream.write("\x1b[?25l")  # curseur caché pendant l'animation
    try:
        for n, (lines, delay) in enumerate(steps):
            if n:
                stream.write(f"\x1b[{height}A")  # on redessine par-dessus l'étape précédente
            stream.write("".join(line + "\x1b[K\n" for line in lines))
            stream.flush()
            time.sleep(delay)
    finally:
        stream.write("\x1b[0m\x1b[?25h\n")
        stream.flush()
