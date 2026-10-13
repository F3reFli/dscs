# Architecture de DSCS

Schémas fonctionnels (Mermaid) de toute la pipeline, du disque à Discord et retour : [architecture/](architecture/README.md).

DSCS (**D**iscord **S**erver **C**loud **S**torage) découpe des fichiers en morceaux chiffrés et les
stocke comme pièces jointes de messages postés par des webhooks Discord. Un coffre peut être partagé entre
plusieurs appareils (les siens, ou ceux de personnes de confiance) : chacun a sa propre clé, la clé du coffre lui
est remise scellée, et l'index est synchronisé, chiffré, via Discord.

Ce document est la référence technique : la documentation (`docs/`) et le code doivent rester d'accord avec lui.
Toute décision qui change une règle ci-dessous doit le mettre à jour.

## Vue d'ensemble du dépôt

```
Dscs/
├── src/dscs/            package Python (l'application)
├── tests/               pytest : unit/, integration/, tui/ + faux serveur Discord (fakes/)
├── docs/                site de documentation local, PHP + SCSS, bilingue (projet indépendant)
├── ARCHITECTURE.md      ce document
├── pyproject.toml       dépendances, entrée `dscs`, config pytest et ruff
└── Makefile             raccourcis : install, test, lint, docs
```

## Couches du package

```
              cli/         tui/              ← interfaces : affichage et saisie uniquement
                 \         /
                  services/                  ← Vault, KeyStore, appairage : la seule API des interfaces
                   /      \
           transfer/      sync/              ← orchestration : transferts / synchronisation entre appareils
                 /    |     \
          crypto/  index/  discord/          ← briques indépendantes entre elles
                 \    |     /
          config/  models  errors  utils/    ← fondations, sans dépendance interne
```

Règles de dépendance :

| Module | Peut importer | Ne doit jamais importer |
|---|---|---|
| `errors`, `models`, `utils` | rien d'interne (sauf `models` → `errors`, `utils` → `errors`) | — |
| `config` | `errors`, `models` | `crypto`, `index`, `discord`, … |
| `crypto`, `index`, `discord` | `config`, `errors`, `models`, `utils` | entre eux, `transfer`, `sync`, `services` |
| `transfer`, `sync` | les briques ci-dessus | `services`, `cli`, `tui` (et pas l'un l'autre) |
| `services` | tout ce qui est en dessous | `cli`, `tui` |
| `cli`, `tui` | `services`, `models`, `errors`, `utils`, `config.Paths` | `transfer`, `sync`, `index`, `crypto`, `discord` directement¹ |

¹ Exception assumée : `cli/commands.py` utilise `discord.is_official_url` (validation de saisie à l'init).
`services` réexporte ce dont les interfaces ont besoin (`Invitation`, `RecoveryKit`, `verification_code`…).

### Rôle de chaque module

| Module | Contenu |
|---|---|
| `errors.py` | `DSCSError` et ses sous-classes. Toute `DSCSError` est une erreur prévue, affichée telle quelle. |
| `models.py` | `WebhookRef` (ID + URL secrète), `WebhookInfo` (nom, serveur, salon), `StoredFile`, `Chunk`, `UploadItem`, `UploadPlan`. |
| `config/paths.py` | `Paths` : emplacements résolus **à l'exécution** (`DSCS_HOME` ou XDG). Aucun global calculé à l'import. |
| `config/settings.py` | `config.toml` : webhooks ajoutés à la main, réglages, noms lisibles `[servers]`/`[channels]`. |
| `crypto/cipher.py` | AES-256-GCM des morceaux, AAD `"<uid>:<idx>"`, constante `CHUNK_FORMAT`. |
| `crypto/keys.py` | Clés de coffre : génération, empreinte, lecture de l'ancienne clé unique (dscs ≤ 0.2). |
| `crypto/sealing.py` | Scellement authentifié X25519 + HKDF-SHA256 + AES-GCM (esprit HPKE, mode Auth). |
| `crypto/device.py` | Identité d'appareil : paire X25519, nom, code de vérification, protection par phrase de passe (scrypt). |
| `crypto/keyring.py` | `Keyring` : générations de clés, membres, révoqués, webhooks, adresse du superbloc ; fusion. |
| `index/db.py` | Connexion SQLite (WAL, autocommit, `busy_timeout`), `transaction()`. |
| `index/migrations.py` | Schéma versionné par `PRAGMA user_version` (version 4). |
| `index/repository.py` | `Index` : le seul code qui écrit du SQL (hors migrations) ; export/import de l'état partagé. |
| `index/lock.py` | `ProcessLock` : verrou `flock` partagé/exclusif entre processus dscs. |
| `discord/http.py` | Session aiohttp, `request()` avec gestion 429 / 5xx / coupures et backoff. |
| `discord/webhook.py` | `WebhookClient` : `info`, `upload`, `replace_attachment`, `download_attachment`, `delete_message`. |
| `discord/pool.py` | `WebhookPool` : les webhooks du coffre, retrouvables par ID. |
| `transfer/` | `plan_upload`, `upload_file` (sur tous les webhooks ou une sélection), `download_file`, `purge_file`, `delete_file`. |
| `sync/superblock.py` | Superbloc : point d'entrée du coffre sur Discord (trousseaux scellés, pointeur d'index). |
| `sync/snapshot.py` | Instantané d'index : état partagé compressé, chiffré, avec numéro de séquence authentifié. |
| `sync/engine.py` | `SyncEngine` : pull / push, fusion de confiance des trousseaux, concurrence optimiste. |
| `sync/invitation.py` | `Invitation` (adresse du coffre) et `RecoveryKit` (adresse + clé privée de secours). |
| `services/keystore.py` | `KeyStore` : `device.json`, `keyring.sealed`, migration de l'ancienne clé, phrase de passe. |
| `services/vault.py` | `Vault` : assemble tout ; transferts, synchronisation, appareils (ajout, retrait, rotation, secours). |
| `services/bench.py` | `dscs bench` : débit par webhook avec des données aléatoires, supprimées ensuite (hors coffre, sans clé). |
| `services/devices.py` | `join_vault` (invitation) et `recover_vault` (kit de secours) pour un appareil sans trousseau. |
| `services/setup.py` | `initialize()` et `probe_webhook()` pour `dscs init`. |
| `cli/` | `parser.py`, `commands.py` (une `cmd_*` par commande), `prompts.py` (saisies), `progress.py`. |
| `tui/` | `app.py`, `app.tcss`, `widgets/` (arbre local, table distante, panneau des webhooks, bandeau d'accueil `BannerStrip` : animation de `dscs -h` dans l'écran principal, sans focus ni attente — jamais un écran qui bloque), `screens/` (envoi, téléchargement, confirmation, transfert). |

## Le Vault, point d'entrée unique

```python
with Vault.open(passphrase=ask) as vault:  # config + trousseau (+ phrase de passe si protégé) + index + verrou
    vault.files()                          # lecture locale
    async with vault.connected():          # session HTTP, puis pull (trousseau + index des autres appareils)
        rec = await vault.upload(item, webhooks=["123…"])
        await vault.download(rec, dest, keep_folder=False)
        await vault.add_device(public_key, "portable")
    # en sortie de connected() : push des modifications locales
```

Sans trousseau (appareil pas encore dans un coffre), lister, supprimer et nettoyer restent possibles ;
envoyer et télécharger demandent une clé.

## Fichiers sur disque

| Fichier | Contenu | Droits | Perte |
|---|---|---|---|
| `~/.config/dscs/device.json` | Identité de l'appareil : clé privée X25519 (chiffrée si phrase de passe), nom | 0600 | Le trousseau local devient illisible : rejoindre de nouveau (autre appareil ou kit de secours) |
| `~/.config/dscs/keyring.sealed` | Trousseau du coffre, scellé pour cet appareil | 0600 | Récupérable : il est aussi sur Discord, scellé pour chaque membre |
| `~/.config/dscs/config.toml` | Réglages, webhooks ajoutés à la main, noms lisibles | 0600 | Recréable |
| `~/.local/share/dscs/index.db` | Index SQLite local (cache de l'index partagé) | 0600 | Récupérable par synchronisation |
| `~/.local/share/dscs/index.lock` | Verrou entre processus | 0600 | Sans importance |
| `~/.config/dscs/key` | Ancienne clé unique en clair (dscs ≤ 0.2) | 0600 | Transférée dans le trousseau au premier lancement, puis effacée |

`DSCS_HOME=/chemin` place tout dans un seul dossier (utilisé par les tests). `DSCS_PASSPHRASE` fournit la phrase de
passe aux scripts.

## Clés et chiffrement

### Hiérarchie

```
clé privée de l'appareil (X25519, jamais transmise ; protégée par phrase de passe en option, scrypt n=2^16)
   └── ouvre ─► trousseau scellé pour cet appareil
                  └── contient ─► clés de coffre, génération 1, 2, … (AES-256)
                                    └── chiffrent ─► morceaux de fichiers, instantanés d'index
```

### Morceaux (format 1)

| Élément | Valeur |
|---|---|
| Algorithme | AES-256-GCM (`cryptography`) |
| Nonce | 12 octets aléatoires par morceau |
| AAD | `"<uid>:<idx>"` : un morceau ne peut être ni modifié, ni déplacé, ni transplanté dans un autre fichier |
| Blob | `nonce (12) ‖ ciphertext ‖ tag (16)` → 28 octets de surcoût |
| Clé | génération courante du trousseau ; son empreinte est enregistrée avec le fichier (`files.key_id`) |
| Intégrité globale | SHA-256 du fichier en clair, vérifié avant de renommer le `.part` |

### Scellement (trousseaux)

`seal(expéditeur, destinataire)` : clé éphémère X25519 ; clé de chiffrement = HKDF-SHA256 sur
`DH(éphémère, destinataire) ‖ DH(expéditeur, destinataire)`, sel = les trois clés publiques, info = contexte
(`dscs:keyring:v1:<vault_id>`) ; AES-256-GCM avec l'en-tête en AAD. Le destinataire apprend la clé publique de
l'expéditeur et sait que seul son détenteur a pu sceller : la confiance se décide ensuite (membre connu).

### Empreintes et codes

- Empreinte d'une clé de coffre : `sha256("dscs:key-fingerprint:v1:" ‖ clé)[:16]`.
- Identifiant d'appareil : `sha256("dscs:device-id:v1:" ‖ clé publique)[:16]`.
- Code de vérification : 20 chiffres (~66 bits) tirés de `sha256("dscs:device-code:v1:" ‖ clé publique)`, à comparer
  entre deux écrans lors d'un appairage.

### Ce que voit Discord

Le nombre, la taille et la date des messages ; les identifiants des webhooks et des appareils (empreintes de clés
publiques). Ni contenu, ni nom de fichier, ni arborescence, ni clé : l'index et le trousseau n'y sont que chiffrés.

## Journal

Chaque module écrit dans son journal (`logging.getLogger(__name__)`) ; le logger `dscs` porte un `NullHandler` :
rien ne sort tant que la CLI ne l'active pas (`cli/logs.py` : `-v` INFO, `-vv` DEBUG, `--log` fichier en `600` ;
l'interface écrit toujours dans un fichier). Règle : **aucun secret dans un message** — URLs passées par
`utils.redact_secrets` (token de webhook et signature `hm` masqués), jamais de clé ; un filtre du handler applique
le même masquage en dernier recours. Un test d'intégration parcourt envoi, téléchargement, synchro à deux appareils
et suppression en `-vv` et vérifie qu'aucun token ni aucune clé n'apparaît.

## Synchronisation entre appareils

### Superbloc

Un message posté par le premier webhook du coffre, dont la pièce jointe (nom aléatoire) est remplacée à chaque
publication (`PATCH`). Son adresse (webhook + identifiant du message) est dans le trousseau et dans les invitations.
Il contient :

- `keyrings` : destinataire → expéditeur → trousseau scellé ;
- `index` : numéro de séquence, clé utilisée, HMAC de l'état, morceaux de l'instantané d'index.

Un webhook ne retrouve ses messages que dans son salon actuel. Si le superbloc répond 404 (webhook déplacé vers un
autre salon, message supprimé) : `SuperblockLostError`, sauf si cet appareil est le seul membre actif (rôle
`recovery` exclu) ou `dscs sync --repair` : un nouveau superbloc est alors posté, son adresse porte `moved` (date),
et la fusion des trousseaux retient l'adresse la plus récente. Les autres appareils la reçoivent par une invitation
(`device join` sur un appareil déjà membre → `relink_vault`, qui n'accepte que des trousseaux de membres connus).

### Instantané d'index

L'état partageable (fichiers complets avec leurs morceaux, pierres tombales des suppressions) est sérialisé de façon
déterministe, compressé (zlib), chiffré avec la clé courante (AAD = `vault_id` + numéro de séquence), puis envoyé
comme des morceaux ordinaires. L'empreinte (HMAC avec la clé) permet de voir « rien de neuf » sans télécharger.

### pull / push

- **pull** (au début de chaque connexion) : relit le superbloc ; fusionne les trousseaux scellés pour cet appareil
  (voir *confiance*) ; si la séquence distante est plus grande que la locale, télécharge l'instantané et l'importe.
  Un état plus ancien (séquence ≤ locale) est ignoré : pas de retour en arrière.
- **push** (en fin de connexion s'il y a eu des envois/suppressions, et pour `dscs sync`) : pull, puis si l'état ou
  le trousseau a changé, envoie un nouvel instantané (séquence + 1) et/ou re-scelle le trousseau pour chaque membre ;
  relit le superbloc et ne publie que s'il n'a pas bougé (sinon recommence, 4 essais). L'ancien instantané est
  supprimé après publication. Un appareil ne publie jamais par-dessus un état distant qu'il n'a pas pu lire.

### Fusion

- Index : union des fichiers (par `uid`) et des pierres tombales ; une pierre tombale l'emporte. Les fichiers
  incomplets locaux (envoi ou suppression en cours) ne sont pas partagés.
- Trousseau : union des clés, des membres, des révocations (une révocation l'emporte), des webhooks ; la clé
  courante est la plus récente. Résultat indépendant de l'ordre.

### Confiance

Un trousseau reçu n'est fusionné que s'il a été scellé par un membre connu (ou par un membre introduit par un membre
connu, de proche en proche). Un appareil révoqué ne peut donc ni se ré-ajouter ni injecter de clé ; ses blocs sont
ignorés et retirés à la publication suivante.

## Appareils

| Opération | Effet |
|---|---|
| `device init` | Crée l'identité du nouvel appareil, affiche sa clé publique et son code |
| `device add` | Sur un membre : vérification du code, ajout au trousseau, re-scellement pour tous, invitation |
| `device join` | Sur le nouvel appareil : lit le superbloc, vérifie le code de l'appareil qui l'a ajouté, enregistre le trousseau. Sur un membre : suit la nouvelle adresse du coffre |
| `device invite` | Affiche l'invitation (adresse du superbloc, aucune clé) |
| `sync --repair` | Recrée le superbloc introuvable même avec plusieurs appareils |
| `device revoke` | Retire un membre et passe à une nouvelle génération de clé (qu'il ne reçoit pas) |
| `key rotate` | Nouvelle génération de clé pour les prochains envois ; les anciennes restent dans le trousseau |
| `key recovery` | Ajoute un appareil « de secours » et donne son kit (clé privée + adresse), à garder hors ligne |
| `device recover` | Rejoint avec le kit ; le nouvel appareil est présenté aux autres au nom de l'appareil de secours |

Limites assumées : une révocation ne reprend pas ce qui a déjà été lu (anciennes clés, fichiers déjà envoyés), et un
appareil retiré connaît encore les URLs des webhooks (il peut supprimer des messages). Ne partager qu'avec des
appareils de confiance.

## Flux d'un envoi

1. **Plan** (`plan_upload`) : dossiers développés, dossier distant par fichier, liens symboliques internes ignorés.
2. **Confirmation** côté interface (TUI : écran d'envoi avec choix des webhooks ; CLI : `-w`).
3. **Enregistrement** : ligne `files` avec `complete = 0`, `uid` aléatoire, `key_id` de la clé courante.
4. **Producteur** : lit les fichiers l'un après l'autre par morceaux de `chunk_size`, met à jour le SHA-256, chiffre
   (dans un thread), **une seule file** bornée pour tout l'envoi (`upload_files`).
5. **Workers** (un par webhook choisi) : prennent le morceau suivant, quel que soit son fichier (tous les webhooks
   travaillent, même avec des petits fichiers) ; envoi sous un nom aléatoire, flag `SUPPRESS_NOTIFICATIONS`, morceau
   inscrit dans l'index **immédiatement**.
6. **Fin d'un fichier** : taille relue différente → échec ; sinon `complete = 1` et SHA-256 enregistré. Push à la fin.
7. **Échec d'un fichier** : ses morceaux sont purgés, les autres fichiers continuent. **Annulation** : fichiers
   terminés gardés, `purge_file` pour ceux en cours.

## Flux d'un téléchargement

1. Contrôles : clé présente dans le trousseau, format connu, nombre de morceaux, webhooks connus.
2. Destination : choisie (TUI) ou `-o` ; dossier distant recréé ou non (`keep_folder`), `safe_join`, jamais d'écrasement.
3. Morceaux relus avec un lien CDN frais, préchargement de 3, déchiffrement et AAD, `.part`, SHA-256, renommage.

## Suppression et nettoyage (gc)

- `delete_file` vérifie les webhooks, marque le fichier incomplet, ajoute une pierre tombale, puis `purge_file`.
- `purge_file` retire chaque morceau de l'index dès sa suppression (404 = supprimé) ; s'il en reste, le fichier reste
  incomplet et `dscs gc` reprendra.
- `dscs gc` exige le verrou **exclusif** : il refuse de tourner si une autre instance est ouverte.

## Schéma de l'index (version 4)

```sql
meta       (key TEXT PRIMARY KEY, value TEXT NOT NULL)        -- sync_seq, keyring_pushed
webhooks   (id TEXT PRIMARY KEY, channel_id TEXT, guild_id TEXT, name TEXT)   -- cache ; jamais de token
files      (id INTEGER PRIMARY KEY, uid TEXT UNIQUE, name, folder, size, sha256, chunks, format,
            key_id TEXT, created REAL, complete INTEGER)
chunks     (file_id → files ON DELETE CASCADE, idx, webhook_id → webhooks, message_id, size,
            PRIMARY KEY (file_id, idx))
tombstones (uid TEXT PRIMARY KEY, deleted REAL)             -- suppressions à propager
```

| Version | Changement |
|---|---|
| 0 → 1 | Base de dscs 0.1 : URL complète (token) remplacée par l'ID du webhook, puis `VACUUM` |
| 1 → 2 | `files.key_id` (clé qui a chiffré chaque fichier) |
| 2 → 3 | `tombstones` (synchronisation) |
| 3 → 4 | `webhooks.guild_id`, `webhooks.name` (affichage du serveur et du salon) |

Une base plus récente que le code est refusée. Ajouter une version : `_to_vN`, `MIGRATIONS`, `SCHEMA_VERSION`,
test depuis une base N-1 remplie.

## Discord

| Sujet | Comportement |
|---|---|
| Limite de taille | 10 Mio par pièce jointe sans boost → morceaux de 9 Mio (`chunk_size_mb`, max 499) |
| Envoi | `POST {webhook}?wait=true`, multipart `payload_json` + `files[0]`, flag 4096 |
| Superbloc | `PATCH {webhook}/messages/{id}` (remplacement de la pièce jointe) |
| Lecture | `GET {webhook}/messages/{id}` puis téléchargement de `attachments[0].url` (lien frais) |
| Suppression | `DELETE {webhook}/messages/{id}` ; 404 toléré lors des purges |
| Infos webhook | `GET {webhook}` : nom, `guild_id`, `channel_id` (pas les noms du serveur et du salon) |
| 429 / 5xx / coupures | attente `retry_after` ; backoff 1 s → 30 s, 8 tentatives |
| Parallélisme | un worker par webhook choisi, une file de morceaux commune à tous les fichiers d'un envoi |

Un webhook ne peut relire et supprimer que **ses propres** messages : ne jamais supprimer dans Discord un webhook
qui a servi.

## Erreurs

```
DSCSError
├── ConfigError       config, identité, trousseau ou webhook manquant ou invalide
├── CryptoError       phrase de passe, bloc scellé, morceau altéré, clé absente du trousseau
│   └── RevokedError  cet appareil a été retiré du coffre
├── IntegrityError    index incomplet, SHA-256 différent
├── IndexStateError   index illisible, trop récent, verrouillé
└── DiscordError      refus de Discord ou échec après retries (.status)
```

## Tests

| Dossier | Couvre |
|---|---|
| `tests/unit/` | utilitaires, crypto, scellement, identité, trousseau, KeyStore, config, index et migrations, verrou, CLI hors ligne |
| `tests/integration/` | transferts, rotation, clés absentes, webhooks choisis, destinations ; appareils (appairage, partage, révocation, concurrence, trousseau forgé, retour en arrière, secours) ; appairage complet en CLI |
| `tests/tui/` | scénario clavier complet (envoi avec choix des webhooks, sélection multiple, destination), touches h j k l dans les fenêtres, bandeau d'accueil non bloquant |
| `tests/fakes/fake_discord.py` | faux serveur : 429, limite 10 Mio, liens expirés, PATCH, suppressions refusables |

## Feuille de route

1. **Envoi plus rapide** : (fait en 0.4 : une file commune à tous les fichiers, tous les webhooks en parallèle) ;
   reste : plafond de mémoire réglable, synchronisation allégée (index dans le superbloc quand il est petit).
2. **Regroupement des petits fichiers** dans un même morceau (nouveau `CHUNK_FORMAT`).
3. **Récupération par bot** : lister les salons pour retrouver des messages d'un webhook perdu.
4. **Reprise d'envoi** après interruption.
5. **Opérations distantes** : renommer, déplacer, dossiers vides, recherche.
6. **Compression** optionnelle avant chiffrement.
