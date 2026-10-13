"""config.toml : lecture validée et écriture du modèle initial."""
from __future__ import annotations

import json
import os
import re
import tomllib
from dataclasses import dataclass, field
from pathlib import Path

from ..errors import ConfigError
from ..models import WebhookRef

MIB = 1024 * 1024
DEFAULT_CHUNK_MB = 9.0  # limite Discord sans Nitro : 10 Mio par pièce jointe, on garde de la marge
MAX_CHUNK_MB = 499.0  # Nitro : 500 Mio
DEFAULT_DOWNLOAD_DIR = "~/Downloads/dscs"


@dataclass
class Settings:
    # Webhooks ajoutés à la main ; ceux du coffre sont dans le trousseau (partagés entre appareils).
    webhooks: list[WebhookRef] = field(default_factory=list)
    chunk_size: int = int(DEFAULT_CHUNK_MB * MIB)
    download_dir: Path = field(default_factory=lambda: Path(DEFAULT_DOWNLOAD_DIR).expanduser())
    show_hidden: bool = False
    # Noms lisibles des serveurs et salons Discord, par identifiant ([servers] et [channels] dans config.toml)
    server_names: dict[str, str] = field(default_factory=dict)
    channel_names: dict[str, str] = field(default_factory=dict)


def _names(raw: dict, table: str) -> dict[str, str]:
    value = raw.get(table) or {}
    if not isinstance(value, dict):
        raise ConfigError(f"[{table}] doit être une table : \"identifiant\" = \"nom\"")
    return {str(k): str(v) for k, v in value.items()}


def load_settings(path: Path) -> Settings:
    if not path.exists():
        raise ConfigError("Aucune config trouvée. Lance d'abord : dscs init")
    try:
        raw = tomllib.loads(path.read_text())
    except tomllib.TOMLDecodeError as e:
        raise ConfigError(f"{path} : TOML invalide ({e})") from None

    hooks = raw.get("webhooks") or []
    if isinstance(hooks, str):
        hooks = [hooks]
    refs: dict[str, WebhookRef] = {}
    for url in hooks:
        ref = WebhookRef.parse(str(url))
        refs.setdefault(ref.id, ref)

    try:
        mb = float(raw.get("chunk_size_mb", DEFAULT_CHUNK_MB))
    except (TypeError, ValueError):
        raise ConfigError("chunk_size_mb doit être un nombre") from None
    if not 0 < mb <= MAX_CHUNK_MB:
        raise ConfigError(f"chunk_size_mb doit être entre 0 et {MAX_CHUNK_MB:g} (actuellement {mb:g})")

    return Settings(
        webhooks=list(refs.values()),
        chunk_size=int(mb * MIB),
        download_dir=Path(str(raw.get("download_dir", DEFAULT_DOWNLOAD_DIR))).expanduser(),
        show_hidden=bool(raw.get("show_hidden", False)),
        server_names=_names(raw, "servers"),
        channel_names=_names(raw, "channels"),
    )


def save_settings(path: Path, webhook_urls: list[str]) -> None:
    """Écrit la config initiale, lisible par toi seul (elle contient les tokens des webhooks)."""
    path.parent.mkdir(parents=True, exist_ok=True)
    hooks = "".join(f"  {json.dumps(u.rstrip('/'))},\n" for u in webhook_urls)
    text = (
        "# DSCS — configuration\n"
        "# Plusieurs webhooks (un par salon) = envois en parallèle. Ceux du coffre sont aussi gardés avec ses clés :\n"
        "# un appareil qui rejoint le coffre les reçoit sans rien écrire ici.\n"
        "# Ne supprime jamais dans Discord un webhook déjà utilisé : ses morceaux deviendraient inaccessibles.\n"
        "# Pour en retirer un du coffre (et les fichiers qu'il porte) : dscs webhooks rm <nom ou identifiant>\n"
        f"webhooks = [\n{hooks}]\n\n"
        "# Taille des morceaux. Max 10 Mio sans Nitro (on garde de la marge).\n"
        f"chunk_size_mb = {DEFAULT_CHUNK_MB}\n\n"
        f'download_dir = "{DEFAULT_DOWNLOAD_DIR}"\n'
        "show_hidden = false\n\n"
        "# Discord ne donne pas aux webhooks le nom de leur serveur ni de leur salon.\n"
        "# Donne-leur un nom lisible ici (identifiants affichés par « dscs webhooks ») :\n"
        "[servers]\n"
        '# "118000000000000000" = "Perso"\n\n'
        "[channels]\n"
        '# "119000000000000000" = "stockage-1"\n'
    )
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as f:
        f.write(text)
    os.chmod(path, 0o600)  # si le fichier existait déjà avec d'autres droits


def remove_webhook_from_config(path: Path, webhook_id: str) -> bool:
    """Retire de config.toml les URLs de ce webhook, sans toucher au reste du fichier (commentaires compris).

    Renvoie False si le fichier n'en contient pas, ou s'il est écrit d'une façon qu'on ne sait pas modifier
    sans risque (il est alors laissé tel quel).
    """
    try:
        text = path.read_text()
        hooks = tomllib.loads(text).get("webhooks") or []
    except (OSError, tomllib.TOMLDecodeError):
        return False
    if not isinstance(hooks, list):
        return False
    urls = [str(u) for u in hooks if _webhook_id(str(u)) == webhook_id]
    if not urls:
        return False
    new = text
    for url in urls:
        quoted = "(?:\"" + re.escape(url) + "\"|'" + re.escape(url) + "')"
        # seul sur sa ligne (forme écrite par dscs init), sinon au milieu d'une liste sur une ligne
        new, n = re.subn(rf"(?m)^[ \t]*{quoted}[ \t]*,?[ \t]*(?:#[^\n]*)?\n", "", new)
        if not n:
            new = re.sub(rf"{quoted}[ \t]*,?[ \t]*", "", new)
    try:
        left = tomllib.loads(new).get("webhooks") or []
    except tomllib.TOMLDecodeError:
        return False
    if sorted(map(str, left)) != sorted(str(u) for u in hooks if str(u) not in urls):
        return False
    path.write_text(new)  # garde les droits du fichier (600 : il contient les tokens)
    return True


def _webhook_id(url: str) -> str | None:
    try:
        return WebhookRef.parse(url).id
    except ConfigError:
        return None
