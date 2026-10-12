"""Hiérarchie d'exceptions. Toute DSCSError est une erreur prévue : son message est montré tel quel."""
from __future__ import annotations


class DSCSError(Exception):
    """Erreur attendue (config, réseau, intégrité…), affichée à l'utilisateur par la CLI et la TUI."""


class ConfigError(DSCSError):
    """Config, clé ou webhook manquant ou invalide."""


class CryptoError(DSCSError):
    """Déchiffrement impossible : mauvaise clé ou morceau altéré."""


class IntegrityError(DSCSError):
    """Le fichier reconstitué ne correspond pas à l'original (index incomplet, hash différent…)."""


class IndexStateError(DSCSError):
    """Index SQLite illisible, d'une version inconnue, ou verrouillé par un autre processus."""


class DiscordError(DSCSError):
    """Discord a refusé la requête, ou reste injoignable après plusieurs tentatives."""

    def __init__(self, msg: str, status: int | None = None):
        super().__init__(msg)
        self.status = status
