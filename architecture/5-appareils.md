# 5. Appareils : appairage, révocation, rotation, secours

[← Vue d'ensemble](README.md) · Code : `services/devices.py`, `services/vault.py`, `crypto/keyring.py`,
`sync/invitation.py`, `cli/commands.py` (`device`, `key`)

## Cycle de vie d'un appareil

```mermaid
stateDiagram-v2
    state "Sans identité" as None
    state "Identité créée<br/>clé X25519 + code" as Identity
    state "Membre du coffre<br/>reçoit les clés et l'index" as Member
    state "Retiré<br/>ne reçoit plus rien" as Revoked

    [*] --> None
    None --> Member: dscs init (nouveau coffre)
    None --> Identity: dscs device init
    Identity --> Member: dscs device join (après device add)
    Identity --> Member: dscs device recover (kit de secours)
    Member --> Member: sync · rotation · ajout d'appareils
    Member --> Revoked: dscs device revoke (depuis un autre membre)
    Revoked --> [*]
```

## Appairage : aucune clé secrète ne circule

```mermaid
sequenceDiagram
    autonumber
    actor U as Toi
    participant B as Nouvel appareil B
    participant A as Appareil membre A
    participant D as Discord (superbloc)

    U->>B: dscs device init
    B->>B: génère une paire X25519 (phrase de passe proposée)
    B-->>U: clé publique dscs-pub-… et code de B
    U->>A: dscs device add dscs-pub-…
    A-->>U: code calculé depuis cette clé publique
    U->>U: compare avec le code affiché par B
    Note over U,A: Codes différents : la clé a été remplacée en chemin, on arrête
    A->>D: pull
    A->>A: trousseau : ajoute le membre B
    A->>D: push : un trousseau scellé par A pour chaque membre, B compris
    A-->>U: invitation dscs-inv1-… et code de A
    Note over U: L'invitation = id du coffre + URL du webhook du superbloc + id du message, aucune clé
    U->>B: dscs device join dscs-inv1-…
    B->>D: lit le superbloc à cette adresse
    B->>B: ouvre le trousseau scellé pour B, l'expéditeur est A
    B-->>U: nom et code de A
    U->>U: compare avec le code affiché par A
    B->>B: enregistre keyring.sealed (re-scellé par B pour B)
    B->>D: pull : instantané de l'index, B voit tous les fichiers
```

La double comparaison des codes empêche quiconque de glisser sa propre clé publique à la place de celle de B, ou de
se faire passer pour A. L'invitation donne seulement l'*adresse* du coffre : sans être membre, on n'y lit rien.
Elle contient pourtant l'URL d'un webhook (droit d'écrire et de supprimer) : à transmettre par un canal privé.

## Révocation et rotation

```mermaid
flowchart TD
    subgraph REVOKE["dscs device revoke B (sur A)"]
        R1["pull"] --> R2["B sort des membres<br/>et entre dans les révoqués"]
        R2 --> R3["Nouvelle génération de clé de coffre<br/>(rotation automatique)"]
        R3 --> R4["push : trousseau scellé pour les membres restants<br/>les exemplaires pour B sont retirés"]
        R4 --> R5["B, à sa prochaine synchro :<br/>plus de trousseau pour lui, accès coupé"]
    end
    subgraph ROTATE["dscs key rotate"]
        K1["Nouvelle clé AES-256<br/>devient la clé courante"] --> K2["Anciennes clés gardées :<br/>les fichiers déjà envoyés restent lisibles"]
        K2 --> K3["push : tous les membres la reçoivent"]
        K3 --> K4["Les prochains envois l'utilisent<br/>(key_id enregistré par fichier)"]
    end
```

Limites assumées : un appareil retiré garde ce qu'il a déjà pu lire (anciennes clés, fichiers envoyés avant) et
connaît les URLs des webhooks, donc peut encore supprimer des messages. Ne partage le coffre qu'avec des appareils de
confiance.

## Kit de secours

```mermaid
sequenceDiagram
    autonumber
    actor U as Toi
    participant A as Appareil membre
    participant D as Discord (superbloc)
    participant N as Nouvel appareil

    U->>A: dscs key recovery -o [file]
    A->>A: génère une identité « de secours » (rôle recovery)
    A->>A: l'ajoute aux membres
    A->>D: push : un trousseau scellé aussi pour l'appareil de secours
    A-->>U: kit dscs-kit1-… = invitation + clé privée de secours
    Note over U: À garder hors ligne : le kit donne accès à tout le coffre
    Note over A,N: Plus tard, tous les appareils perdus
    U->>N: dscs device recover dscs-kit1-…
    N->>D: lit le superbloc à l'adresse du kit
    N->>N: ouvre le trousseau scellé pour l'appareil de secours
    N->>N: n'accepte qu'un trousseau scellé par un de ses propres membres
    N-->>U: liste des membres, à reconnaître
    N->>N: crée sa propre identité et s'ajoute aux membres
    N->>D: push au nom de l'appareil de secours (déjà de confiance pour les autres)
```

## Réparer le point d'entrée du coffre

Un webhook déplacé dans un autre salon ne retrouve plus ses anciens messages, dont le superbloc.

```mermaid
flowchart TD
    L1["Superbloc introuvable (404)"] --> L2{"Seul appareil actif ?<br/>(les appareils de secours ne comptent pas)"}
    L2 -->|"oui"| L3["Recréé automatiquement<br/>dans le salon actuel du webhook"]
    L2 -->|"non"| L4["dscs sync --repair<br/>sur UN seul appareil"]
    L4 --> L3
    L3 --> L5["Nouvelle adresse datée dans le trousseau,<br/>la plus récente l'emporte à la fusion"]
    L5 --> L6["dscs device invite<br/>puis dscs device join sur chaque autre appareil"]
    L6 --> L7["L'autre appareil n'accepte l'adresse que dans<br/>un trousseau scellé par un membre qu'il connaît"]
    L5 --> L8["Refaire le kit de secours :<br/>l'ancien pointe vers l'ancienne adresse"]
```

## Retirer un webhook du coffre

```mermaid
flowchart TD
    W1["dscs webhooks rm [webhook]"] --> W2["pull, puis vérifications :<br/>pas le dernier webhook ;<br/>porte le superbloc ? seul appareil ou --repair"]
    W2 --> W3["Liste des fichiers qui ont au moins un morceau dessus<br/>affichée, puis confirmation"]
    W3 --> W4["Trousseau : webhook retiré, définitivement<br/>(une config qui le liste encore ne le ramène pas)"]
    W4 --> W5["Index : ces fichiers reçoivent une pierre tombale<br/>et deviennent incomplets"]
    W5 --> W6["Superbloc dessus ? recréé sur un autre webhook"]
    W6 --> W7["Morceaux de ces fichiers sur les autres webhooks supprimés"]
    W7 --> W8["push : les autres appareils l'oublient à leur synchro"]
    W8 --> W9["Messages du webhook supprimés s'il existe encore dans Discord<br/>URL retirée de config.toml"]
```
