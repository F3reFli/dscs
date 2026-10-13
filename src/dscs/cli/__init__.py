"""Point d'entrée `dscs`."""
from __future__ import annotations

import re
import sys

from ..config import Paths
from ..errors import DSCSError
from . import logs
from .parser import COMMANDS, build_parser


def _first_word(argv: list[str]) -> int:
    """Position du premier argument après les options du journal (-v, -vv, --log <fichier>)."""
    i = 0
    while i < len(argv):
        if re.fullmatch(r"-v+|--verbose", argv[i]) or argv[i].startswith("--log="):
            i += 1
        elif argv[i] == "--log":
            i += 2
        else:
            break
    return i


def main(argv: list[str] | None = None) -> int:
    argv = list(sys.argv[1:] if argv is None else argv)
    i = _first_word(argv)
    if argv[i : i + 1] == ["help"]:
        argv[i : i + 1] = ["--help"]
    elif i == len(argv) or (argv[i] not in COMMANDS and not argv[i].startswith("-")):
        argv.insert(i, "tui")  # `dscs`, `dscs ~/Documents` ou `dscs -v` : interface
    args = build_parser().parse_args(argv)
    if args.command == "tui" and args.verbose and args.log is None:
        args.log = Paths.from_env().data_dir / "dscs.log"  # l'interface occupe le terminal : journal dans un fichier
    logs.setup(args.verbose, args.log)
    try:
        return args.func(args)
    except DSCSError as e:
        print(f"Erreur : {e}", file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print("\nInterrompu.")
        return 130
