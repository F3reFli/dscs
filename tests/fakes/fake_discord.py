"""Faux serveur de webhooks Discord pour tester dscs sans toucher à Discord.

Simule : limite de 10 Mio par pièce jointe (413), rate limit (429 aléatoires),
liens CDN signés qui expirent (le lien renvoyé à l'envoi est déjà expiré).
`app[CONTROL]["deny_deletes"] = True` fait échouer les suppressions (403) pour tester la reprise par gc.
`app[CONTROL]["channels"][wid] = "…"` déplace un webhook dans un autre salon, comme dans les paramètres Discord :
comme sur le vrai Discord, un webhook ne retrouve plus alors les messages restés dans son ancien salon.
`app[CONTROL]["deleted"].add(wid)` supprime un webhook : toute requête par lui répond 404 (Unknown Webhook).
`app[CONTROL]["rate_limit"] = False` coupe les 429 simulés (mesures de temps reproductibles).
`app[CONTROL]["latency"] = 0.2` : chaque envoi prend ce temps (comme un vrai envoi de plusieurs Mo) ;
`app[STATS]["max_inflight"]` et `["max_inflight_by_webhook"]` comptent alors les envois simultanés.
"""
import asyncio
import itertools
import json
import time

from aiohttp import web

MAX_ATTACHMENT = 10 * 1024 * 1024

STORE = web.AppKey("store", dict)  # message_id -> {"data": bytes, "name": str, "flags": int}
STATS = web.AppKey("stats", dict)
CONTROL = web.AppKey("control", dict)  # modifiable pendant que le serveur tourne


def make_app(rate_limit_every: int = 7) -> web.Application:
    store: dict[str, dict] = {}  # message_id -> {"data": bytes, "name": str, "flags": int}
    ids = itertools.count(1_000_000)
    counter = itertools.count(1)
    stats = {"posts": 0, "429": 0, "expired_hits": 0, "fresh_fetch": 0, "deletes": 0, "edits": 0,
             "max_inflight": 0, "max_inflight_by_webhook": {}}
    inflight: dict[str, int] = {}
    control = {"deny_deletes": False, "channels": {}, "deleted": set(), "latency": 0.0, "rate_limit": True}

    def att_url(req, mid, expired):
        ex = int(time.time()) + (-10 if expired else 3600)
        return f"http://{req.host}/attachments/{mid}/{store[mid]['name']}?ex={ex:x}&hm=sig"

    def channel_of(req, wid):
        return req.app[CONTROL]["channels"].get(wid, f"42{wid}")

    def visible(req, mid):
        """Un webhook ne voit que ses propres messages, et seulement dans son salon actuel."""
        wid = req.match_info["wid"]
        m = store.get(mid)
        return m is not None and m["wid"] == wid and m["channel"] == channel_of(req, wid)

    def unknown():
        return web.json_response({"message": "Unknown Message", "code": 10008}, status=404)

    def message(req, mid, expired=False):
        m = store[mid]
        return {
            "id": mid, "channel_id": m["channel"], "content": "", "flags": m["flags"],
            "attachments": [{"id": mid, "filename": m["name"], "size": len(m["data"]),
                             "url": att_url(req, mid, expired)}],
        }

    def maybe_429():
        if rate_limit_every and control["rate_limit"] and next(counter) % rate_limit_every == 0:
            stats["429"] += 1
            return web.json_response({"message": "You are being rate limited.", "retry_after": 0.05,
                                      "global": False}, status=429)
        return None

    async def info(req):
        wid = req.match_info["wid"]
        return web.json_response(
            {"id": wid, "type": 1, "name": f"Stockage {wid}", "guild_id": "4242", "channel_id": channel_of(req, wid)}
        )

    async def post(req):
        if (r := maybe_429()):
            return r
        assert req.query.get("wait") == "true", "wait=true manquant"
        reader = await req.multipart()
        payload, data, name = None, None, None
        async for part in reader:
            if part.name == "payload_json":
                payload = json.loads(await part.text())
            elif part.name == "files[0]":
                name = part.filename
                data = await part.read()
        if data is None:
            return web.json_response({"message": "no file"}, status=400)
        if len(data) > MAX_ATTACHMENT:
            return web.json_response({"message": "Request entity too large", "code": 40005}, status=413)
        wid = req.match_info["wid"]
        if latency := req.app[CONTROL]["latency"]:
            inflight[wid] = inflight.get(wid, 0) + 1
            stats["max_inflight"] = max(stats["max_inflight"], sum(inflight.values()))
            by_hook = stats["max_inflight_by_webhook"]
            by_hook[wid] = max(by_hook.get(wid, 0), inflight[wid])
            try:
                await asyncio.sleep(latency)
            finally:
                inflight[wid] -= 1
        mid = str(next(ids))
        store[mid] = {"data": data, "name": name, "flags": (payload or {}).get("flags", 0), "wid": wid,
                      "channel": channel_of(req, wid)}
        stats["posts"] += 1
        # lien déjà expiré : le client ne doit jamais l'utiliser tel quel
        return web.json_response(message(req, mid, expired=True),
                                 headers={"X-RateLimit-Remaining": "4", "X-RateLimit-Reset-After": "0.1"})

    async def get_msg(req):
        if (r := maybe_429()):
            return r
        mid = req.match_info["mid"]
        if not visible(req, mid):
            return unknown()
        stats["fresh_fetch"] += 1
        return web.json_response(message(req, mid))

    async def patch(req):
        if (r := maybe_429()):
            return r
        mid = req.match_info["mid"]
        if not visible(req, mid):
            return unknown()
        reader = await req.multipart()
        data, name = None, None
        async for part in reader:
            if part.name == "files[0]":
                name = part.filename
                data = await part.read()
        if data is not None:
            if len(data) > MAX_ATTACHMENT:
                return web.json_response({"message": "Request entity too large", "code": 40005}, status=413)
            store[mid]["data"], store[mid]["name"] = data, name
        stats["edits"] += 1
        return web.json_response(message(req, mid, expired=True))

    async def delete(req):
        if req.app[CONTROL]["deny_deletes"]:
            return web.json_response({"message": "Missing Permissions", "code": 50013}, status=403)
        mid = req.match_info["mid"]
        if not visible(req, mid):
            return unknown()
        del store[mid]
        stats["deletes"] += 1
        return web.Response(status=204)

    async def attachment(req):
        mid = req.match_info["mid"]
        if int(req.query.get("ex", "0"), 16) < time.time():
            stats["expired_hits"] += 1
            return web.Response(status=404, text="This content is no longer available.")
        if mid not in store:
            return web.Response(status=404)
        return web.Response(body=store[mid]["data"], content_type="application/octet-stream")

    @web.middleware
    async def deleted_webhooks(req, handler):
        if req.match_info.get("wid") in req.app[CONTROL]["deleted"]:
            return web.json_response({"message": "Unknown Webhook", "code": 10015}, status=404)
        return await handler(req)

    app = web.Application(client_max_size=64 * 1024 * 1024, middlewares=[deleted_webhooks])
    base = "/api/webhooks/{wid}/{tok}"
    app.router.add_get(base, info)
    app.router.add_post(base, post)
    app.router.add_get(base + "/messages/{mid}", get_msg)
    app.router.add_patch(base + "/messages/{mid}", patch)
    app.router.add_delete(base + "/messages/{mid}", delete)
    app.router.add_get("/attachments/{mid}/{name}", attachment)
    app[STORE], app[STATS] = store, stats
    app[CONTROL] = control
    return app
