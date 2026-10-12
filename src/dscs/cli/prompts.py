"""Saisies interactives : confirmations, phrases de passe."""
from __future__ import annotations

import getpass
import os
import sys

from ..errors import DSCSError

PASSPHRASE_ENV = "DSCS_PASSPHRASE"
MIN_PASSPHRASE = 8


def confirm(question: str, default: bool = False) -> bool:
    hint = "[O/n]" if default else "[o/N]"
    answer = input(f"{question} {hint} ").strip().lower()
    if not answer:
        return default
    return answer in ("o", "oui", "y", "yes")


def passphrase() -> str:
    """Phrase de passe de cet appareil : variable DSCS_PASSPHRASE, sinon saisie masquée."""
    if env := os.environ.get(PASSPHRASE_ENV):
        return env
    if not sys.stdin.isatty():
        raise DSCSError(
            "Cet appareil est protégé par une phrase de passe : "
            f"lance dscs dans un terminal, ou définis {PASSPHRASE_ENV}."
        )
    return getpass.getpass("Phrase de passe de cet appareil : ")


def new_passphrase() -> str:
    """Nouvelle phrase de passe, saisie deux fois."""
    if not sys.stdin.isatty():
        raise DSCSError("Choisir une phrase de passe nécessite un terminal.")
    first = getpass.getpass(f"Nouvelle phrase de passe ({MIN_PASSPHRASE} caractères minimum) : ")
    if len(first) < MIN_PASSPHRASE:
        raise DSCSError(
            f"Phrase de passe trop courte (au moins {MIN_PASSPHRASE} caractères ; "
            "une phrase de 4 à 6 mots est idéale)."
        )
    if getpass.getpass("Confirme-la : ") != first:
        raise DSCSError("Les deux saisies ne correspondent pas : rien n'a été changé.")
    return first


def ask(question: str, default: str = "") -> str:
    """Question libre ; hors terminal, la valeur par défaut."""
    if not sys.stdin.isatty():
        return default
    answer = input(f"{question}{f' [{default}]' if default else ''} : ").strip()
    return answer or default


def secret(question: str) -> str:
    """Texte sensible (invitation, kit de secours) : saisie masquée, ou lu sur l'entrée standard."""
    if sys.stdin.isatty():
        return getpass.getpass(question)
    return sys.stdin.readline().strip()


def device_passphrase() -> str | None:
    """Phrase de passe d'un nouvel appareil, proposée (recommandée) seulement dans un terminal."""
    if not sys.stdin.isatty():
        return None
    print("La clé privée de cet appareil protège toutes les clés du coffre.")
    if confirm("La protéger par une phrase de passe ? (recommandé)", default=True):
        return new_passphrase()
    return None


def same_code(a: str, b: str) -> bool:
    return "".join(ch for ch in a if ch.isdigit()) == "".join(ch for ch in b if ch.isdigit())
