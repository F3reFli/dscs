"""Point d'entrée `dscs`."""
from __future__ import annotations

import sys

from ..errors import DSCSError
from .parser import COMMANDS, build_parser


def main(argv: list[str] | None = None) -> int:
    argv = list(sys.argv[1:] if argv is None else argv)
    if argv[:1] == ["help"]:
        argv = ["--help"]
    elif not argv or (argv[0] not in COMMANDS and not argv[0].startswith("-")):
        argv.insert(0, "tui")  # `dscs` ou `dscs ~/Documents` : interface
    args = build_parser().parse_args(argv)
    try:
        return args.func(args)
    except DSCSError as e:
        print(f"Erreur : {e}", file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print("\nInterrompu.")
        return 130
