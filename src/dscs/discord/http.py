"""Session HTTP et requêtes résilientes : rate limits (429), erreurs 5xx, coupures réseau."""
from __future__ import annotations

import asyncio
from collections.abc import Callable
from typing import Any, Literal

import aiohttp

from .. import __version__
from ..errors import DiscordError

USER_AGENT = f"DSCS (https://github.com/F3reFli, {__version__})"
MAX_ATTEMPTS = 8
MAX_BACKOFF = 30.0

Expect = Literal["json", "bytes"]


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
) -> Any:
    """Requête avec retry. `build` recrée les arguments à chaque essai (FormData n'est pas réutilisable).

    429 : attend `retry_after` puis réessaie. 5xx et coupures : backoff exponentiel.
    Autre 4xx : DiscordError immédiate (avec `status`). 204 : renvoie None.
    """
    last: Exception | None = None
    backoff = 1.0
    for _ in range(attempts):
        kwargs = build() if build else {}
        try:
            async with session.request(method, url, **kwargs) as r:
                if r.status == 429:
                    try:
                        data = await r.json(content_type=None)
                        wait = float(data.get("retry_after", 1))
                    except Exception:
                        wait = float(r.headers.get("Retry-After", 1))
                    await asyncio.sleep(wait + 0.1)
                    continue
                if r.status >= 500:
                    last = DiscordError(f"Discord a répondu {r.status}", r.status)
                    await asyncio.sleep(backoff)
                    backoff = min(backoff * 2, MAX_BACKOFF)
                    continue
                if r.status >= 400:
                    text = (await r.text())[:300]
                    raise DiscordError(f"Discord a répondu {r.status} : {text}", r.status)
                # respecte le bucket avant qu'il ne déborde
                if r.headers.get("X-RateLimit-Remaining") == "0":
                    reset = r.headers.get("X-RateLimit-Reset-After")
                    if reset:
                        await asyncio.sleep(float(reset))
                if r.status == 204:
                    return None
                return await r.json() if expect == "json" else await r.read()
        except (TimeoutError, aiohttp.ClientError) as e:
            last = e
            await asyncio.sleep(backoff)
            backoff = min(backoff * 2, MAX_BACKOFF)
    raise DiscordError(f"Échec après {attempts} tentatives : {last}")
