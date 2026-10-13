<?php
/**
 * @var Dscs\Docs\Page    $page
 * @var Dscs\Docs\Content $c
 */
$page->title = 'API Python (Vault)';
$page->description = 'Utiliser dscs comme bibliothèque depuis tes propres scripts Python.';
?>
<?= $c->callout('warning', '<p>dscs est en version 0.x : cette API peut encore changer d\'une version à l\'autre.</p>') ?>

<p>La CLI et l'interface n'utilisent que l'objet <code>Vault</code> (<code>dscs.services</code>). Tes scripts peuvent faire de même.</p>

<h2>Exemple complet</h2>

<?= $c->code('python', <<<'PY'
import asyncio
from pathlib import Path

from dscs.errors import DSCSError
from dscs.services import Vault


async def main() -> None:
    with Vault.open() as vault:                      # config + clé + index (hors ligne)
        plan = vault.plan_upload([Path("[folder]").expanduser()])
        print(f"{len(plan.items)} fichiers, {plan.total_chunks} morceaux")

        async with vault.connected():                # session HTTP
            for item in plan.items:
                try:
                    rec = await vault.upload(item)
                    print("envoyé", rec.id, rec.path)
                except DSCSError as e:
                    print("échec", item.path, e)

            for rec in vault.files():
                if rec.name.endswith(".jpg"):
                    path = await vault.download(rec, Path("/tmp/restauration"))
                    print("récupéré", path)


asyncio.run(main())
PY) ?>

<h2><code>Vault</code></h2>

<h3>Ouverture et fermeture</h3>
<?= $c->table(['Membre', 'Description'], [
    ['<code>Vault.open(paths=None, passphrase=None)</code>', 'Lit la config, ouvre le trousseau, prend le verrou partagé, ouvre (et migre) l\'index. <code>passphrase</code> : fonction sans argument qui renvoie la phrase de passe, appelée seulement si l\'appareil est protégé. Sans trousseau, le Vault s\'ouvre quand même (lister, supprimer). Lève <code>ConfigError</code>, <code>CryptoError</code>, <code>IndexStateError</code>.'],
    ['<code>vault.close()</code>', 'Ferme l\'index et libère le verrou. Appelé automatiquement par <code>with</code>.'],
    ['<code>vault.paths</code>, <code>vault.settings</code>', 'Emplacements et réglages en cours (<code>settings.download_dir</code> est modifiable).'],
]) ?>

<h3>Réseau</h3>
<?= $c->table(['Membre', 'Description'], [
    ['<code>async with vault.connected()</code>', 'Ouvre la session HTTP pour la durée du bloc. Requis par toutes les opérations réseau.'],
    ['<code>await vault.start()</code> / <code>await vault.stop()</code>', 'Équivalent manuel (utilisé par l\'interface).'],
    ['<code>await vault.webhook_names()</code>', 'Noms des webhooks.'],
    ['<code>await vault.refresh_webhook_info()</code>', 'Redemande à Discord nom, serveur et salon de chaque webhook ; renvoie des <code>WebhookInfo</code>.'],
    ['<code>vault.webhook_infos()</code>', 'Mêmes informations, depuis le cache local (hors ligne).'],
    ['<code>vault.resolve_webhooks(noms_ou_ids)</code>', 'Identifiants de webhooks désignés par nom ou identifiant.'],
]) ?>

<h3>Lecture (locale, sans réseau)</h3>
<?= $c->table(['Membre', 'Renvoie'], [
    ['<code>vault.files()</code>', '<code>list[StoredFile]</code> des fichiers complets, triés par dossier puis nom'],
    ['<code>vault.file(file_id)</code>', '<code>StoredFile</code> ou <code>None</code> (inconnu ou incomplet)'],
    ['<code>vault.interrupted_count()</code>', 'Nombre de fichiers incomplets (à nettoyer)'],
    ['<code>vault.plan_upload(paths)</code>', '<code>UploadPlan</code> pour une liste de fichiers et dossiers'],
]) ?>

<h3>Opérations (réseau)</h3>
<?= $c->table(['Membre', 'Description'], [
    ['<code>await vault.upload(item, on_progress=None, webhooks=None)</code>', 'Envoie un <code>UploadItem</code>, renvoie le <code>StoredFile</code>. <code>webhooks</code> : identifiants à utiliser (défaut : tous). En cas d\'erreur ou d\'annulation, les morceaux envoyés sont retirés.'],
    ['<code>await vault.download(rec, dest_dir=None, on_progress=None, keep_folder=True)</code>', 'Reconstitue le fichier sous <code>dest_dir/&lt;dossier&gt;/</code> (ou directement dans <code>dest_dir</code> si <code>keep_folder=False</code> ; défaut : <code>download_dir</code>), renvoie son chemin réel. Jamais d\'écrasement.'],
    ['<code>await vault.delete(rec)</code>', 'Supprime le fichier de Discord et de l\'index.'],
    ['<code>await vault.collect_garbage()</code>', 'Nettoie les fichiers incomplets ; renvoie <code>GcReport(cleaned, remaining)</code>. Exige d\'être la seule instance.'],
]) ?>

<p><code>on_progress</code> est appelé avec un nombre d'octets en clair à chaque morceau traité.</p>

<h3>Synchronisation (réseau)</h3>
<?= $c->table(['Membre', 'Description'], [
    ['<code>await vault.pull()</code>', 'Reçoit trousseau et index publiés par les autres appareils (fait par <code>connected()</code>).'],
    ['<code>await vault.flush()</code>', 'Publie les modifications locales s\'il y en a (fait en sortie de <code>connected()</code>).'],
    ['<code>await vault.sync(repair=False)</code>', 'Échange complet ; renvoie un <code>SyncReport</code> (fichiers reçus, trousseau mis à jour, superbloc recréé, avertissements). <code>repair=True</code> : recrée le superbloc introuvable même si d\'autres appareils utilisent le coffre (automatique si cet appareil est le seul).'],
    ['<code>vault.notify</code>', 'Fonction appelée avec chaque avertissement (synchronisation, webhook qui a changé de salon) ; <code>None</code> par défaut.'],
]) ?>

<h3>Appareils</h3>
<?= $c->table(['Membre', 'Description'], [
    ['<code>vault.members()</code>, <code>vault.device_id</code>', 'Membres du coffre (<code>Member</code> : nom, clé publique, date) et identifiant de cet appareil.'],
    ['<code>vault.find_member(texte)</code>', 'Un membre désigné par nom, code ou identifiant.'],
    ['<code>vault.invitation()</code>', '<code>Invitation</code> : adresse du coffre, à donner à un nouvel appareil.'],
    ['<code>await vault.add_device(cle_publique, nom)</code>', 'Ajoute un appareil (re-scelle le trousseau pour tous), renvoie l\'invitation.'],
    ['<code>await vault.revoke_device(membre)</code>', 'Retire un appareil et passe à une nouvelle génération de clé.'],
    ['<code>await vault.rotate_key()</code>', 'Nouvelle génération de clé, publiée.'],
    ['<code>await vault.create_recovery()</code>', 'Ajoute un appareil de secours, renvoie son <code>RecoveryKit</code>.'],
    ['<code>await join_vault(paths, invitation, confirmer)</code>', 'Rejoint un coffre (appareil sans trousseau) ; <code>confirmer(nom, code)</code> doit valider le code de l\'appareil qui a fait l\'ajout.'],
    ['<code>await recover_vault(paths, kit, nom, phrase, confirmer)</code>', 'Rejoint un coffre avec un kit de secours ; <code>confirmer(membres)</code> fait reconnaître la liste des membres.'],
    ['<code>await relink_vault(paths, invitation)</code>', 'Appareil déjà membre : suit la nouvelle adresse du coffre (après une réparation) ; renvoie <code>(trousseau, adresse changée)</code>.'],
]) ?>

<h2>Modèles (<code>dscs.models</code>)</h2>

<h3><code>StoredFile</code></h3>
<?= $c->table(['Champ', 'Type', 'Description'], [
    ['<code>id</code>', 'int', 'ID affiché par <code>dscs ls</code>'],
    ['<code>uid</code>', 'str', 'Identifiant aléatoire utilisé dans l\'AAD'],
    ['<code>name</code>, <code>folder</code>', 'str', 'Nom et dossier distant (<code>""</code> = racine)'],
    ['<code>path</code>', 'str (propriété)', '<code>folder/name</code>, ou <code>name</code>'],
    ['<code>size</code>', 'int', 'Taille en octets'],
    ['<code>sha256</code>', 'str', 'Empreinte du fichier en clair'],
    ['<code>chunks</code>', 'int', 'Nombre de morceaux'],
    ['<code>created</code>', 'float', 'Horodatage Unix de l\'envoi'],
    ['<code>format</code>', 'int', 'Format des morceaux'],
]) ?>

<h3><code>UploadPlan</code> et <code>UploadItem</code></h3>
<?= $c->table(['Membre', 'Description'], [
    ['<code>plan.items</code>', '<code>list[UploadItem]</code> ; chaque item a <code>path</code> (local), <code>folder</code> (distant), <code>size</code>'],
    ['<code>plan.total_bytes</code>', 'Somme des tailles'],
    ['<code>plan.total_chunks</code>', 'Nombre total de morceaux'],
]) ?>

<h3>Autres</h3>
<ul>
  <li><code>Chunk(idx, webhook_id, message_id, size)</code> : un morceau dans l'index.</li>
  <li><code>WebhookRef(id, url)</code> : <code>WebhookRef.parse(url)</code> ; <code>.redacted</code> donne l'URL sans token.</li>
  <li><code>chunk_count(size, chunk_size)</code> : nombre de morceaux (au moins 1).</li>
</ul>

<h2>Configuration et initialisation</h2>

<p><code>initialize()</code> crée un <strong>nouveau coffre</strong> sur cet appareil (identité + trousseau) : pour un coffre
existant, utilise <code>join_vault</code> ou <code>recover_vault</code>.</p>

<?= $c->code('python', <<<'PY'
from pathlib import Path
from dscs.config import Paths
from dscs.services import Vault, initialize

paths = Paths(config_dir=Path("/srv/coffre"), data_dir=Path("/srv/coffre"))
result = initialize(paths, ["[webhooks-link]"])
print(result.identity.code, result.keyring_file)

with Vault.open(paths) as vault:
    print(len(vault.files()))
PY) ?>

<?= $c->table(['Fonction', 'Description'], [
    ['<code>Paths(config_dir, data_dir)</code>', 'Emplacements ; propriétés <code>config_file</code>, <code>device_file</code>, <code>keyring_file</code>, <code>index_file</code>, <code>lock_file</code>'],
    ['<code>Paths.from_env(env=None)</code>', '<code>DSCS_HOME</code>, sinon XDG, sinon les défauts'],
    ['<code>initialize(paths, urls, device_name=None, new_passphrase=None)</code>', 'Valide les URLs, écrit la config, crée l\'identité de l\'appareil et le trousseau s\'ils n\'existent pas ; renvoie un <code>InitResult</code> (fichiers, identité, trousseau)'],
    ['<code>await probe_webhook(url)</code>', 'Nom du webhook si Discord le reconnaît, sinon <code>None</code>'],
]) ?>

<h2>Bonnes pratiques</h2>
<ul>
  <li>Utilise toujours <code>with Vault.open()</code> pour libérer l'index et le verrou.</li>
  <li>Ne passe pas par <code>dscs.transfer</code>, <code>dscs.index</code> ou <code>dscs.discord</code> directement : le Vault garantit
    les vérifications (clé, verrou, webhooks).</li>
  <li>Attrape <code>DSCSError</code> pour les erreurs prévues (<?= $c->link('reference/erreurs') ?>).</li>
</ul>
