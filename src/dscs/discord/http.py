"""Session HTTP et requêtes résilientes : rate limits (429), erreurs 5xx, coupures réseau."""
from __future__ import annotations

import asyncio
import logging
import time
from collections.abc import Callable
from dataclasses import dataclass
from typing import Any, Literal

import aiohttp

from .. import __version__
from ..errors import DiscordError
from ..utils import human, redact_secrets

USER_AGENT = f"DSCS (https://github.com/F3reFli, {__version__})"
log = logging.getLogger(__name__)

MAX_ATTEMPTS = 8
MAX_BACKOFF = 30.0

Expect = Literal["json", "bytes"]


@dataclass
class Throttle:
    """Freinages imposés par Discord sur un webhook : réponses 429 et attentes de son quota de requêtes."""

    hits: int = 0  # réponses 429
    waited: float = 0.0  # secondes passées à attendre (429 et quota épuisé)


def new_session() -> aiohttp.ClientSession:
    return aiohttp.ClientSession(
        timeout=aiohttp.ClientTimeout(total=None, sock_connect=30, sock_read=180),
        trust_env=True,
        headers={"User-Agent": USER_AGENT},
    )


async def request(
    session: aiohttp.ClientSession,
    method: str,
    url: str,
    *,
    build: Callable[[], dict[str, Any]] | None = None,
    expect: Expect = "json",
    attempts: int = MAX_ATTEMPTS,
    throttle: Throttle | None = None,
) -> Any:
    """Requête avec retry. `build` recrée les arguments à chaque essai (FormData n'est pas réutilisable).

    429 : attend `retry_after` puis réessaie. 5xx et coupures : backoff exponentiel.
    Autre 4xx : DiscordError immédiate (avec `status`). 204 : renvoie None.
    """
    last: Exception | None = None
    backoff = 1.0
    route = redact_secrets(url.split("://", 1)[-1])  # pour le journal : jamais le token ni la signature
    for attempt in range(1, attempts + 1):
        kwargs = build() if build else {}
        t0 = time.monotonic()
        try:
            async with session.request(method, url, **kwargs) as r:
                if r.status == 429:
                    try:
                        data = await r.json(content_type=None)
                        wait = float(data.get("retry_after", 1))
                    except Exception:
                        wait = float(r.headers.get("Retry-After", 1))
                    if throttle:
                        throttle.hits += 1
                        throttle.waited += wait + 0.1
                    log.info("%s %s : 429, Discord demande d'attendre %.2f s (essai %d/%d)",
                             method, route, wait, attempt, attempts)
                    await asyncio.sleep(wait + 0.1)
                    continue
                if r.status >= 500:
                    last = DiscordError(f"Discord a répondu {r.status}", r.status)
                    log.info("%s %s : %d, nouvel essai dans %.0f s (essai %d/%d)",
                             method, route, r.status, backoff, attempt, attempts)
                    await asyncio.sleep(backoff)
                    backoff = min(backoff * 2, MAX_BACKOFF)
                    continue
                if r.status >= 400:
                    text = (await r.text())[:300]
                    log.debug("%s %s → %d en %.0f ms", method, route, r.status, _ms(t0))
                    raise DiscordError(f"Discord a répondu {r.status} : {text}", r.status)
                result = None if r.status == 204 else await r.json() if expect == "json" else await r.read()
                size = f", {human(len(result))}" if isinstance(result, bytes) else ""
                log.debug("%s %s → %d en %.0f ms%s", method, route, r.status, _ms(t0), size)
                # respecte le bucket avant qu'il ne déborde
                if r.headers.get("X-RateLimit-Remaining") == "0":
                    reset = r.headers.get("X-RateLimit-Reset-After")
                    if reset:
                        if throttle:
                            throttle.waited += float(reset)
                        log.info("quota de requêtes épuisé (%s) : attente %.2f s", route, float(reset))
                        await asyncio.sleep(float(reset))
                return result
        except (TimeoutError, aiohttp.ClientError) as e:
            last = e
            log.info("%s %s : %s, nouvel essai dans %.0f s (essai %d/%d)",
                     method, route, type(e).__name__, backoff, attempt, attempts)
            await asyncio.sleep(backoff)
            backoff = min(backoff * 2, MAX_BACKOFF)
    reason = redact_secrets(str(last))  # une erreur réseau peut citer l'URL complète
    log.warning("%s %s : abandon après %d tentatives (%s)", method, route, attempts, reason)
    raise DiscordError(f"Échec après {attempts} tentatives : {reason}")


def _ms(t0: float) -> float:
    return (time.monotonic() - t0) * 1000
