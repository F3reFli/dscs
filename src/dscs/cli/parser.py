"""Définition des sous-commandes."""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

from .. import __version__
from ..utils import banner
from . import commands

KEYS_HELP = """\
Touches de l'interface :
  j/k ou ↑/↓  bouger       l/→  ouvrir     h/←  fermer / remonter
  espace      marquer      u    envoyer la sélection    Entrée  envoyer le fichier
  .  fichiers cachés       -    dossier parent          Tab     onglet Local/Distant
  (Distant) Entrée/l télécharger · x supprimer · r rafraîchir      q  quitter
  Fenêtres : j/k/h/l aussi (liste des webhooks, arbre de destination, boutons)
"""


class DSCSParser(argparse.ArgumentParser):
    """`dscs -h` commence par la bannière ; l'aide des sous-commandes (qui héritent de cette classe) non."""

    def __init__(self, *args, banner: bool = False, **kwargs):
        super().__init__(*args, **kwargs)
        self.banner = banner

    def print_help(self, file=None) -> None:
        if self.banner:
            banner.show(file or sys.stdout)
        super().print_help(file)


def build_parser() -> argparse.ArgumentParser:
    p = DSCSParser(
        banner=True,
        prog="dscs",
        description="DSCS — Discord Server Cloud Storage : stockage chiffré sur un serveur Discord.",
        epilog=KEYS_HELP,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    p.add_argument("-V", "--version", action="version", version=f"dscs {__version__}")
    sub = p.add_subparsers(dest="command", metavar="COMMANDE")

    s = sub.add_parser("tui", help="interface arbre de fichiers (commande par défaut : `dscs [DOSSIER]`)")
    s.add_argument("folder", nargs="?", type=Path, help="dossier de départ (défaut : dossier courant)")
    s.set_defaults(func=commands.cmd_tui)

    s = sub.add_parser("init", help="configurer les webhooks et générer la clé de chiffrement")
    s.add_argument("--force", action="store_true", help="réécrire une config existante (la clé est gardée)")
    s.set_defaults(func=commands.cmd_init)

    s = sub.add_parser("put", help="envoyer des fichiers/dossiers sans interface")
    s.add_argument("paths", nargs="+", type=Path, metavar="CHEMIN")
    s.add_argument(
        "-w", "--webhook", action="append", metavar="WEBHOOK", dest="webhooks",
        help="n'utiliser que ce webhook (identifiant ou nom, répétable ; défaut : tous) — voir « dscs webhooks »",
    )
    s.set_defaults(func=commands.cmd_put)

    s = sub.add_parser("ls", help="lister les fichiers stockés")
    s.set_defaults(func=commands.cmd_ls)

    s = sub.add_parser("get", help="récupérer un fichier")
    s.add_argument("id", type=int)
    s.add_argument("-o", "--out", type=Path, metavar="DOSSIER", help="défaut : download_dir de la config")
    s.add_argument("--flat", action="store_true", help="ne pas recréer le dossier distant sous la destination")
    s.set_defaults(func=commands.cmd_get)

    s = sub.add_parser("rm", help="supprimer un fichier de Discord")
    s.add_argument("id", type=int)
    s.add_argument("-y", "--yes", action="store_true", help="ne pas demander confirmation")
    s.set_defaults(func=commands.cmd_rm)

    s = sub.add_parser("gc", help="nettoyer les envois interrompus (morceaux orphelins sur Discord)")
    s.set_defaults(func=commands.cmd_gc)

    s = sub.add_parser("webhooks", help="lister les webhooks du coffre avec leur serveur et leur salon")
    s.set_defaults(func=commands.cmd_webhooks)

    s = sub.add_parser("device", help="appareils du coffre : identité, appairage, révocation, secours")
    s.set_defaults(func=commands.cmd_device_show)
    dev = s.add_subparsers(dest="device_command", metavar="ACTION")
    d = dev.add_parser("show", help="afficher l'identité de cet appareil (action par défaut)")
    d.set_defaults(func=commands.cmd_device_show)
    d = dev.add_parser("init", help="nouvel appareil : créer son identité avant de rejoindre un coffre")
    d.add_argument("--name", help="nom de l'appareil (défaut : nom de la machine)")
    d.set_defaults(func=commands.cmd_device_init)
    d = dev.add_parser("add", help="ajouter un appareil au coffre (depuis un appareil membre)")
    d.add_argument("public_key", metavar="CLE_PUBLIQUE", help="clé publique affichée par « dscs device init »")
    d.add_argument("--name", help="nom de l'appareil dans le coffre")
    d.add_argument("--code", help="code de vérification attendu (sinon demandé)")
    d.set_defaults(func=commands.cmd_device_add)
    d = dev.add_parser("invite", help="afficher l'invitation du coffre (adresse, sans aucune clé)")
    d.set_defaults(func=commands.cmd_device_invite)
    d = dev.add_parser(
        "join", help="rejoindre le coffre avec l'invitation reçue (ou suivre sa nouvelle adresse après une réparation)"
    )
    d.add_argument("invitation", nargs="?", metavar="INVITATION", help="sinon demandée (saisie masquée)")
    d.add_argument("--code", help="code de vérification attendu de l'appareil qui t'a ajouté")
    d.set_defaults(func=commands.cmd_device_join)
    d = dev.add_parser("list", help="lister les appareils membres du coffre")
    d.set_defaults(func=commands.cmd_device_list)
    d = dev.add_parser("revoke", help="retirer un appareil du coffre (nouvelle génération de clé)")
    d.add_argument("device", metavar="APPAREIL", help="nom, code ou identifiant (voir « dscs device list »)")
    d.add_argument("-y", "--yes", action="store_true", help="ne pas demander confirmation")
    d.set_defaults(func=commands.cmd_device_revoke)
    d = dev.add_parser("recover", help="rejoindre le coffre avec un kit de secours")
    d.add_argument("kit", nargs="?", metavar="KIT", help="sinon demandé (saisie masquée)")
    d.add_argument("--name", help="nom de cet appareil (défaut : nom de la machine)")
    d.add_argument("-y", "--yes", action="store_true", help="ne pas demander de reconnaître les membres")
    d.set_defaults(func=commands.cmd_device_recover)
    d = dev.add_parser("passphrase", help="ajouter, changer ou retirer la phrase de passe de cet appareil")
    d.add_argument("--remove", action="store_true", help="retirer la phrase de passe")
    d.set_defaults(func=commands.cmd_device_passphrase)

    s = sub.add_parser("key", help="clés du coffre : générations, rotation, kit de secours")
    s.set_defaults(func=commands.cmd_key_list)
    key = s.add_subparsers(dest="key_command", metavar="ACTION")
    k = key.add_parser("list", help="lister les générations de clés (action par défaut)")
    k.set_defaults(func=commands.cmd_key_list)
    k = key.add_parser("rotate", help="nouvelle génération de clé pour les prochains envois")
    k.set_defaults(func=commands.cmd_key_rotate)
    k = key.add_parser("recovery", help="créer un kit de secours (à garder hors ligne)")
    k.add_argument("-o", "--output", type=Path, metavar="FICHIER", help="écrire le kit dans ce fichier (droits 600)")
    k.set_defaults(func=commands.cmd_key_recovery)

    s = sub.add_parser("sync", help="synchroniser le coffre avec les autres appareils")
    s.add_argument(
        "--repair", action="store_true",
        help="recréer le point d'entrée du coffre s'il est introuvable (webhook déplacé, message supprimé)",
    )
    s.set_defaults(func=commands.cmd_sync)

    return p


COMMANDS = {"tui", "init", "put", "ls", "get", "rm", "gc", "webhooks", "device", "key", "sync"}
