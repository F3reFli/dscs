"""Implémentation des sous-commandes. Chaque `cmd_*` reçoit les arguments parsés et renvoie le code de sortie."""
from __future__ import annotations

import argparse
import asyncio
import functools
import sys
import time
from pathlib import Path

from ..config import Paths, load_settings
from ..discord import is_official_url
from ..errors import DSCSError
from ..models import WebhookRef
from ..services import (
    NO_KEYRING_HELP,
    Invitation,
    KeyStore,
    RecoveryKit,
    Vault,
    default_device_name,
    initialize,
    join_vault,
    parse_public_key,
    probe_webhook,
    public_key_to_text,
    recover_vault,
    relink_vault,
    verification_code,
)
from ..utils import human, write_private
from . import prompts
from .progress import TextProgress


def _date(ts: float) -> str:
    return time.strftime("%d/%m/%Y %H:%M", time.localtime(ts))


def open_vault(passphrase=prompts.passphrase) -> Vault:
    """Ouvre le coffre en demandant la phrase de passe seulement si l'appareil est protégé."""
    vault = Vault.open(passphrase=passphrase)
    vault.notify = lambda message: print(f"⚠ {message}", file=sys.stderr)
    for message in vault.notices:
        vault.notify(message)
    if vault.keystore and vault.keystore.migrated_legacy_key:
        print(
            "ℹ Ta clé a été transférée dans le trousseau de cet appareil "
            f"({vault.paths.keyring_file}) et l'ancien fichier en clair effacé.\n"
            "  Protège cet appareil par une phrase de passe : dscs device passphrase",
            file=sys.stderr,
        )
    return vault


# ---------------------------------------------------------------------- init

def cmd_init(args: argparse.Namespace) -> int:
    paths = Paths.from_env()
    if paths.config_file.exists() and not args.force:
        print(f"Config déjà présente : {paths.config_file}\n(édite-la, ou relance avec --force)")
        return 1
    print("Discord : Paramètres du salon → Intégrations → Webhooks → Nouveau webhook → Copier l'URL\n")
    urls: list[str] = []
    while True:
        prompt = "URL du webhook" + (" (Entrée pour terminer)" if urls else "") + " : "
        url = input(prompt).strip()
        if not url:
            if urls:
                break
            continue
        try:
            WebhookRef.parse(url)
        except DSCSError as e:
            print(f"  ✗ {e}")
            continue
        if not is_official_url(url):
            print("  ⚠ Ça ne ressemble pas à une URL de webhook Discord.")
            if not prompts.confirm("  Garder quand même ?"):
                continue
        name = asyncio.run(probe_webhook(url))
        print(f"  ✓ webhook « {name} »" if name else "  ⚠ webhook injoignable pour l'instant (gardé quand même)")
        urls.append(url)

    new_passphrase = None
    if not KeyStore(paths).has_identity():
        print("\nLa clé privée de cet appareil protège toutes les clés du coffre.")
        if prompts.confirm("La protéger par une phrase de passe ? (recommandé)", default=True):
            new_passphrase = prompts.new_passphrase()

    res = initialize(paths, urls, new_passphrase=new_passphrase, passphrase=prompts.passphrase)
    print(f"\nConfig    : {res.config_file}")
    print(f"Appareil  : {res.identity.name}  (code {res.identity.code})")
    if res.migrated_legacy_key:
        print(f"Trousseau : {res.keyring_file}  (ton ancienne clé y a été transférée, l'ancien fichier effacé)")
    elif res.keyring_created:
        print(f"Trousseau : {res.keyring_file}  (nouveau coffre, clé générée sur cet appareil)")
    else:
        print(f"Trousseau existant conservé : {res.keyring_file}")
    print(f"Index     : {res.index_file}")
    print("\n⚠ Sans cet appareil (ou un autre appareil membre du coffre), tout ce qui est stocké est illisible.")
    print("  Crée dès ton premier envoi un kit de secours, à garder hors ligne : dscs key recovery")
    if not new_passphrase and not KeyStore(paths).is_protected():
        print("  Cet appareil n'a pas de phrase de passe : ajoute-en une avec « dscs device passphrase ».")
    return 0


# ---------------------------------------------------------------------- put / ls / get / rm / gc

def cmd_put(args: argparse.Namespace) -> int:
    with open_vault() as vault:
        return asyncio.run(_put(vault, args.paths, args.webhooks))


async def _put(vault: Vault, paths: list[Path], webhooks: list[str] | None = None) -> int:
    plan = vault.plan_upload(paths)
    if not plan.items:
        print("Rien à envoyer.")
        return 1
    async with vault.connected():
        selected = vault.resolve_webhooks(webhooks) if webhooks else None
        count = len(plan.items)
        bar = TextProgress(plan.items[0].path.name if count == 1 else f"{count} fichiers", plan.total_bytes)

        def done(item, result) -> None:
            if isinstance(result, Exception):
                bar.log(f"✗ {item.path.name} : {result}")
            else:
                bar.log(f"✓ [{result.id}] {result.path}  ({human(result.size)})")

        results = await vault.upload_many(plan.items, on_progress=bar, on_result=done, webhooks=selected)
        speed = bar.speed  # avant la publication du coffre (en sortant du bloc)
    sent = sum(1 for r in results if not isinstance(r, Exception))
    bar.finish(f"{sent}/{count} envoyé(s), {human(plan.total_bytes)} à {human(speed)}/s")
    return 0 if sent == count else 1


def cmd_ls(args: argparse.Namespace) -> int:
    with open_vault() as vault:
        recs = vault.files()
        interrupted = vault.interrupted_count()
        unreadable = [r for r in recs if not vault.can_read(r)] if vault.has_key else []
    if not recs:
        print("Aucun fichier stocké.")
    else:
        print(f"{'ID':>5}  {'Taille':>10}  {'Envoyé le':16}  Chemin")
        for r in recs:
            mark = "  ✗ clé absente" if r in unreadable else ""
            print(f"{r.id:>5}  {human(r.size):>10}  {_date(r.created):16}  {r.path}{mark}")
        print(f"\n{len(recs)} fichier(s), {human(sum(r.size for r in recs))}")
    if recs and not vault.has_key:
        print(f"⚠ Aucune clé de coffre sur cet appareil : impossible de télécharger. {NO_KEYRING_HELP}")
    elif unreadable:
        print(f"⚠ {len(unreadable)} fichier(s) chiffré(s) avec une clé absente du trousseau de cet appareil (✗).")
    if interrupted:
        print(f"⚠ {interrupted} envoi(s) interrompu(s) laissent des morceaux sur Discord : lance « dscs gc ».")
    return 0


def cmd_get(args: argparse.Namespace) -> int:
    with open_vault() as vault:
        rec = vault.file(args.id)
        if rec is None:
            print(f"Aucun fichier avec l'ID {args.id} (voir : dscs ls)")
            return 1
        bar = TextProgress(rec.name, rec.size)

        async def run() -> Path:
            async with vault.connected():
                return await vault.download(rec, args.out, on_progress=bar, keep_folder=not args.flat)

        target = asyncio.run(run())
    bar.finish(f"✓ {target}  (intégrité vérifiée)")
    return 0


def cmd_rm(args: argparse.Namespace) -> int:
    with open_vault() as vault:
        rec = vault.file(args.id)
        if rec is None:
            print(f"Aucun fichier avec l'ID {args.id}")
            return 1
        if not args.yes and not prompts.confirm(f"Supprimer {rec.path} ({human(rec.size)}) de Discord ?"):
            return 1

        async def run() -> None:
            async with vault.connected():
                await vault.delete(rec)

        asyncio.run(run())
    print("  ✓ supprimé")
    return 0


def cmd_gc(args: argparse.Namespace) -> int:
    with open_vault() as vault:
        if not vault.interrupted_count():
            print("Rien à nettoyer.")
            return 0

        async def run():
            async with vault.connected():
                return await vault.collect_garbage()

        report = asyncio.run(run())
    print(f"  ✓ {report.cleaned} envoi(s) interrompu(s) nettoyé(s)")
    if report.remaining:
        print(f"  ⚠ {report.remaining} restant(s) : Discord injoignable ou webhook absent de la config.")
        return 1
    return 0


def cmd_webhooks(args: argparse.Namespace) -> int:
    with open_vault() as vault:

        async def run():
            async with vault.connected():
                return await vault.refresh_webhook_info()

        infos = asyncio.run(run())
    if not infos:
        print("Aucun webhook dans le coffre.")
        return 1
    for i in infos:
        print(f"{i.display_name}  ({i.id})")
        print(f"  Serveur : {i.server_detail}")
        print(f"  Salon   : {i.channel_detail}")
        print(f"  Morceaux stockés : {i.chunks}")
        if i.link:
            print(f"  Ouvrir  : {i.link}")
        print()
    if any(not (i.server_label and i.channel_label) for i in infos):
        print("Discord ne donne pas aux webhooks le nom de leur serveur ni de leur salon : tu peux les nommer")
        print("dans config.toml, sections [servers] et [channels] (\"identifiant\" = \"nom\").")
    return 0


def cmd_webhooks_rm(args: argparse.Namespace) -> int:
    with open_vault() as vault:
        [wid] = vault.resolve_webhooks([args.webhook])
        info = next(i for i in vault.webhook_infos() if i.id == wid)
        files = vault.files_on_webhook(wid)
        print(f"Retirer {info.display_name} ({wid}, {info.channel}) du coffre, sur tous les appareils.")
        if files:
            print(f"Ses morceaux seront perdus : {len(files)} fichier(s) supprimé(s) du coffre "
                  f"({human(sum(r.size for r in files))}) :")
            for rec in files[:20]:
                print(f"  {rec.path}")
            if len(files) > 20:
                print(f"  … et {len(files) - 20} autre(s)")
        if not args.yes and not prompts.confirm("Continuer ?"):
            return 1

        async def run():
            async with vault.connected():
                return await vault.remove_webhook(wid, repair=args.repair)

        res = asyncio.run(run())
    print(f"  ✓ {info.display_name} retiré du coffre ({len(res.files)} fichier(s) supprimé(s))")
    if res.relocated:
        print("  ✓ point d'entrée du coffre recréé sur un autre webhook")
        if any(m.role != "recovery" and m.id != vault.device_id for m in vault.members()):
            print("  ℹ Autres appareils : « dscs device invite » ici, puis « dscs device join <invitation> » "
                  "sur chacun.")
    if res.leftovers:
        print(f"  ⚠ {res.leftovers} message(s) restent dans son salon Discord (webhook déjà supprimé ?) : "
              "supprime le salon, ou ces messages, à la main.")
    if not res.config_updated and wid in {w.id for w in load_settings(vault.paths.config_file).webhooks}:
        print("  ⚠ son URL est encore dans config.toml : retire-la (elle est ignorée en attendant).")
    return 0


# ---------------------------------------------------------------------- bench

MIB = 1024 * 1024


def cmd_bench(args: argparse.Namespace) -> int:
    if not 0 < args.size <= 4096:
        raise DSCSError("Quantité du test : entre 1 et 4096 Mo (-s).")
    if not 1 <= args.parallel <= 8:
        raise DSCSError("Envois simultanés par webhook : entre 1 et 8 (-p).")
    total = int(args.size * MIB)
    with open_vault() as vault:
        ids = vault.resolve_webhooks(args.webhooks) if args.webhooks else [i.id for i in vault.webhook_infos()]
        if not ids:
            raise DSCSError("Aucun webhook dans le coffre : ajoute leurs URL dans config.toml (clé webhooks).")
        names = {i.id: i.display_name for i in vault.webhook_infos()}
        chunk = vault.settings.chunk_size
        chunks = -(-total // chunk)
        print(f"Test de débit : {human(total)} de données aléatoires, {chunks} morceau(x) de {human(chunk)} max,")
        print(f"sur {len(ids)} webhook(s) ({', '.join(names[i] for i in ids)}), {args.parallel} envoi(s) à la fois par "
              "webhook.")
        print("Rien n'entre dans le coffre : les messages de test sont supprimés à la fin, même si tu interromps.")
        if chunks < len(ids) * args.parallel:
            print(f"⚠ {chunks} morceau(x) pour {len(ids) * args.parallel} envois simultanés : des webhooks resteront "
                  "inactifs (augmente -s).")
        if not args.yes:
            if not sys.stdin.isatty():
                raise DSCSError("Confirmation impossible hors d'un terminal : ajoute -y.")
            if not prompts.confirm("Lancer le test (envoi réel vers Discord) ?"):
                return 1
        progress = TextProgress("envoi", total)

        async def run():
            await vault.start()  # sans synchronisation : le coffre n'est pas concerné
            try:
                await vault.refresh_webhook_info()  # noms et salons à jour pour le tableau
                return await vault.benchmark(total, ids, args.parallel, args.download, progress)
            finally:
                await vault.stop()

        report = asyncio.run(run())
    progress.finish(f"✓ {human(report.sent)} envoyés en {report.up_seconds:.1f} s")
    _print_bench(report, args)
    return 0 if not report.leftovers else 1


def _print_bench(report, args: argparse.Namespace) -> None:
    head = f"{'Webhook':16} {'Salon':20} {'Morceaux':>8} {'Envoyé':>10} {'Temps':>8} {'Débit':>12}"
    print(f"\n  {head}  Freiné par Discord")
    for w in report.webhooks:
        brake = f"{w.throttled}× 429, {w.waited:.1f} s d'attente" if w.throttled or w.waited >= 0.05 else "non"
        print(f"  {w.info.display_name[:16]:16} {w.info.channel[:20]:20} {w.chunks:>8} {human(w.sent):>10} "
              f"{w.up_seconds:>6.1f} s {human(w.up_rate) + '/s':>12}  {brake}")
    print(f"  {'Total':16} {'':20} {sum(w.chunks for w in report.webhooks):>8} {human(report.sent):>10} "
          f"{report.up_seconds:>6.1f} s {human(report.up_rate) + '/s':>12}")
    if args.download:
        print(f"\n  Téléchargement : {human(report.received)} relus en {report.down_seconds:.1f} s → "
              f"{human(report.down_rate)}/s")

    print()
    if report.leftovers:
        print(f"⚠ {report.leftovers} message(s) de test n'ont pas pu être supprimés (données aléatoires, sans "
              f"lien avec le coffre) : supprime-les dans Discord si tu veux. Erreur : {report.errors[0]}")
    else:
        print(f"✓ {report.deleted} message(s) de test supprimé(s).")
    for group in report.shared_channels():
        print(f"⚠ {', '.join(w.info.display_name for w in group)} sont dans le même salon : ils partagent la limite de "
              "messages du salon. Un webhook par salon pour additionner les débits.")
    waited = sum(w.waited for w in report.webhooks)
    busy = sum(w.up_seconds for w in report.webhooks) or 1
    if waited / busy > 0.2:
        print("ℹ Discord a souvent freiné les envois (limites par webhook et par salon) : ajouter des salons aidera "
              "plus que des envois simultanés (-p).")
    if len(report.webhooks) > 1:
        print("ℹ Pour voir ce qu'apporte chaque salon, compare avec un seul : dscs bench -w <webhook>.")
    else:
        print("ℹ Pour voir ce qu'apporte un salon de plus, compare avec tous les webhooks : dscs bench.")


# ---------------------------------------------------------------------- device

def cmd_device_show(args: argparse.Namespace) -> int:
    store = KeyStore(Paths.from_env())
    if not store.has_identity():
        print(f"Cet appareil n'a pas encore d'identité. {NO_KEYRING_HELP}")
        print("Pour rejoindre un coffre existant, commence par : dscs device init")
        return 1
    name, public = store.public_info()
    print(f"Appareil       : {name}")
    print(f"Code           : {verification_code(public)}")
    print(f"Clé publique   : {public_key_to_text(public)}")
    if store.is_protected():
        print("Protection     : phrase de passe")
    else:
        print("Protection     : aucune — ajoute une phrase de passe avec « dscs device passphrase »")
    print(f"Trousseau      : {'présent' if store.has_keyring() else 'aucun'} ({store.paths.keyring_file})")
    return 0


def cmd_device_init(args: argparse.Namespace) -> int:
    """Nouvel appareil qui va rejoindre un coffre existant : crée son identité et affiche sa clé publique."""
    store = KeyStore(Paths.from_env())
    if store.has_identity():
        print("Cet appareil a déjà une identité.\n")
        return cmd_device_show(args)
    name = args.name or prompts.ask("Nom de cet appareil", default_device_name())
    identity = store.create_identity(name, prompts.device_passphrase())
    print(f"\n✓ Identité créée pour « {identity.name} ».\n")
    print("Clé publique, à transmettre à un appareil déjà membre du coffre (elle n'est pas secrète) :")
    print(f"  {public_key_to_text(identity.public)}\n")
    print(f"Code de cet appareil : {identity.code}\n")
    print("1. Sur l'appareil membre :  dscs device add <clé publique ci-dessus>")
    print("   Il affichera ce même code : il doit être identique avant de confirmer.")
    print("2. Puis ici :               dscs device join <invitation qu'il t'aura donnée>")
    return 0


def cmd_device_add(args: argparse.Namespace) -> int:
    public = parse_public_key(args.public_key)
    code = verification_code(public)
    print(f"Code de l'appareil à ajouter : {code}")
    if args.code:
        if not prompts.same_code(args.code, code):
            raise DSCSError("Le code donné ne correspond pas à cette clé publique : rien n'a été fait.")
    elif not prompts.confirm("Est-ce exactement le code affiché sur le nouvel appareil ?"):
        print("Annulé. N'ajoute jamais un appareil dont le code ne correspond pas : la clé a pu être remplacée.")
        return 1
    name = args.name or prompts.ask("Nom du nouvel appareil dans le coffre", "appareil")
    with open_vault() as vault:

        async def run():
            async with vault.connected():
                return await vault.add_device(public, name)

        invitation = asyncio.run(run())
        me = vault.keystore.identity()
    print(f"\n✓ « {name} » ajouté au coffre.\n")
    print("Invitation à transmettre au nouvel appareil, par un canal privé (elle permet d'écrire avec le webhook) :")
    print(f"  {invitation.to_text()}\n")
    print(f"Code de CET appareil ({me.name}) : {me.code}")
    print("Le nouvel appareil l'affichera pendant « dscs device join » : il doit être identique.")
    return 0


def cmd_device_invite(args: argparse.Namespace) -> int:
    with open_vault() as vault:
        if not vault.has_key:
            print(f"Aucune clé de coffre sur cet appareil. {NO_KEYRING_HELP}")
            return 1
        invitation = vault.invitation()
    print("Invitation du coffre, à transmettre par un canal privé (elle permet d'écrire avec le webhook,")
    print("mais ne contient aucune clé : seul un appareil membre peut lire le coffre) :")
    print(f"  {invitation.to_text()}\n")
    print("Nouvel appareil : il doit d'abord être ajouté (dscs device add), puis : dscs device join <invitation>")
    print("Appareil déjà membre, après « dscs sync --repair » ailleurs : dscs device join <invitation>")
    return 0


def cmd_device_join(args: argparse.Namespace) -> int:
    invitation = Invitation.from_text(args.invitation or prompts.secret("Colle l'invitation : "))
    paths = Paths.from_env()
    ask = functools.cache(prompts.passphrase)  # demandée une seule fois pour toute la commande
    if KeyStore(paths).has_keyring():
        _, moved = asyncio.run(relink_vault(paths, invitation, ask))
        with open_vault(ask) as vault:

            async def sync() -> int:
                async with vault.connected():
                    return len(vault.files())

            files = asyncio.run(sync())
        if moved:
            print(f"✓ Nouvelle adresse du coffre enregistrée : {files} fichier(s).")
        else:
            print(f"✓ Cet appareil suivait déjà cette adresse du coffre : {files} fichier(s).")
        return 0

    def confirm(name: str, code: str) -> bool:
        print(f"Ce coffre t'a été partagé par « {name} ».")
        print(f"Code de cet appareil-là : {code}")
        if args.code:
            return prompts.same_code(args.code, code)
        return prompts.confirm("Est-ce exactement le code affiché sur cet appareil ?")

    ring = asyncio.run(join_vault(paths, invitation, confirm, ask))
    with open_vault(ask) as vault:

        async def run() -> int:
            async with vault.connected():
                return len(vault.files())

        files = asyncio.run(run())
    print(f"\n✓ Coffre rejoint : {len(ring.members)} appareil(s) membre(s), {files} fichier(s).")
    return 0


def cmd_device_list(args: argparse.Namespace) -> int:
    with open_vault() as vault:
        if not vault.has_key:
            print(f"Aucune clé de coffre sur cet appareil. {NO_KEYRING_HELP}")
            return 1

        async def refresh() -> str | None:
            try:
                async with vault.connected():
                    return None
            except DSCSError as e:
                return str(e)

        problem = asyncio.run(refresh())
        members, me, ring = vault.members(), vault.device_id, vault.keyring
    if problem:
        print(f"⚠ Liste locale, pas à jour : {problem}\n")
    print(f"Coffre {ring.vault_id} · {len(members)} appareil(s) membre(s)\n")
    for m in members:
        tag = "  (cet appareil)" if m.id == me else "  (kit de secours)" if m.role == "recovery" else ""
        print(f"{m.name}{tag}")
        print(f"  Code   : {verification_code(m.public)}")
        print(f"  Ajouté : {_date(m.added)}")
    if ring.revoked:
        print(f"\n{len(ring.revoked)} appareil(s) retiré(s) du coffre.")
    return 0


def cmd_device_revoke(args: argparse.Namespace) -> int:
    with open_vault() as vault:
        member = vault.find_member(args.device)
        print(f"Retirer « {member.name} » (code {verification_code(member.public)}) du coffre ?")
        print("  Il ne recevra ni les nouvelles clés ni ce qui sera envoyé ensuite. Ce qu'il a déjà pu lire")
        print("  (fichiers envoyés avant, avec les anciennes clés) reste à sa portée, et il connaît les webhooks :")
        print("  il peut encore supprimer des messages. Ne partage le coffre qu'avec des appareils de confiance.")
        if not args.yes and not prompts.confirm("Confirmer le retrait ?"):
            return 1

        async def run():
            async with vault.connected():
                return await vault.revoke_device(member)

        key = asyncio.run(run())
        generation = vault.keyring.generation_of(key.id)
    print(f"✓ « {member.name} » retiré. Nouvelle clé de coffre : génération {generation}, empreinte {key.id}.")
    return 0


def cmd_device_recover(args: argparse.Namespace) -> int:
    kit = RecoveryKit.from_text(args.kit or prompts.secret("Colle le kit de secours : "))
    paths = Paths.from_env()
    store = KeyStore(paths)
    name = args.name or default_device_name()
    new_passphrase = None if store.has_identity() else prompts.device_passphrase()

    def confirm(members) -> bool:
        print("Membres de ce coffre :")
        for m in members:
            print(f"  • {m.name}  (code {verification_code(m.public)}, ajouté le {_date(m.added)})")
        return args.yes or prompts.confirm("Reconnais-tu ce coffre ?")

    ring = asyncio.run(recover_vault(paths, kit, name, new_passphrase, confirm, prompts.passphrase))
    with open_vault() as vault:

        async def run() -> int:
            async with vault.connected():
                return len(vault.files())

        files = asyncio.run(run())
    print(f"\n✓ Coffre récupéré sur « {name} » : {len(ring.members)} appareil(s) membre(s), {files} fichier(s).")
    print("  Si ce kit a pu être vu par quelqu'un d'autre, crée-en un nouveau (dscs key recovery)")
    print("  et retire l'ancien appareil de secours (dscs device list, puis dscs device revoke).")
    return 0


def cmd_device_passphrase(args: argparse.Namespace) -> int:
    store = KeyStore(Paths.from_env(), prompts.passphrase)
    store.identity()  # déverrouille avec la phrase actuelle avant toute modification
    if args.remove:
        if not store.is_protected():
            print("Cet appareil n'a pas de phrase de passe.")
            return 0
        store.set_passphrase(None)
        print("✓ Phrase de passe retirée : la clé de l'appareil n'est plus protégée")
        print("  que par les droits du fichier (600).")
        return 0
    store.set_passphrase(prompts.new_passphrase())
    print("✓ Phrase de passe enregistrée. Elle sera demandée à chaque utilisation de dscs sur cet appareil.")
    return 0


# ---------------------------------------------------------------------- key

def cmd_key_list(args: argparse.Namespace) -> int:
    with open_vault() as vault:
        ring = vault.keyring
        usage = vault.files_per_key()
    if ring is None:
        print(f"Aucune clé de coffre sur cet appareil. {NO_KEYRING_HELP}")
        return 1
    print(f"Coffre {ring.vault_id} · trousseau révision {ring.revision}\n")
    print(f"  {'Gén.':>4}  {'Empreinte':16}  {'Créée le':16}  Fichiers")
    for n, vk in enumerate(ring.generations(), 1):
        count, size = usage.get(vk.id, (0, 0))
        mark = "*" if vk.id == ring.current else " "
        print(f"{mark} {n:>4}  {vk.id:16}  {_date(vk.created):16}  {count} ({human(size)})")
    print("\n* clé courante : utilisée pour les prochains envois.")
    missing = sum(n for kid, (n, _) in usage.items() if kid is not None and kid not in ring.keys)
    if missing:
        print(f"⚠ {missing} fichier(s) chiffré(s) avec une clé absente de ce trousseau.")
    return 0


def cmd_key_rotate(args: argparse.Namespace) -> int:
    with open_vault() as vault:

        async def run():
            async with vault.connected():
                return await vault.rotate_key()

        key = asyncio.run(run())
        generation = vault.keyring.generation_of(key.id)
    print(f"✓ Nouvelle clé de coffre : génération {generation}, empreinte {key.id}, publiée pour tous les appareils.")
    print("  Les prochains envois l'utiliseront. Les fichiers déjà envoyés restent lisibles :")
    print("  les clés précédentes sont conservées dans le trousseau.")
    return 0


def cmd_key_recovery(args: argparse.Namespace) -> int:
    with open_vault() as vault:

        async def run():
            async with vault.connected():
                return await vault.create_recovery()

        kit = asyncio.run(run())
    text = kit.to_text()
    print("⚠ Ce kit de secours donne accès à TOUT le coffre. Range-le hors ligne : imprimé dans un endroit sûr,")
    print("  ou sur une clé USB chiffrée. Jamais sur Discord, dans un mail ou dans un nuage.\n")
    if args.output:
        write_private(args.output, (text + "\n").encode())
        print(f"✓ Kit écrit dans {args.output} (lisible par toi seul).")
    else:
        print(text)
    print("\nSi tu perds tous tes appareils : dscs device recover <kit>.")
    print("S'il est perdu ou exposé : dscs device list, puis dscs device revoke <appareil de secours>.")
    return 0


# ---------------------------------------------------------------------- sync

def cmd_sync(args: argparse.Namespace) -> int:
    with open_vault() as vault:
        if not vault.has_key:
            print(f"Aucune clé de coffre sur cet appareil. {NO_KEYRING_HELP}")
            return 1

        vault.notify = None  # les avertissements sont affichés avec le résumé

        async def run():
            await vault.start()
            try:
                return await vault.sync(repair=args.repair)
            finally:
                await vault.stop()

        report = asyncio.run(run())
        others = [m for m in vault.members() if m.id != vault.device_id]
    if report.published:
        print("✓ Coffre publié sur Discord.")
    if report.keyring_updated:
        print("✓ Trousseau mis à jour (nouvelles clés ou nouveaux appareils).")
    print(f"✓ {report.files_added} fichier(s) reçu(s) d'autres appareils.")
    if report.pushed:
        print("✓ Coffre publié (index et trousseau à jour).")
    else:
        print("✓ Rien à publier : le coffre est à jour.")
    for warning in report.warnings:
        print(f"⚠ {warning}")
    if report.relocated and any(m.role != "recovery" for m in others):
        print("\nLes autres appareils ne connaissent pas encore la nouvelle adresse. Sur chacun d'eux :")
        print("  dscs device join <invitation>    (invitation : « dscs device invite » ici)")
    if args.repair and not report.relocated:
        print("ℹ Le point d'entrée du coffre était joignable : rien à réparer.")
    return 0


# ---------------------------------------------------------------------- tui

def cmd_tui(args: argparse.Namespace) -> int:
    start = (args.folder or Path.cwd()).expanduser()
    if not start.is_dir():
        print(f"Pas un dossier : {start}  (voir : dscs --help)")
        return 1
    from ..tui import DSCSApp  # import tardif : Textual est lourd à charger

    with open_vault() as vault:
        DSCSApp(start.resolve(), vault).run()
    return 0
