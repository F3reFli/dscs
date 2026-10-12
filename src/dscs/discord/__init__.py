"""Tout ce qui parle à Discord : HTTP résilient, webhooks."""
from .http import new_session
from .pool import WebhookPool
from .webhook import SentMessage, WebhookClient, is_official_url

__all__ = ["SentMessage", "WebhookClient", "WebhookPool", "is_official_url", "new_session"]
