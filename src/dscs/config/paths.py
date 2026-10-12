"""Emplacements des fichiers de DSCS, résolus à l'exécution (jamais à l'import)."""
from __future__ import annotations

import os
from collections.abc import Mapping
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Paths:
    config_dir: Path  # config.toml, device.json, keyring.sealed : secrets, à sauvegarder
    data_dir: Path  # index.db : la carte des fichiers, à sauvegarder aussi

    @property
    def config_file(self) -> Path:
        return self.config_dir / "config.toml"

    @property
    def device_file(self) -> Path:
        """Identité de cet appareil (clé privée X25519, éventuellement protégée par phrase de passe)."""
        return self.config_dir / "device.json"

    @property
    def keyring_file(self) -> Path:
        """Trousseau du coffre (toutes les clés de coffre, membres), scellé pour cet appareil."""
        return self.config_dir / "keyring.sealed"

    @property
    def key_file(self) -> Path:
        """Ancienne clé unique en clair (dscs ≤ 0.2) : migrée dans le trousseau puis effacée."""
        return self.config_dir / "key"

    @property
    def index_file(self) -> Path:
        return self.data_dir / "index.db"

    @property
    def lock_file(self) -> Path:
        return self.data_dir / "index.lock"

    @classmethod
    def from_env(cls, env: Mapping[str, str] | None = None) -> Paths:
        """DSCS_HOME (tout au même endroit), sinon les dossiers XDG."""
        env = os.environ if env is None else env
        if home := env.get("DSCS_HOME"):
            p = Path(home).expanduser()
            return cls(p, p)
        config = Path(env.get("XDG_CONFIG_HOME") or "~/.config").expanduser() / "dscs"
        data = Path(env.get("XDG_DATA_HOME") or "~/.local/share").expanduser() / "dscs"
        return cls(config, data)
