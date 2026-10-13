# 2. Envoi : du disque à Discord

[← Vue d'ensemble](README.md) · Code : `transfer/plan.py`, `transfer/upload.py`, `crypto/cipher.py`,
`discord/webhook.py`, `discord/http.py`, `index/repository.py`, `services/vault.py` (`upload_many`)

## Le pipeline complet

```mermaid
flowchart TD
    subgraph PREP["1 · Préparer"]
        SEL["Sélection<br/>TUI : espace puis u · CLI : dscs put [file] [folder]…"]
        PLAN["plan_upload<br/>dossiers parcourus récursivement<br/>chaque fichier : chemin, dossier distant, taille"]
        CONF["Confirmation (TUI)<br/>fichiers, total, nombre de morceaux,<br/>webhooks cochés"]
        SEL --> PLAN --> CONF
    end

    subgraph PROD["2 · Lire et chiffrer : un producteur, fichier après fichier"]
        STAT["stat : taille relue<br/>n = max(1, ⌈taille / 9 Mio⌉)"]
        UID["uid = 16 octets aléatoires (hex)"]
        ADD["index.add_file<br/>complete = 0 : envoi en cours"]
        READ["Lecture du morceau i<br/>9 Mio au plus, dans un thread"]
        SHA["SHA-256 du fichier<br/>mis à jour morceau après morceau"]
        ENC["AES-256-GCM avec la clé de coffre courante<br/>nonce = 12 octets aléatoires<br/>AAD = « uid:i »"]
        BLOB["blob = nonce ‖ chiffré ‖ tag<br/>taille + 28 octets"]
        QUEUE[["File bornée<br/>2 × nombre de webhooks morceaux<br/>mémoire indépendante de la taille des fichiers"]]
        CHECK["Fin du fichier : taille et EOF<br/>inchangées depuis le début ?"]
        STAT --> UID --> ADD --> READ --> SHA --> ENC --> BLOB --> QUEUE
        BLOB -->|"morceau suivant"| READ
        READ -->|"dernier morceau lu"| CHECK
    end

    subgraph SEND["3 · Envoyer : un flux par webhook choisi"]
        TAKE["Le premier flux libre<br/>prend le morceau suivant de la file"]
        NAME["Nom de pièce jointe aléatoire<br/>16 hex + .bin : ni nom ni extension"]
        POST["POST /webhooks/id/token?wait=true<br/>multipart : payload_json + files[0]<br/>flags = 4096 : aucune notification"]
        HTTPL["Couche HTTP<br/>429 : attendre retry_after · quota à 0 : attendre<br/>5xx ou coupure : 1 s, 2 s… jusqu'à 30 s, 8 essais"]
        RESP["Réponse : id du message, id du salon"]
        CHUNK["index.add_chunk<br/>fichier, i, webhook, message, taille"]
        TAKE --> NAME --> POST --> HTTPL --> RESP --> CHUNK
    end

    subgraph DONE["4 · Valider"]
        ALL{"Tous les morceaux sur Discord<br/>et fichier lu en entier ?"}
        COMPLETE["index.mark_complete<br/>complete = 1, SHA-256 enregistré"]
        PUSH["En fin de commande : le coffre est publié<br/>(instantané d'index chiffré, voir Synchronisation)"]
        ALL -->|"oui"| COMPLETE --> PUSH
    end

    subgraph FAIL["Échecs"]
        F1["Fichier modifié, illisible ou refusé par Discord"]
        F2["purge_file : ses messages sont supprimés,<br/>les autres fichiers continuent"]
        F3["Annulation (c) : tous les fichiers pas encore complets<br/>sont purgés, les complets restent"]
        F4["Processus tué : l'envoi reste « incomplet » dans l'index<br/>dscs gc supprime ses morceaux plus tard"]
        F1 --> F2
    end

    CONF --> STAT
    QUEUE --> TAKE
    CHECK --> ALL
    CHUNK --> ALL
    CHECK -->|"modifié"| F1
    HTTPL -->|"refus définitif"| F1
```

## Un morceau, tel qu'il part sur Discord

| Octets | Contenu |
|---|---|
| 12 | nonce aléatoire (jamais réutilisé avec la même clé) |
| ≤ 9 Mio | données chiffrées en AES-256-GCM (clé de coffre courante, 256 bits) |
| 16 | tag d'authentification GCM |

- **AAD = `uid:idx`** : le tag lie le morceau à *son* fichier (uid aléatoire) et à *sa* place. Un morceau modifié,
  déplacé dans le fichier ou transplanté dans un autre fichier ne se déchiffre pas.
- **Format 1** (`CHUNK_FORMAT`), enregistré avec chaque fichier : un futur format restera reconnaissable.
- Taille par défaut **9 Mio** (`chunk_size_mb`) : Discord refuse plus de 10 Mio par pièce jointe sur un serveur
  sans boost.

La requête vers Discord :

```text
POST https://discord.com/api/webhooks/<id>/<token>?wait=true
Content-Type: multipart/form-data
  payload_json = {"content": "", "flags": 4096, "attachments": [{"id": 0, "filename": "a3f91c5e0b7d2e84.bin"}]}
  files[0]     = <nonce ‖ chiffré ‖ tag>                      (application/octet-stream)
→ 200 {"id": "<message>", "channel_id": "<salon>", "attachments": [{"url": "https://cdn.discordapp.com/…"}]}
```

`wait=true` fait renvoyer le message créé : son identifiant est ce qui permettra de le relire et de le supprimer.

## Parallélisme : tous les webhooks travaillent en même temps

Les morceaux de tous les fichiers passent par la même file ; chaque webhook a son flux et prend le morceau suivant dès
qu'il est libre. Un petit fichier (un seul morceau) part donc sur le premier webhook libre, un gros fichier se répartit
sur tous, et il n'y a pas de pause entre deux fichiers. Exemple avec 2 webhooks et 3 fichiers :

```mermaid
sequenceDiagram
    participant P as Producteur
    participant Q as File bornée
    participant W1 as Flux webhook 1 (salon 1)
    participant W2 as Flux webhook 2 (salon 2)
    participant D as API Discord
    participant I as Index

    P->>Q: [file-1] morceau 0
    P->>Q: [file-1] morceau 1
    Q->>W1: [file-1] morceau 0
    Q->>W2: [file-1] morceau 1
    P->>Q: [file-2] morceau 0
    P->>Q: [file-3] morceau 0
    par en même temps
        W1->>D: POST (salon 1)
        D-->>W1: message 111
        W1->>I: add_chunk([file-1], 0, webhook 1, 111)
    and
        W2->>D: POST (salon 2)
        D-->>W2: message 222
        W2->>I: add_chunk([file-1], 1, webhook 2, 222)
    end
    Note over I: [file-1] complet : mark_complete(SHA-256)
    Q->>W1: [file-2] morceau 0
    Q->>W2: [file-3] morceau 0
    par en même temps
        W1->>D: POST (salon 1)
        D-->>W1: message 333
    and
        W2->>D: POST (salon 2)
        D-->>W2: message 444
    end
    Note over I: [file-2] et [file-3] complets
```

Discord limite le nombre de messages **par salon** : ce sont des webhooks dans des **salons différents** qui
additionnent les débits. `dscs bench` mesure ce que donne chaque salon.

## Ce que l'index retient d'un envoi

```mermaid
erDiagram
    FILES ||--o{ CHUNKS : "est découpé en"
    WEBHOOKS ||--o{ CHUNKS : "porte"
    FILES {
        int id "affiché par dscs ls"
        text uid "aléatoire, dans l'AAD"
        text name "nom d'origine"
        text folder "dossier distant"
        int size "taille en clair"
        text sha256 "empreinte du fichier"
        int chunks "nombre de morceaux"
        int format "format des morceaux"
        text key_id "empreinte de la clé de coffre"
        real created "date d'envoi"
        int complete "0 en cours, 1 terminé"
    }
    CHUNKS {
        int file_id "fichier"
        int idx "place dans le fichier"
        text webhook_id "webhook qui l'a envoyé"
        text message_id "message Discord"
        int size "taille chiffrée"
    }
    WEBHOOKS {
        text id "jamais le token"
        text name "cache d'affichage"
        text guild_id "serveur"
        text channel_id "salon"
    }
    TOMBSTONES {
        text uid "fichier supprimé"
        real deleted "date"
    }
```

L'index ne contient **aucun secret** : ni clé, ni token de webhook. Il est publié chiffré pour les autres appareils
(voir [Synchronisation](4-synchronisation.md)).
