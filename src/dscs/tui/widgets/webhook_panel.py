"""Onglet Distant : les webhooks du coffre, avec leur serveur et leur salon Discord."""
from __future__ import annotations

from rich.table import Table
from rich.text import Text
from textual.widgets import Static

from ...models import WebhookInfo


class WebhookPanel(Static):
    """Résumé non focalisable au-dessus de la liste des fichiers.

    Discord ne donne pas aux webhooks le nom de leur serveur ni de leur salon : on affiche leurs identifiants,
    ou les noms donnés dans config.toml ([servers], [channels]), et un lien qui ouvre le salon.
    """

    infos: list[WebhookInfo] = []

    def show(self, infos: list[WebhookInfo]) -> None:
        self.infos = infos
        if not infos:
            self.update(Text("Aucun webhook dans le coffre.", style="dim"))
            return
        table = Table(box=None, expand=True, pad_edge=False, header_style="bold")
        table.add_column("Webhook", no_wrap=True)
        table.add_column("Serveur", overflow="ellipsis")
        table.add_column("Salon", overflow="ellipsis")
        table.add_column("Morceaux", justify="right")
        table.add_column("", no_wrap=True)
        for i in infos:
            link = Text("ouvrir ↗", style=f"underline link {i.link}") if i.link else Text("")
            table.add_row(
                i.display_name,
                Text(i.server_detail, style="" if i.server_label else "dim"),
                Text(i.channel_detail, style="" if i.channel_label else "dim"),
                str(i.chunks),
                link,
            )
        self.update(table)
