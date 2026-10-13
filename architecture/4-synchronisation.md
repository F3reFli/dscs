# 4. Synchronisation entre appareils

[← Vue d'ensemble](README.md) · Code : `sync/superblock.py`, `sync/snapshot.py`, `sync/engine.py`,
`index/repository.py` (`export_state`, `import_state`), `crypto/keyring.py` (`merge`)

Un webhook ne peut pas lister un salon : il faut un **point d'entrée connu**, le *superbloc*. C'est un message fixe
dont la pièce jointe est remplacée (`PATCH`) à chaque publication. Son adresse (webhook + identifiant du message) est
dans le trousseau et dans les invitations.

## Ce que contient le superbloc

```mermaid
flowchart LR
    subgraph SB["Superbloc (JSON, pièce jointe au nom aléatoire)"]
        direction TB
        META["format = dscs-superblock · version · id du coffre"]
        subgraph KR["keyrings : destinataire → expéditeur → trousseau scellé"]
            KA["pour l'appareil A<br/>scellé par A, scellé par B"]
            KB["pour l'appareil B<br/>scellé par A, scellé par B"]
            KR_["pour l'appareil de secours<br/>scellé par A, scellé par B"]
        end
        subgraph IP["index : pointeur vers le dernier instantané"]
            SEQ["seq : numéro de séquence"]
            KID["key_id : génération de clé utilisée"]
            DIG["digest : HMAC de l'état"]
            PARTS["parts : (webhook, message) des morceaux"]
        end
    end
    PARTS --> SNAP["Instantané chiffré<br/>envoyé comme des morceaux ordinaires"]
```

Rien n'y est lisible sans clé : chaque trousseau n'est ouvrable que par son destinataire, et le pointeur ne dit que
*où* est l'instantané, pas ce qu'il contient.

## L'instantané de l'index

```mermaid
flowchart LR
    STATE["export_state<br/>fichiers complets et leurs morceaux<br/>+ pierres tombales, triés"] --> JSON["JSON canonique<br/>clés triées, sans espaces"]
    JSON --> HMAC["HMAC-SHA256(clé de coffre,<br/>dscs:index-digest:v1: ‖ JSON)<br/>32 hex = digest"]
    JSON --> DOC["document : format, version,<br/>id du coffre, seq, état"]
    DOC --> ZLIB["zlib niveau 9"]
    ZLIB --> GCM["AES-256-GCM, clé de coffre courante<br/>AAD = dscs:index:v1:coffre:seq"]
    GCM --> SPLIT["Découpé en morceaux de 9 Mio<br/>envoyés sur les webhooks"]
```

- Le **digest** permet de voir « rien de neuf » sans rien télécharger, sans révéler le contenu à qui n'a pas la clé.
- Le **numéro de séquence** est dans l'AAD : un vieil instantané ne peut pas être présenté comme récent.
- Les fichiers **incomplets** (envoi ou suppression en cours) ne sont jamais partagés.

## Recevoir (pull) : au début de chaque connexion

```mermaid
flowchart TD
    START["vault.connected() ou dscs sync"] --> READ["Lire le superbloc<br/>GET message puis CDN"]
    READ -->|"404 : message supprimé<br/>ou webhook déplacé"| LOST{"Seul appareil actif,<br/>ou --repair ?"}
    LOST -->|"oui"| RELOC["Recréer le superbloc ailleurs<br/>adresse datée, publiée au push"]
    LOST -->|"non"| ERR["Erreur : réparer sur UN appareil<br/>puis dscs device join sur les autres"]
    RELOC --> READ
    READ --> MINE["Trousseaux scellés pour cet appareil"]
    MINE --> OPEN["open_sealed chacun<br/>l'identifiant de l'expéditeur doit correspondre"]
    OPEN --> TRUST["merge_trusted : ne fusionner que ceux<br/>scellés par un membre de confiance"]
    TRUST --> REV{"Cet appareil est retiré<br/>ou n'est plus membre ?"}
    REV -->|"oui"| REVOKED["RevokedError : accès coupé"]
    REV -->|"non"| NEWER{"seq distant plus récent<br/>que le seq local ?"}
    NEWER -->|"non"| UPTODATE["À jour : rien à télécharger"]
    NEWER -->|"oui"| HASKEY{"Clé key_id<br/>dans le trousseau ?"}
    HASKEY -->|"non"| WAIT["Erreur : clé pas encore reçue,<br/>réessayer après une synchro de l'autre appareil"]
    HASKEY -->|"oui"| DL["Télécharger les morceaux de l'instantané"]
    DL -->|"morceau disparu : publication en cours"| READ
    DL --> DEC["Déchiffrer, AAD = coffre:seq<br/>zlib, vérifier format, coffre, seq"]
    DEC --> IMPORT["import_state<br/>union des fichiers et pierres tombales<br/>une pierre tombale l'emporte toujours"]
    IMPORT --> SAVE["seq local = seq distant"]
```

## Publier (push) : en fin de commande s'il y a eu des changements

```mermaid
flowchart TD
    P0["Fin de vault.connected() avec modifications,<br/>dscs sync, ajout ou retrait d'appareil, rotation"] --> P1{"Superbloc<br/>existe ?"}
    P1 -->|"non"| P2["Créer le superbloc<br/>sur le premier webhook qui répond"]
    P1 -->|"oui"| P3
    P2 --> P3["pull (voir ci-dessus)"]
    P3 --> P4["export_state + digest HMAC"]
    P4 --> P5{"Index distant identique,<br/>même clé, sur des webhooks du coffre ?<br/>Trousseaux à jour pour chaque membre ?"}
    P5 -->|"oui"| P6["Rien à publier"]
    P5 -->|"non"| P7["Index changé : seal_state, seq + 1,<br/>envoi des morceaux de l'instantané"]
    P7 --> P8["Trousseau changé : sceller un exemplaire<br/>pour chaque membre, retirer les révoqués"]
    P8 --> P9{"Relire le superbloc :<br/>a-t-il bougé depuis le pull ?"}
    P9 -->|"oui : un autre appareil a publié"| P10["Supprimer les morceaux envoyés<br/>et recommencer (4 essais)"]
    P10 --> P3
    P9 -->|"non"| P11["PATCH : remplacer la pièce jointe du superbloc"]
    P11 --> P12["Supprimer l'ancien instantané<br/>retenir seq et l'empreinte du trousseau publié"]
```

Concurrence optimiste : un appareil ne publie jamais par-dessus un état distant qu'il n'a pas lu et fusionné. Deux
appareils qui publient en même temps : le second recommence avec l'état du premier, rien n'est perdu.

## Deux appareils, de bout en bout

```mermaid
sequenceDiagram
    autonumber
    participant A as Appareil A
    participant D as Discord
    participant B as Appareil B

    A->>D: pull : superbloc (seq 7)
    A->>D: envoi de [file] (morceaux chiffrés)
    A->>A: index local : [file] complet
    A->>D: push : instantané seq 8 (zlib + AES-GCM, AAD coffre:8)
    A->>D: PATCH superbloc : index seq 8, digest, parts
    A->>D: suppression de l'instantané seq 7
    Note over B: Plus tard, dscs sur B
    B->>D: pull : superbloc
    D-->>B: index seq 8 > seq local 7
    B->>D: GET des morceaux de l'instantané
    B->>B: déchiffre, import_state : [file] apparaît
    B->>D: GET des morceaux de [file] (s'il le télécharge)
```

## Fusion sans conflit

| Donnée | Règle de fusion |
|---|---|
| Fichiers | union par `uid` |
| Suppressions | union des pierres tombales ; une pierre tombale l'emporte sur le fichier |
| Clés de coffre | union ; la courante est la plus récente |
| Membres | union ; une révocation l'emporte |
| Webhooks | union ; un webhook retiré l'est partout et ne revient pas |
| Adresse du superbloc | la plus récente (une adresse recréée est datée) |

Le résultat ne dépend pas de l'ordre des fusions.

## Confiance

```mermaid
flowchart LR
    START["Trousseaux reçus,<br/>ouverts avec la clé de cet appareil"] --> T{"Expéditeur parmi les membres connus<br/>(ou cet appareil), non révoqué ?"}
    T -->|"oui"| MERGE["Fusionné : ses nouveaux membres<br/>deviennent à leur tour de confiance"]
    MERGE --> T
    T -->|"non, et plus aucun progrès"| IGNORE["Ignoré, avec un avertissement"]
```

Un appareil révoqué ne peut ni se ré-ajouter ni injecter de clé ; un trousseau forgé par un tiers qui connaît un
webhook est ignoré. Au premier contact, c'est la comparaison des codes de vérification qui crée la confiance (voir
[Appareils](5-appareils.md)).
