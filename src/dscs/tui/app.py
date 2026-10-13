"""Application Textual : assemble les onglets et relie les actions au Vault."""
from __future__ import annotations

import asyncio
from pathlib import Path

from textual import on, work
from textual.app import App, ComposeResult
from textual.binding import Binding
from textual.screen import ModalScreen
from textual.widgets import Footer, Header, TabbedContent, TabPane

from ..errors import DSCSError
from ..models import StoredFile, UploadPlan
from ..services import Vault
from ..utils import human
from .screens import ConfirmScreen, DownloadChoice, DownloadScreen, TransferScreen, UploadScreen
from .widgets import RemoteTable, VimTree, WebhookPanel


class DSCSApp(App):
    TITLE = "DSCS"
    SUB_TITLE = "Discord Server Cloud Storage"
    CSS_PATH = "app.tcss"

    BINDINGS = [
        Binding("q", "quit", "Quitter"),
        Binding("tab", "next_tab", "Local ⇄ Distant", priority=True),
        Binding("1", "show_tab('local')", show=False),
        Binding("2", "show_tab('remote')", show=False),
    ]

    def __init__(self, start: Path, vault: Vault) -> None:
        super().__init__()
        self.start = start
        self.vault = vault
        self.upload_webhooks: set[str] | None = None  # dernier choix de webhooks (pendant la session)
        self.download_dir: Path | None = None  # dernier dossier de téléchargement choisi

    def compose(self) -> ComposeResult:
        yield Header()
        with TabbedContent(initial="local"):
            with TabPane("Local", id="local"):
                yield VimTree(self.start, show_hidden=self.vault.settings.show_hidden)
            with TabPane("Distant", id="remote"):
                yield WebhookPanel()
                yield RemoteTable()
        yield Footer()

    async def on_mount(self) -> None:
        # pas d'écriture sur le terminal pendant que l'interface l'occupe : avertissements en notification
        self.vault.notify = lambda message: self.notify(message, severity="warning", timeout=20)
        for message in self.vault.notices:
            self.vault.notify(message)
        await self.vault.start()
        self.reload_remote()
        self.query_one(VimTree).focus()
        self.initial_sync()
        if not self.vault.has_key:
            self.notify(
                "Aucune clé de coffre sur cet appareil : envoi et téléchargement impossibles "
                "(« dscs init » ou « dscs device join »)",
                severity="error",
                timeout=10,
            )
        elif n := self.vault.unreadable_count():
            self.notify(f"{n} fichier(s) chiffré(s) avec une clé absente de ce trousseau (✗)", severity="warning")
        if n := self.vault.interrupted_count():
            self.notify(f"{n} envoi(s) interrompu(s) : lance « dscs gc » pour nettoyer", severity="warning")

    async def on_unmount(self) -> None:
        try:
            await self.vault.flush()
        except DSCSError:
            pass  # publié à la prochaine synchronisation : l'état local est conservé
        await self.vault.stop()

    @work(exclusive=True, group="sync")
    async def initial_sync(self) -> None:
        """Récupère ce que les autres appareils ont publié, puis le nom des webhooks."""
        try:
            report = await self.vault.pull()
        except DSCSError as e:
            self.notify(f"Synchronisation impossible : {e}", severity="error", timeout=10)
        else:
            if report and report.files_added:
                self.notify(f"{report.files_added} fichier(s) reçu(s) d'autres appareils")
            self.reload_remote()
        try:
            infos = await self.vault.refresh_webhook_info()
        except DSCSError:
            infos = self.vault.webhook_infos()
        self.sub_title = "→ " + ", ".join(i.display_name for i in infos)
        self.reload_remote()

    async def publish(self) -> None:
        """Partage les modifications avec les autres appareils du coffre."""
        try:
            await self.vault.flush()
        except DSCSError as e:
            self.notify(f"Publication sur le coffre impossible ({e}) : relance « dscs sync »", severity="warning")

    # -- onglets
    def action_show_tab(self, tab: str) -> None:
        self.query_one(TabbedContent).active = tab
        (self.query_one(VimTree) if tab == "local" else self.query_one(RemoteTable)).focus()

    def action_next_tab(self) -> None:
        if isinstance(self.screen, ModalScreen):
            self.screen.focus_next()  # dans une fenêtre : Tab passe d'un champ à l'autre
            return
        active = self.query_one(TabbedContent).active
        self.action_show_tab("remote" if active == "local" else "local")

    def reload_remote(self) -> None:
        infos = self.vault.webhook_infos()
        self.query_one(WebhookPanel).show(infos)
        names = {i.id: i.display_name for i in infos}
        per_file = {fid: ", ".join(names.get(w, w) for w in ws) for fid, ws in self.vault.file_webhooks().items()}
        readable = self.vault.can_read if self.vault.has_key else (lambda r: True)
        self.query_one(RemoteTable).fill(self.vault.files(), readable, per_file)

    # -- envoi
    @on(VimTree.SendRequest)
    def ask_upload(self, event: VimTree.SendRequest) -> None:
        try:
            plan = self.vault.plan_upload(event.paths)
        except OSError as e:
            self.notify(f"Lecture impossible : {e}", severity="error")
            return
        if not plan.items:
            self.notify("Rien à envoyer (dossier vide ?)", severity="warning")
            return
        lines = [
            f"{(i.folder + '/') if i.folder else ''}{i.path.name}   {human(i.size)}" for i in plan.items[:14]
        ]
        if len(plan.items) > 14:
            lines.append(f"… et {len(plan.items) - 14} autre(s)")
        summary = (
            f"{len(plan.items)} fichier(s) · {human(plan.total_bytes)} · {plan.total_chunks} morceau(x) chiffré(s)"
        )
        infos = self.vault.webhook_infos()
        if not infos:
            self.notify("Aucun webhook dans le coffre : ajoute-en un dans config.toml.", severity="error")
            return

        def done(webhooks: list[str] | None) -> None:
            if webhooks:
                self.upload_webhooks = set(webhooks)
                self.start_upload(plan, webhooks)

        self.push_screen(UploadScreen(lines, summary, infos, self.upload_webhooks), done)

    def start_upload(self, plan: UploadPlan, webhooks: list[str]) -> None:
        self.push_screen(
            TransferScreen("Envoi vers Discord", plan.total_bytes, lambda scr: self.run_upload(plan, scr, webhooks))
        )

    @work(exclusive=True, group="transfer")
    async def run_upload(self, plan: UploadPlan, screen: TransferScreen, webhooks: list[str]) -> None:
        total = len(plan.items)
        finished: list[bool] = []  # True : envoyé, False : en échec

        def done(item, result) -> None:
            finished.append(not isinstance(result, Exception))
            screen.set_current(f"[{len(finished)}/{total}] {item.path.name}")
            if isinstance(result, Exception):
                screen.log_line(f"✗ {item.path.name} : {result}", "red")
            else:
                screen.log_line(f"✓ {item.path.name}  ({human(result.size)}, {result.chunks} morceau(x))", "green")

        screen.set_current(f"{total} fichier(s) sur {len(webhooks)} webhook(s) en parallèle")
        try:
            await self.vault.upload_many(plan.items, on_progress=screen.advance, on_result=done, webhooks=webhooks)
        except asyncio.CancelledError:
            ok = sum(finished)
            screen.log_line("Annulé — les morceaux des fichiers en cours ont été supprimés.", "yellow")
            screen.finish(f"Annulé · {ok}/{total} envoyé(s)", ok=False)
            self.reload_remote()
            raise
        ok = sum(finished)
        await self.publish()
        screen.finish(f"Terminé · {ok}/{total} envoyé(s)", ok=ok == total)
        self.query_one(VimTree).clear_marks()
        self.reload_remote()

    # -- distant
    @on(RemoteTable.Action)
    def remote_action(self, event: RemoteTable.Action) -> None:
        if event.kind == "reload":
            self.reload_remote()
            return
        recs = [r for r in (self.vault.file(fid) for fid in event.file_ids) if r is not None]
        if not recs:
            return
        if event.kind == "download":
            default = self.download_dir or self.vault.settings.download_dir

            def chosen(choice: DownloadChoice | None) -> None:
                if choice:
                    self.download_dir = choice.dest
                    self.start_download(recs, choice)

            self.push_screen(DownloadScreen(recs, default, self.start), chosen)
        elif event.kind == "open":
            self.open_channel(recs[0])
        elif event.kind == "delete":
            lines = [f"{r.path}   {human(r.size)}" for r in recs[:14]]
            if len(recs) > 14:
                lines.append(f"… et {len(recs) - 14} autre(s)")
            messages = sum(r.chunks for r in recs)
            summary = (
                f"Les {messages} message(s) seront supprimés. Irréversible."
                if len(recs) == 1
                else f"{len(recs)} fichier(s) · {human(sum(r.size for r in recs))} · "
                f"{messages} message(s) supprimés. Irréversible."
            )
            title = "Supprimer de Discord ?" if len(recs) == 1 else f"Supprimer {len(recs)} fichiers de Discord ?"
            self.push_screen(
                ConfirmScreen(title, lines, summary, danger=True),
                lambda ok: ok and self.start_delete(recs),
            )

    def open_channel(self, rec: StoredFile) -> None:
        """Ouvre dans Discord le salon qui contient les morceaux du fichier."""
        infos = {i.id: i for i in self.vault.webhook_infos()}
        links = [infos[w].link for w in self.vault.file_webhooks().get(rec.id, []) if w in infos and infos[w].link]
        if not links:
            self.notify("Salon inconnu : relance l'onglet une fois Discord joignable.", severity="warning")
            return
        self.open_url(links[0])
        self.notify(f"Ouverture du salon : {links[0]}", timeout=4)

    # -- téléchargement (un ou plusieurs fichiers)
    def start_download(self, recs: list[StoredFile], choice: DownloadChoice) -> None:
        total = sum(r.size for r in recs)
        self.push_screen(
            TransferScreen("Téléchargement depuis Discord", total, lambda scr: self.run_download(recs, choice, scr))
        )

    @work(exclusive=True, group="transfer")
    async def run_download(self, recs: list[StoredFile], choice: DownloadChoice, screen: TransferScreen) -> None:
        ok, n = 0, len(recs)
        try:
            for i, rec in enumerate(recs, 1):
                screen.set_current(f"[{i}/{n}] {rec.name}" if n > 1 else rec.name)
                before = screen.done_bytes
                try:
                    target = await self.vault.download(
                        rec, choice.dest, on_progress=screen.advance, keep_folder=choice.keep_folder
                    )
                    ok += 1
                    screen.log_line(f"✓ {target}", "green")
                except (DSCSError, OSError) as e:
                    screen.log_line(f"✗ {rec.path} : {e}", "red")
                    screen.advance(max(0, rec.size - (screen.done_bytes - before)))
        except asyncio.CancelledError:
            screen.finish(f"Annulé · {ok}/{n} téléchargé(s)", ok=False)
            raise
        if n == 1:
            screen.finish("Terminé · intégrité vérifiée (SHA-256)" if ok else "Échec", ok=ok == 1)
        else:
            screen.finish(f"Terminé · {ok}/{n} téléchargé(s) · intégrité vérifiée (SHA-256)", ok=ok == n)
        self.query_one(RemoteTable).clear_marks()

    # -- suppression (un ou plusieurs fichiers)
    def start_delete(self, recs: list[StoredFile]) -> None:
        if len(recs) == 1:
            self.run_delete(recs[0])
            return
        messages = sum(r.chunks for r in recs)
        self.push_screen(
            TransferScreen("Suppression sur Discord", messages, lambda scr: self.run_delete_many(recs, scr))
        )

    @work(exclusive=True, group="transfer")
    async def run_delete(self, rec: StoredFile) -> None:
        try:
            await self.vault.delete(rec)
            self.notify(f"{rec.name} supprimé")
        except DSCSError as e:
            self.notify(str(e), severity="error")
        await self.publish()
        self.query_one(RemoteTable).clear_marks()
        self.reload_remote()

    @work(exclusive=True, group="transfer")
    async def run_delete_many(self, recs: list[StoredFile], screen: TransferScreen) -> None:
        """La barre avance d'un cran par message supprimé (fichier par fichier)."""
        ok, n = 0, len(recs)
        try:
            for i, rec in enumerate(recs, 1):
                screen.set_current(f"[{i}/{n}] {rec.path}")
                try:
                    await self.vault.delete(rec)
                    ok += 1
                    screen.log_line(f"✓ {rec.path} supprimé", "green")
                except DSCSError as e:
                    screen.log_line(f"✗ {rec.path} : {e}", "red")
                screen.advance(rec.chunks)
        except asyncio.CancelledError:
            screen.log_line("Annulé — les fichiers restants n'ont pas été supprimés.", "yellow")
            screen.finish(f"Annulé · {ok}/{n} supprimé(s)", ok=False)
            await self.publish()
            self.reload_remote()
            raise
        await self.publish()
        screen.finish(f"Terminé · {ok}/{n} supprimé(s)", ok=ok == n)
        self.query_one(RemoteTable).clear_marks()
        self.reload_remote()
