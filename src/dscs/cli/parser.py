"""Définition des sous-commandes. L'aide (-h) est en anglais ; les exemples n'utilisent que des noms génériques."""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

from .. import __version__
from ..utils import banner
from . import commands

RAW = argparse.RawDescriptionHelpFormatter

# Résumé d'une ligne de chaque commande : `dscs -h` (par catégories) et en tête de `dscs <commande> -h`
SUMMARY = {
    "init": "set up webhooks, create the vault",
    "tui": "file-tree interface (or just: dscs)",
    "put": "upload files or folders",
    "ls": "list stored files with their IDs",
    "get": "download a file",
    "rm": "delete a file from Discord",
    "gc": "clean up interrupted uploads",
    "webhooks": "list webhooks: server, channel, chunks",
    "webhooks rm": "remove a webhook from the vault, everywhere",
    "bench": "measure upload speed (test data)",
    "sync": "sync the vault with your other devices",
    "device": "devices: pairing, revocation, recovery",
    "key": "vault keys: rotation, recovery kit",
}

GROUPS = [
    ("Getting started", [("init", "init"), ("tui [<folder>]", "tui")]),
    ("Files", [
        ("put <path>...", "put"), ("ls", "ls"), ("get <id>", "get"), ("rm <id>", "rm"), ("gc", "gc"),
    ]),
    ("Webhooks", [("webhooks", "webhooks"), ("webhooks rm <webhook>", "webhooks rm"), ("bench", "bench")]),
    ("Sharing & keys", [("sync", "sync"), ("device <action>", "device"), ("key <action>", "key")]),
]

MAIN_EXAMPLES = [
    ("dscs", "open the interface in the current folder"),
    ("dscs put <file> <folder>", "upload a file and a folder"),
    ("dscs get <id> -o <folder>", "download a file into <folder>"),
    ("dscs webhooks rm <webhook_name>", "remove a webhook from the vault"),
]

KEYS_HELP = """\
TUI keys:
  j/k  ↑/↓   move               space   mark
  l    →     open               u       upload marked items
  h    ←     close / go up      Enter   upload this file
  .          hidden files       -       parent folder
  Tab        Local/Remote tab   q       quit
  Remote tab: Enter/l download · x delete · r refresh
  Dialogs: j/k/h/l work too (webhook list, destination tree, buttons)
"""

COL = 36  # colonne des descriptions dans `dscs -h`
USAGE = "dscs [-h] [-V] [-v] [--log <file>] <command> [<args>]"


def _rows(rows: list[tuple[str, str]], col: int | None = None) -> str:
    """Deux colonnes ; `col` : colonne des descriptions (défaut : juste après la plus longue commande)."""
    col = col or max(len(left) for left, _ in rows) + 4
    return "".join(
        f"  {left:<{col - 2}}{right}\n" if len(left) + 2 <= col - 2 else f"  {left}\n{'':{col}}{right}\n"
        for left, right in rows
    )


def _examples(rows: list[tuple[str, str]], col: int | None = None) -> str:
    return "Examples:\n" + _rows(rows, col)


def main_help() -> str:
    parts = [
        f"usage: {USAGE}\n\n"
        "DSCS - Discord Server Cloud Storage: end-to-end encrypted storage on Discord.\n"
    ]
    for title, commands_ in GROUPS:
        parts.append(f"{title}:\n" + _rows([(usage, SUMMARY[name]) for usage, name in commands_], COL))
    parts.append("Options:\n" + _rows([
        ("-h, --help", "show this help"),
        ("-V, --version", "show the version"),
        ("-v, --verbose", "show what dscs does (-vv: every request)"),
        ("--log <file>", "write that log to <file>"),
    ], COL))
    parts.append(_examples(MAIN_EXAMPLES, COL))
    parts.append("Run `dscs <command> -h` for a command's options.\n")
    parts.append(KEYS_HELP)
    return "\n".join(parts)


WEBHOOKS_HELP = """\
The vault's webhooks: those in config.toml and those received from your other
devices (kept with the vault's keys).

Removing a URL from config.toml does not remove it from the vault: dscs reminds
you at each start until you run `dscs webhooks rm`.
"""

WEBHOOKS_RM_HELP = """\
Remove a webhook from the vault, everywhere: from the keyring (other devices
forget it at their next sync, and it never comes back, even if a config still
lists it), from the local cache and from config.toml.

Its chunks are lost: files with at least one chunk on it are deleted from the
vault, along with their chunks left on other webhooks (the list is shown before
confirmation). If the webhook still exists in Discord, its messages are deleted;
otherwise they stay in its channel. The vault's last webhook cannot be removed.
"""


class DSCSParser(argparse.ArgumentParser):
    """`dscs -h` : bannière puis aide par catégories ; les sous-commandes (même classe) gardent l'aide d'argparse."""

    def __init__(self, *args, top: bool = False, **kwargs):
        super().__init__(*args, **kwargs)
        self.top = top

    def format_help(self) -> str:
        return main_help() if self.top else super().format_help()

    def print_help(self, file=None) -> None:
        if self.top:
            banner.show(file or sys.stdout)
        super().print_help(file)


def _command(sub, name: str, examples: list[tuple[str, str]] | None = None, description: str | None = None):
    s = sub.add_parser(
        name.split()[-1], help=SUMMARY.get(name), description=description or SUMMARY[name].capitalize() + ".",
        epilog=_examples(examples) if examples else None, formatter_class=RAW,
    )
    _log_options(s, default=argparse.SUPPRESS)  # aussi après la commande : dscs put x -v
    return s


def _log_options(parser, default) -> None:
    parser.add_argument(
        "-v", "--verbose", action="count", default=0 if default is None else default,
        help="show what dscs does (-vv: every request and chunk)",
    )
    parser.add_argument(
        "--log", type=Path, metavar="<file>", default=default,
        help="write that log to <file> instead of the terminal",
    )


def _webhook_option(parser) -> None:
    parser.add_argument(
        "-w", "--webhook", action="append", metavar="<webhook>", dest="webhooks",
        help="only use this webhook (ID or name, repeatable; default: all) - see `dscs webhooks`",
    )


def _yes(parser) -> None:
    parser.add_argument("-y", "--yes", action="store_true", help="do not ask for confirmation")


def build_parser() -> argparse.ArgumentParser:
    p = DSCSParser(top=True, prog="dscs", usage=USAGE)
    p.add_argument("-V", "--version", action="version", version=f"dscs {__version__}")
    _log_options(p, default=None)
    sub = p.add_subparsers(dest="command", metavar="<command>")
    _files(sub)
    _webhooks(sub)
    _devices(sub)
    _keys(sub)
    return p


def _files(sub) -> None:
    s = _command(sub, "tui", [("dscs", "start in the current folder"), ("dscs <folder>", "start in <folder>")])
    s.add_argument("folder", nargs="?", type=Path, metavar="<folder>", help="start folder (default: current folder)")
    s.set_defaults(func=commands.cmd_tui)

    s = _command(sub, "init")
    s.add_argument("--force", action="store_true", help="rewrite an existing config (keys are kept)")
    s.set_defaults(func=commands.cmd_init)

    s = _command(sub, "put", [
        ("dscs put <file> <folder>", "upload a file and a folder"),
        ("dscs put <file> -w <webhook_name>", "only use one webhook"),
    ])
    s.add_argument("paths", nargs="+", type=Path, metavar="<path>", help="file or folder to upload")
    _webhook_option(s)
    s.set_defaults(func=commands.cmd_put)

    s = _command(sub, "ls")
    s.set_defaults(func=commands.cmd_ls)

    s = _command(sub, "get", [
        ("dscs get <id>", "into download_dir (config.toml)"),
        ("dscs get <id> -o <folder> --flat", "straight into <folder>"),
    ])
    s.add_argument("id", type=int, metavar="<id>", help="file ID (see `dscs ls`)")
    s.add_argument("-o", "--out", type=Path, metavar="<folder>", help="destination (default: download_dir)")
    s.add_argument("--flat", action="store_true", help="do not recreate the remote folder under the destination")
    s.set_defaults(func=commands.cmd_get)

    s = _command(sub, "rm", [("dscs rm <id>", "delete a file (asks for confirmation)")])
    s.add_argument("id", type=int, metavar="<id>", help="file ID (see `dscs ls`)")
    _yes(s)
    s.set_defaults(func=commands.cmd_rm)

    s = _command(sub, "gc")
    s.set_defaults(func=commands.cmd_gc)

    s = _command(sub, "sync")
    s.add_argument(
        "--repair", action="store_true",
        help="recreate the vault's entry point if it cannot be found (webhook moved, message deleted)",
    )
    s.set_defaults(func=commands.cmd_sync)


def _webhooks(sub) -> None:
    s = _command(sub, "webhooks", [
        ("dscs webhooks", "server, channel, chunks and link of each"),
        ("dscs webhooks rm <webhook_name>", "remove one (asks for confirmation)"),
    ], description=WEBHOOKS_HELP)
    s.set_defaults(func=commands.cmd_webhooks)
    hooks = s.add_subparsers(dest="webhooks_command", metavar="<action>")
    w = hooks.add_parser("list", help="list the vault's webhooks (default action)", formatter_class=RAW,
                         description="List the vault's webhooks: server, channel, stored chunks, link.")
    w.set_defaults(func=commands.cmd_webhooks)
    w = _command(hooks, "webhooks rm", [
        ("dscs webhooks rm <webhook_name>", "by name (case-insensitive)"),
        ("dscs webhooks rm <webhook_id> -y", "by ID, without confirmation"),
    ], description=WEBHOOKS_RM_HELP)
    w.add_argument("webhook", metavar="<webhook>", help="webhook ID or name (see `dscs webhooks`)")
    _yes(w)
    w.add_argument(
        "--repair", action="store_true",
        help="if it holds the vault's entry point, recreate it elsewhere even if other devices use it",
    )
    w.set_defaults(func=commands.cmd_webhooks_rm)

    s = _command(sub, "bench", [
        ("dscs bench", "100 MB over all webhooks"),
        ("dscs bench -s 50 -w <webhook_name>", "50 MB over one webhook"),
        ("dscs bench -p 2 --download", "2 uploads at a time, then download"),
    ])
    s.add_argument("-s", "--size", type=float, default=100.0, metavar="<mb>", help="amount in MB (default: 100)")
    _webhook_option(s)
    s.add_argument(
        "-p", "--parallel", type=int, default=1, metavar="<n>",
        help="simultaneous uploads per webhook (default: 1, like dscs put)",
    )
    s.add_argument("--download", action="store_true", help="also measure downloads (read back before deletion)")
    _yes(s)
    s.set_defaults(func=commands.cmd_bench)


def _devices(sub) -> None:
    s = _command(sub, "device", [
        ("dscs device init", "on the new device: create its identity"),
        ("dscs device add <public_key>", "on a member device: add it, get an invitation"),
        ("dscs device join <invitation>", "on the new device: join the vault"),
    ])
    s.set_defaults(func=commands.cmd_device_show)
    dev = s.add_subparsers(dest="device_command", metavar="<action>")

    def action(name: str, text: str):
        return dev.add_parser(name, help=text, description=text[0].upper() + text[1:] + ".", formatter_class=RAW)

    d = action("show", "show this device's identity (default action)")
    d.set_defaults(func=commands.cmd_device_show)
    d = action("init", "new device: create its identity before joining a vault")
    d.add_argument("--name", metavar="<name>", help="device name (default: host name)")
    d.set_defaults(func=commands.cmd_device_init)
    d = action("add", "add a device to the vault (from a member device)")
    d.add_argument("public_key", metavar="<public_key>", help="public key shown by `dscs device init`")
    d.add_argument("--name", metavar="<name>", help="device name in the vault")
    d.add_argument("--code", metavar="<code>", help="expected verification code (asked otherwise)")
    d.set_defaults(func=commands.cmd_device_add)
    d = action("invite", "show the vault's invitation (its address, no keys)")
    d.set_defaults(func=commands.cmd_device_invite)
    d = action("join", "join the vault with an invitation (or follow its new address)")
    d.add_argument("invitation", nargs="?", metavar="<invitation>", help="asked otherwise (hidden input)")
    d.add_argument("--code", metavar="<code>", help="expected verification code of the device that added you")
    d.set_defaults(func=commands.cmd_device_join)
    d = action("list", "list the vault's member devices")
    d.set_defaults(func=commands.cmd_device_list)
    d = action("revoke", "remove a device from the vault (new key generation)")
    d.add_argument("device", metavar="<device>", help="name, code or ID (see `dscs device list`)")
    _yes(d)
    d.set_defaults(func=commands.cmd_device_revoke)
    d = action("recover", "join the vault with a recovery kit")
    d.add_argument("kit", nargs="?", metavar="<kit>", help="asked otherwise (hidden input)")
    d.add_argument("--name", metavar="<name>", help="name of this device (default: host name)")
    d.add_argument("-y", "--yes", action="store_true", help="do not ask to recognize the members")
    d.set_defaults(func=commands.cmd_device_recover)
    d = action("passphrase", "add, change or remove this device's passphrase")
    d.add_argument("--remove", action="store_true", help="remove the passphrase")
    d.set_defaults(func=commands.cmd_device_passphrase)


def _keys(sub) -> None:
    s = _command(sub, "key", [
        ("dscs key", "list key generations"),
        ("dscs key recovery -o <file>", "write a recovery kit to <file> (keep it offline)"),
    ])
    s.set_defaults(func=commands.cmd_key_list)
    key = s.add_subparsers(dest="key_command", metavar="<action>")
    k = key.add_parser("list", help="list key generations (default action)", formatter_class=RAW,
                       description="List the vault's key generations.")
    k.set_defaults(func=commands.cmd_key_list)
    k = key.add_parser("rotate", help="new key generation for future uploads", formatter_class=RAW,
                       description="Create a new key generation for future uploads, shared with every member.")
    k.set_defaults(func=commands.cmd_key_rotate)
    k = key.add_parser("recovery", help="create a recovery kit (keep it offline)", formatter_class=RAW,
                       description="Create a recovery kit: it can rejoin the vault without any other device.")
    k.add_argument("-o", "--output", type=Path, metavar="<file>", help="write the kit to this file (mode 600)")
    k.set_defaults(func=commands.cmd_key_recovery)


COMMANDS = {"tui", "init", "put", "ls", "get", "rm", "gc", "webhooks", "bench", "device", "key", "sync"}
