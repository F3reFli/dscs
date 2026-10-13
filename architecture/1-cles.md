# 1. Clés et secrets

[← Vue d'ensemble](README.md) · Code : `crypto/device.py`, `crypto/sealing.py`, `crypto/keyring.py`,
`crypto/keys.py`, `services/keystore.py`

Trois niveaux de clés : la **phrase de passe** (optionnelle) protège la **clé de l'appareil**, qui ouvre le
**trousseau du coffre**, qui contient les **clés de coffre** qui chiffrent les fichiers. Aucune clé n'est jamais écrite
ni envoyée en clair.

## Hiérarchie des clés

```mermaid
flowchart TD
    PASS["🔑 Phrase de passe<br/>(optionnelle, jamais stockée)"]
    SCRYPT["scrypt<br/>N = 2^16, r = 8, p = 1<br/>sel aléatoire 16 o · ≈ 64 Mio de mémoire"]
    WRAP["Clé d'emballage<br/>32 o"]
    DEVFILE[("device.json<br/>nom · clé publique · clé privée<br/>chiffrée (scrypt) ou en clair")]
    DEVKEY["Clé privée X25519 de l'appareil<br/>32 o, ne quitte jamais le PC"]
    RINGFILE[("keyring.sealed<br/>trousseau scellé par et pour cet appareil")]
    RING["Trousseau du coffre (JSON)"]
    VKEYS["Clés de coffre AES-256<br/>une par génération, la plus récente sert aux envois"]
    HOOKS["URLs des webhooks<br/>(tokens : droit d'écrire et supprimer)"]
    SB["Adresse du superbloc<br/>webhook + identifiant du message"]
    MEMBERS["Membres (clés publiques), révoqués,<br/>webhooks retirés"]
    CHUNKS["Morceaux des fichiers"]
    SNAP["Instantané de l'index"]

    PASS --> SCRYPT --> WRAP
    WRAP -->|"AES-256-GCM<br/>AAD = dscs:device-key:v1: ‖ clé publique"| DEVKEY
    DEVFILE --> DEVKEY
    RINGFILE --> RING
    DEVKEY -->|"ouvre (voir scellement)"| RING
    RING --> VKEYS
    RING --> HOOKS
    RING --> SB
    RING --> MEMBERS
    VKEYS -->|"AES-256-GCM"| CHUNKS
    VKEYS -->|"zlib + AES-256-GCM<br/>et HMAC-SHA256 pour l'empreinte"| SNAP
```

Sans phrase de passe, la clé privée est dans `device.json` en clair, protégée seulement par les droits `600` du
fichier : `dscs device passphrase` l'ajoute à tout moment.

## Ouverture du coffre (au lancement de chaque commande)

```mermaid
sequenceDiagram
    autonumber
    participant U as Toi
    participant CLI as dscs (CLI ou TUI)
    participant KS as KeyStore
    participant DEV as device.json
    participant RING as keyring.sealed
    participant IDX as index.db

    CLI->>KS: Vault.open()
    KS->>DEV: lecture
    alt clé privée protégée
        KS->>U: Phrase de passe de cet appareil ?
        U-->>KS: phrase (ou variable DSCS_PASSPHRASE)
        KS->>KS: scrypt(phrase, sel) donne une clé de 32 o
        KS->>KS: AES-256-GCM déchiffre la clé privée (tag faux = phrase incorrecte)
    else sans protection
        KS->>KS: clé privée lue telle quelle
    end
    KS->>KS: vérifie que la clé privée redonne la clé publique enregistrée
    KS->>RING: lecture du trousseau scellé
    KS->>KS: open_sealed : 2 échanges X25519, HKDF-SHA256, AES-256-GCM
    KS->>KS: refuse un trousseau local scellé par un autre appareil
    KS-->>CLI: trousseau : clés de coffre, webhooks, superbloc, membres
    CLI->>IDX: ouverture (verrou partagé, migration du schéma si besoin)
    Note over CLI: Aucune requête réseau : dscs ls marche hors ligne
```

## Scellement d'un trousseau pour un appareil

Utilisé pour `keyring.sealed` (scellé par l'appareil pour lui-même) et dans le superbloc (un exemplaire par appareil
membre). Dans l'esprit de HPKE en mode authentifié.

```mermaid
flowchart LR
    subgraph EXP["Appareil expéditeur"]
        SPRIV["Clé privée expéditeur"]
        EPH["Clé éphémère X25519<br/>neuve à chaque scellement"]
    end
    subgraph DEST["Appareil destinataire"]
        DPUB["Clé publique destinataire"]
    end
    DH1["DH(éphémère, destinataire)"]
    DH2["DH(expéditeur, destinataire)"]
    HKDF["HKDF-SHA256<br/>sel = pub. éphémère ‖ pub. expéditeur ‖ pub. destinataire<br/>info = dscs:seal:v1: ‖ dscs:keyring:v1: ‖ id du coffre"]
    KEY["Clé AES-256 à usage unique"]
    GCM["AES-256-GCM<br/>nonce aléatoire 12 o<br/>AAD = en-tête (MAGIC ‖ pub. expéditeur ‖ pub. éphémère)"]
    BLOB["Bloc scellé"]

    EPH --> DH1
    DPUB --> DH1
    SPRIV --> DH2
    DPUB --> DH2
    DH1 --> HKDF
    DH2 --> HKDF
    HKDF --> KEY --> GCM --> BLOB
```

| Octets | Contenu |
|---|---|
| id du coffre ‖ `\n` | en clair, pour le contexte (vérifié après ouverture) |
| 8 | `DSCSSL01` (MAGIC) |
| 32 | clé publique de l'expéditeur |
| 32 | clé publique éphémère |
| 12 | nonce |
| n | trousseau chiffré (JSON) |
| 16 | tag GCM |

- **Confidentialité** : seul le détenteur de la clé privée du destinataire recalcule les deux échanges DH.
- **Authenticité** : seul le détenteur de la clé privée de l'expéditeur a pu calculer DH(expéditeur, destinataire).
  L'appelant vérifie ensuite que cet expéditeur est un membre de confiance (voir [Synchronisation](4-synchronisation.md#confiance)).
- **Contexte** : un bloc scellé pour un usage ou un coffre ne s'ouvre pas pour un autre.

## Identifiants dérivés (publics)

```mermaid
flowchart LR
    PUB["Clé publique X25519<br/>32 o"] -->|"SHA-256(dscs:device-id:v1: ‖ pub), 16 hex"| ID["Identifiant d'appareil"]
    PUB -->|"SHA-256(dscs:device-code:v1: ‖ pub), 12 premiers o mod 10^20"| CODE["Code de vérification<br/>20 chiffres, ≈ 66 bits<br/>ex. 2703-7423-0506-9497-1856"]
    PUB -->|"base32"| TXT["Texte à transmettre<br/>dscs-pub-…"]
    VK["Clé de coffre AES-256"] -->|"SHA-256(dscs:key-fingerprint:v1: ‖ clé), 16 hex"| FP["Empreinte de la clé<br/>enregistrée avec chaque fichier"]
```

L'empreinte de clé dit quelle génération a chiffré un fichier sans permettre de retrouver la clé ; le code de
vérification se compare entre deux écrans pour déjouer une substitution de clé publique (voir
[Appareils](5-appareils.md)).
