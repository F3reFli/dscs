# 6. Discord et sécurité

[← Vue d'ensemble](README.md) · Code : `discord/http.py`, `discord/webhook.py`, `transfer/purge.py`,
`services/vault.py` (`delete`, `collect_garbage`), `index/lock.py`

## Une requête vers Discord

Toutes les requêtes passent par `discord.http.request` : session unique, en-tête `User-Agent` DSCS, connexion en
30 s maximum, lecture coupée après 180 s de silence.

```mermaid
flowchart TD
    REQ["Requête : POST, GET, PATCH ou DELETE"] --> SEND["Envoi (formulaire recréé à chaque essai)"]
    SEND --> CODE{"Réponse"}
    CODE -->|"429 trop de requêtes"| R429["Attendre retry_after (+ 0,1 s)<br/>compté dans les freinages du webhook"]
    R429 --> TRY
    CODE -->|"5xx ou coupure réseau"| R5["Attendre 1 s, 2 s, 4 s… (30 s au plus)"]
    R5 --> TRY{"Moins de 8 essais ?"}
    TRY -->|"oui"| SEND
    TRY -->|"non"| FAIL["Échec après 8 tentatives"]
    CODE -->|"autre 4xx"| E4["Erreur immédiate<br/>401 token · 403 droits · 404 introuvable · 413 trop gros"]
    CODE -->|"2xx"| OK["Succès"]
    OK --> BUCKET{"X-RateLimit-Remaining = 0 ?"}
    BUCKET -->|"oui"| WAITB["Attendre X-RateLimit-Reset-After<br/>avant la requête suivante"]
    BUCKET -->|"non"| RET["Réponse renvoyée"]
    WAITB --> RET
```

Discord limite les requêtes **par webhook** et les messages **par salon**. dscs ne cherche pas à contourner ces
limites : il les respecte et répartit le travail sur plusieurs webhooks dans des salons différents.

## Cycle de vie d'un message

```mermaid
stateDiagram-v2
    state "Créé<br/>POST ?wait=true" as Created
    state "Relu<br/>GET du message, lien CDN frais" as Read
    state "Remplacé<br/>PATCH (superbloc seulement)" as Replaced
    state "Supprimé<br/>DELETE (déjà absent : pas une erreur)" as Deleted
    state "Inaccessible<br/>webhook supprimé ou déplacé dans un autre salon" as Lost

    [*] --> Created
    Created --> Read
    Read --> Read
    Created --> Replaced
    Replaced --> Replaced
    Created --> Deleted
    Read --> Deleted
    Created --> Lost
    Deleted --> [*]
```

Un webhook ne peut relire, remplacer et supprimer **que ses propres messages, dans son salon actuel**. Ne jamais
supprimer ni déplacer un webhook qui a servi : pour un nouveau salon, créer un nouveau webhook.

## Le journal (`-v`, `-vv`, `--log`)

Chaque étape ci-dessus peut être suivie : `-v` montre la synchronisation, chaque fichier, les freinages et les
nouvelles tentatives ; `-vv` chaque requête (méthode, route, code, durée) et chaque morceau. Les URLs y passent par
`utils.redact_secrets` : `…/webhooks/<id>/***`, liens CDN `…&hm=***`, et aucune clé n'est jamais écrite. Un filtre
applique le même masquage en dernier recours sur tout ce qui sort.

## Suppressions et nettoyage

```mermaid
flowchart TD
    DEL["dscs rm id · x dans l'onglet Distant"] --> D1["Le fichier est marqué incomplet<br/>et reçoit une pierre tombale"]
    D1 --> D2["DELETE de chacun de ses messages<br/>chaque morceau supprimé sort de l'index"]
    D2 --> D3{"Tous supprimés ?"}
    D3 -->|"oui"| D4["Le fichier sort de l'index<br/>la pierre tombale reste et se synchronise"]
    D3 -->|"non : réseau, webhook…"| D5["Reste incomplet : dscs gc réessaiera"]
    GC["dscs gc"] --> G1["Verrou exclusif : aucune autre instance de dscs<br/>(jamais l'envoi en cours d'une autre fenêtre)"]
    G1 --> G2["Pour chaque fichier incomplet : DELETE de ses morceaux"]
    G2 --> G3["Bilan : nettoyés, restants"]
```

Les envois interrompus (processus tué, coupure) laissent aussi des fichiers incomplets : leurs morceaux sont déjà
inscrits dans l'index au fur et à mesure, donc `dscs gc` sait toujours les retrouver.

## Ce que Discord voit, et ce qu'il ne voit pas

| Discord voit | Discord ne voit pas |
|---|---|
| Des messages sans texte, notifications coupées | Le nom, le dossier, le type des fichiers |
| Des pièces jointes `16 hex.bin` d'environ 9 Mio (la dernière plus petite) | Le contenu (AES-256-GCM) |
| Le nombre de morceaux, leur taille, leur date | Le nombre de fichiers ni le découpage (dans l'index, chiffré) |
| Les identifiants des webhooks et des salons | Les clés de coffre, les clés privées des appareils |
| Le superbloc et l'instantané, en blocs chiffrés ou scellés | La liste des fichiers, les membres en clair |

## Attaques et réponses

| Si quelqu'un (Discord ou un tiers qui a une URL de webhook)… | Ce qui se passe |
|---|---|
| modifie un morceau | tag GCM invalide : refusé au déchiffrement |
| déplace un morceau ou le met dans un autre fichier | AAD `uid:idx` différente : refusé |
| remet un vieil instantané de l'index | numéro de séquence plus petit que celui connu : ignoré |
| glisse un trousseau scellé par un appareil inconnu | ignoré, avec un avertissement |
| donne une fausse invitation à un appareil membre | ignorée : seule compte une adresse scellée par un membre connu |
| remplace la clé publique d'un nouvel appareil pendant l'appairage | codes de vérification différents : l'appairage s'arrête |
| supprime les messages | possible : DSCS ne protège pas la **disponibilité**, garde d'autres copies |
| bannit le compte ou le serveur | possible : stocker des fichiers ainsi sort de l'usage prévu de Discord |
