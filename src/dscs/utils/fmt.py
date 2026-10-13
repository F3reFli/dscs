"""Formatage pour l'affichage."""
from __future__ import annotations

import re
from pathlib import Path

_WEBHOOK_TOKEN = re.compile(r"(/api/(?:v\d+/)?webhooks/\d+/)[\w-]+")
_LINK_SIGNATURE = re.compile(r"([?&]hm=)[^&\s\"']+")


def human(n: float) -> str:
    """Taille lisible : 1536 -> '1.5 Ko'."""
    for unit in ("o", "Ko", "Mo", "Go", "To"):
        if n < 1024 or unit == "To":
            return f"{n:.0f} {unit}" if unit == "o" else f"{n:.1f} {unit}"
        n /= 1024
    return ""


def tilde(path: Path | str) -> str:
    """Chemin lisible : le dossier personnel devient « ~ » (/home/moi/Images -> ~/Images)."""
    path = Path(path)
    try:
        rel = path.relative_to(Path.home())
    except (ValueError, RuntimeError):
        return str(path)
    return "~" if rel == Path() else f"~/{rel}"


def redact_secrets(text: str) -> str:
    """Masque les tokens de webhook et les signatures des liens CDN : rien de tel dans un journal ou un message."""
    return _LINK_SIGNATURE.sub(r"\1***", _WEBHOOK_TOKEN.sub(r"\1***", text))
