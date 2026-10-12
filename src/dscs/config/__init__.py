"""Où sont les fichiers (Paths) et ce qu'ils contiennent (Settings)."""
from .paths import Paths
from .settings import DEFAULT_CHUNK_MB, MAX_CHUNK_MB, Settings, load_settings, save_settings

__all__ = ["DEFAULT_CHUNK_MB", "MAX_CHUNK_MB", "Paths", "Settings", "load_settings", "save_settings"]
