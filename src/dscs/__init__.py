"""DSCS — Discord Server Cloud Storage : stockage chiffré sur un serveur Discord."""
import logging

__version__ = "0.4.2"

# Journal muet tant que la CLI ne l'active pas (-v, --log) : rien ne s'écrit par défaut, ni dans l'interface
logging.getLogger(__name__).addHandler(logging.NullHandler())
