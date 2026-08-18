# Governed Content — trajectoire de convergence de Content Sync

Statut : **migration des contenus ordinaires terminée — #441 CLOSED ; convergence de façade #442 READY**  
Date : **2026-08-18**  
Ticket d'origine : **#385**  
Migration contrôlée : **#439, #440, #441**  
Parent : **#60**

## 1. Décision

Le Content Sync Agency a correctement rempli son rôle de bootstrap et de
promotion déterministe du contenu initial. Il ne doit plus être la source de
vérité Git du contenu éditorial ordinaire.

La cible est :

```text
contenu légal / réglementaire / contractuel contrôlé / ressource critique
-> Governed Content
-> source canonique versionnée
-> revue avant promotion
-> matérialisation Drupal déterministe

contenu marketing / éditorial ordinaire
-> Drupal
-> workflow éditeur normal
-> révisions / traductions / permissions Drupal
-> pas d'écrasement par Git
```

La migration des contenus ordinaires est désormais achevée :

- #439 a introduit le lifecycle `released`, la release/readmission explicite et
  les protections fail-closed ;
- #440 a libéré et prouvé les trois cas clients pilotes ;
- #451 a libéré les dix contenus `ai_feature` ;
- #458 puis #464 ont libéré les quatorze contenus `service` ;
- #470 a libéré trois pages ordinaires à impact moyen ;
- #474 a libéré le lot final `homepage`, `services`, `contact` ;
- #441 est fermé avec `LEGACY_RELEASE_PENDING_IDS = []`.

## 2. Sources de vérité

L'inventaire n'est **pas** maintenu manuellement dans cette documentation.

Les deux sources machine autoritatives sont :

```text
web/modules/custom/emerging_digital_content/content_sync/catalog.yml
Drupal\emerging_digital_content\ContentSync\Policy\GovernedContentPolicy
```

Le catalogue décrit les contenus encore sous contrôle Content Sync. La policy
distingue explicitement :

```text
GOVERNED_CONTENT_IDS
LEGACY_RELEASE_PENDING_IDS
```

La documentation explique les invariants, l'historique de migration et la cible.
Elle ne doit jamais devenir une seconde allowlist copiée à la main.

## 3. Inventaire courant après #474/#475

Inventaire recalculé depuis le catalogue et la policy versionnés sur `main`
après le merge `7511588f9b23366e4132b773236366ed57e6159c` :

```text
CATALOG_VERSION=1
CATALOG_COUNT=3
BUNDLE_COUNTS={"page":3}
MISSING_REFERENCED_FILES=[]
UNREFERENCED_NODE_FILES=[]
DUPLICATE_IDS=[]
ALL_HAVE_FR_EN=true
LEGACY_UUID_COUNT=3
```

La policy runtime contient :

```text
3 IDs = GOVERNED
0 ID = LEGACY_RELEASE_PENDING
```

Donc :

```text
3 entrées cataloguées
= 3 Governed Content légaux
+ 0 contenu ordinaire en transition
```

### 3.1 Governed Content conservé sous Git

Les trois contenus durablement admis sont :

| Source ID | Bundle | Classification | Motif |
|---|---|---|---|
| `mentions-legales` | `page` | `GOVERNED` | contenu légal |
| `politique-confidentialite` | `page` | `GOVERNED` | politique de confidentialité |
| `politique-cookies` | `page` | `GOVERNED` | politique cookies |

Ces contenus restent canoniques dans le repository jusqu'à décision contraire
explicite et revue.

### 3.2 Contenu ordinaire pending

Il n'existe plus de contenu ordinaire `LEGACY_RELEASE_PENDING`.

```text
service pending: 0
page ordinaire pending: 0
```

`GovernedContentPolicy::LEGACY_RELEASE_PENDING_IDS` est vide et ne doit pas être
réutilisé comme file d'attente pour de nouveaux contenus marketing.

### 3.3 Contenus ordinaires RELEASED

La trajectoire complète a libéré **33 contenus ordinaires** :

```text
3 case studies du pilote #440
+ 10 ai_feature sous #441
+ 14 service sous #441
+ 6 page sous #441
= 33 contenus RELEASED
```

#441 représentait les 30 contenus restant après le pilote #440 ; il est désormais
terminé.

## 4. Preuves de migration

Chaque lot réel a utilisé le même principe :

```text
base active
-> release candidate reviewed
-> mapping released
-> Content Sync sans réadmission implicite
-> modification éditoriale Drupal persistante
-> HEAD final exact toujours released
-> Browser Validation lorsque nécessaire
-> rollback/readmission active avec identité conservée
```

### 4.1 Lot `ai_feature` — #451

```text
workflow run: 31951005179
profile: ai-features-441
release candidate: bf6d3aaaaa5e697ecd3a319001c4800e611f9ddb
exact HEAD: 960b16d3a041846bd65adcc31a3100d8f5d144c9
result: PASS
browser: 40/40 release candidate + 40/40 exact HEAD
artifact sha256: 36dcb51eba0ca2146b68214c91e9b3ed07df5e43fd9f72607c040d1a40218aca
```

### 4.2 Premier lot services — #458

```text
workflow run: 31953623538
profile: services-drupal-441
release candidate: 0b40245817d3781046e5d596a1febee3323eed4e
exact HEAD: feff40159e973ecd242d2b7041bf38833c86d8ab
result: PASS
browser: 28/28 release candidate + 28/28 exact HEAD
artifact sha256: f0e03f4846a40201ff838a52a71d0e71d7d70361a1564122a7d6b677b8b2b5eb
```

### 4.3 Second lot services — #464

```text
workflow run: 32067198176
profile: services-general-441
release candidate: 295b1c8fb5afba2b0db93f13dcf6f07d73be6846
exact HEAD: c235264ed3c490b8defd359921cab5a857d9cb77
result: PASS
browser: 28/28 release candidate + 28/28 exact HEAD
artifact sha256: 87d2128494142f6602fc25b58515f1370a99b128797fa2c08316abd92fd91849
```

### 4.4 Pages à impact moyen — #470

```text
workflow run: 32070506061
profile: pages-medium-441
release candidate: edf8b8e5208cfc6fb7d5f3a79dc8c0babb72d586
exact HEAD: a3097178391830c2df1690bdc3387f4f5fbc2407
result: PASS
```

Le lot a libéré `cas-clients`, `equipe` et `ia-drupal` avant les pages à fort
impact.

### 4.5 Lot final — #474/#475

Le lot final a libéré exactement `homepage`, `services` et `contact` avec le
profil trusted `pages-final-441`.

```text
CI exact-head: 32089079390 / #867 / SUCCESS
gateway: 32089390437 / #7 / SUCCESS
proof: 32089398559 / #6 / SUCCESS
release candidate: 9d9d615679d345ddacbd11a3c4fcdbba35580fb5
exact HEAD: 6c420c65403ee036c3f5773f2157c5a99ac5dac2
merge main: 7511588f9b23366e4132b773236366ed57e6159c
result: PASS
artifact id: 9308024782
artifact sha256: caef27d7dd696c28e7470e05bad68ea193534eec3715db08f5bc040a1a42bd39
```

La preuve a confirmé :

- les trois mappings `active -> released -> released` ;
- conservation des node IDs, UUID et catalog hashes ;
- diffs d'identité et de node state vides ;
- `services` passé de révision 1 à 2 pendant l'edit proof ;
- marqueur `[proof-441-final-pages-editorial-survives]` conservé après resync ;
- 16/16 Playwright au release candidate et 16/16 au HEAD final ;
- six aliases FR/EN plus les fronts `/fr/` et `/en/` ;
- formulaire contact FR/EN visible avec les champs attendus et submit, sans
  soumission ;
- 16 captures release candidate et 16 captures HEAD final, paires correspondantes
  identiques ;
- rollback/readmission des trois mappings vers `active` avec identité conservée.

Post-merge :

```text
CI #870: 32116483748 / SUCCESS
Deploy Production #180: 32116483785 / SUCCESS
```

## 5. Invariant de release

```text
retirer un contenu de la gouvernance Git
!= supprimer Drupal
!= dépublier Drupal
!= recréer Drupal
```

Le lifecycle `released` implémenté dans #439 garantit cette séparation : un
mapping released est ignoré par le prune et ne peut pas être repris implicitement
par un Content Sync normal.

La commande de release a été la primitive de migration. Maintenant que pending=0,
elle reste une capacité d'audit/compatibilité et non un mécanisme de gestion du
contenu éditorial courant.

## 6. Taxonomie de gouvernance cible

Un contenu n'entre dans le futur catalogue Governed Content que s'il appartient
explicitement à une classe approuvée.

### 6.1 Légal et réglementaire

Exemples : mentions légales, confidentialité, cookies et notices réglementaires
réellement requises.

C'est le seul groupe actuellement matérialisé dans le catalogue.

### 6.2 Prompts système approuvés

Un prompt système peut être gouverné seulement si :

- il est réellement un artefact métier versionnable ;
- sa revue avant promotion est requise ;
- il ne contient aucun secret, token ou credential ;
- il possède une identité stable et une destination runtime définie ;
- le mécanisme de matérialisation est explicite.

Cette classe reste une capacité future, pas un prétexte pour créer de nouveaux
payloads.

### 6.3 Contrats, métadonnées contrôlées et ressources critiques

Éligibles uniquement lorsque l'artefact doit être identique entre environnements
et que l'historique Git/review apporte une garantie métier concrète.

Une ressource n'est pas « critique » simplement parce qu'elle est pratique à
versionner. Toute nouvelle admission exige un ticket et une justification.

## 7. États de cycle de vie

La terminologie historique utilise :

```text
GOVERNED
LEGACY_RELEASE_PENDING
RELEASED
RETIRED
```

État courant :

- `GOVERNED` : trois contenus légaux ;
- `LEGACY_RELEASE_PENDING` : ensemble vide ;
- `RELEASED` : contenus ordinaires désormais editor-owned dans Drupal ;
- `RETIRED` : retrait volontaire d'un contenu gouverné, opération distincte et
  explicite.

#442 doit maintenant déterminer quelles notions de transition restent utiles
dans la façade publique du module et lesquelles peuvent être renommées,
dépréciées ou confinées sans casser les garanties runtime.

## 8. Identité, révisions, traductions et aliases

L'identité métier reste :

```text
source/business ID
-> mapping local d'environnement
-> target entity ID / UUID
```

Règles conservées :

- ne jamais recycler un `source_id`, `legacy_uuid` ou mapping historique ;
- une release ne recrée jamais le node ;
- traductions FR/EN et aliases doivent rester stables pendant une transition ;
- les contenus RELEASED sont éditables via Drupal et ne sont plus réappliqués
  depuis un payload Git ;
- un ancien commit ne doit pas reprendre automatiquement possession d'un node.

## 9. Promotion et revue

### Governed Content

```text
changement payload
-> PR
-> validation schéma/politique
-> matérialisation staging/preprod si nécessaire
-> validation fonctionnelle + rendu
-> revue
-> merge du même commit
-> promotion production
-> validation post-déploiement
```

### Contenu ordinaire RELEASED

Le contenu suit le workflow Drupal normal : permissions, révisions, traduction
et validation humaine. Il n'est pas réexporté automatiquement vers
`content_sync/`.

## 10. Admission policy après migration

État machine :

```text
GOVERNED_CONTENT_IDS = 3 IDs légaux
LEGACY_RELEASE_PENDING_IDS = []
```

Règle :

```text
nouvel ID ordinaire marketing/editorial
-> REFUSE dans Content Sync

nouvel ID Governed Content
-> ticket dédié
-> justification de classe gouvernée
-> identité + destination + review + rollback documentés
-> mise à jour explicite de la policy
```

`GovernedContentCatalogPolicyTest` vérifie que le catalogue et la policy restent
alignés.

## 11. Rollback et readmission

La primitive `emerging:content-sync:readmit` reste une capacité explicite de
rollback pour un mapping `released`. Elle ne doit jamais devenir une reprise de
contrôle implicite.

Ordre de rollback :

1. restaurer explicitement l'ID et son payload dans un checkout gouverné ;
2. `readmit --dry-run` ;
3. `readmit --apply` ;
4. vérifier la même identité node/UUID ;
5. resynchroniser explicitement ;
6. vérifier FR/EN, aliases, publication et rendu.

`--prune=unpublish` n'est pas un mécanisme de rollback de release.

## 12. Prochaine convergence — #442

La migration étant terminée, le chantier suivant n'est **pas** un nouveau lot de
contenu. #442 doit converger la façade `emerging_digital_content` vers son rôle
réel de Governed Content.

Ordre recommandé :

1. inventorier les APIs, commandes et services encore nommés comme bootstrap
   générique ;
2. distinguer ce qui reste nécessaire aux trois contenus gouvernés de ce qui
   n'était utile qu'à la migration legacy ;
3. définir les alias/dépréciations nécessaires avant tout renommage ;
4. préserver idempotence, dry-run, mapping, rollback, prune guards et production ;
5. réduire la dette custom seulement après preuve de compatibilité ;
6. mettre à jour `docs/governed-content-release.md`, commandes et exemples une
   fois la décision de façade matérialisée.

Invariant de #442 :

```text
migration terminée
!= autorisation de casser les primitives éprouvées
```

La convergence doit simplifier la surface sans réduire les garanties.
