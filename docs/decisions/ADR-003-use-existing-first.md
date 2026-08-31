# ADR-003 — USE EXISTING FIRST et budget de simplicité

Statut : **ACCEPTED**  
Date : **2026-09-01**  
Décision : **#920**  
Roadmap : **#870**

## Contexte

Agency avait déjà une doctrine `CONTRIBUTED MODULES FIRST` dans `AGENTS.md` et une préférence explicite `USE DRUPAL > EXTEND DRUPAL > BUILD IN AGENCY`.

La revue du programme PROD -> PREPROD (#816/#914) et de la future promotion éditoriale (#872) a montré que cette intention n'était pas assez opérationnelle : la recherche Drupal contrib ne suffit pas. Avant de concevoir une nouvelle capacité custom, il faut également auditer Drupal core, les APIs Drupal, Drush, DDEV, les outils système standards et les primitives Agency déjà présentes.

La sécurité, la traçabilité et le fail-closed restent des exigences. Cette ADR ajoute une exigence de même niveau : **la simplicité et la réutilisation de primitives existantes sont des critères d'architecture de premier ordre**.

## Décision

Pour toute nouvelle capacité Agency, extension substantielle, nouvelle abstraction, nouvel orchestrateur ou nouveau mécanisme d'exploitation, appliquer obligatoirement cet ordre avant de concevoir du custom :

```text
1. Drupal Core
2. Drush / APIs Drupal officielles
3. DDEV / primitives officielles de l'environnement
4. Module contrib stable, maintenu et couvert par la Drupal Security Team lorsque pertinent
5. Outil système standard adapté au problème
   (Git, Composer, MariaDB, rsync/SSH, systemd, etc.)
6. EXTEND EXISTING : couche Agency minimale autour de l'existant
7. BUILD IN AGENCY : uniquement si un gap réel reste démontré
```

`USE EXISTING FIRST` ne signifie pas ajouter une dépendance à chaque besoin. Une primitive système simple et éprouvée peut être préférable à un module contrib supplémentaire.

## Gate EXISTING_CAPABILITY_AUDIT

Avant toute implémentation custom substantielle, le ticket ou la PR doit pouvoir répondre à :

```text
EXISTING_CAPABILITY_AUDIT

Drupal core évalué : ...
Drush / APIs Drupal évalués : ...
DDEV évalué : ...
Contrib stable/security-covered évalué : ...
Outils système standards évalués : ...
Primitives Agency existantes évaluées : ...

DECISION =
USE EXISTING
| EXTEND EXISTING
| BUILD CUSTOM

CUSTOM_GAP =
raison précise pour laquelle l'existant ne couvre pas le besoin
```

Une absence de familiarité avec un outil existant, l'absence de recherche, une préférence personnelle, ou le fait qu'une implémentation custom soit déjà commencée ne constituent jamais un `CUSTOM_GAP`.

Si l'audit n'est pas fait, la tâche n'est pas prête pour une implémentation custom substantielle.

## Budget de simplicité

Invariant :

> Une nouvelle couche n'est admissible que si elle apporte une garantie ou une capacité nécessaire qu'une primitive existante ne couvre pas, et si son bénéfice justifie sa complexité opérationnelle durable.

Préférer :

- configuration à framework ;
- composition à réimplémentation ;
- délégation à duplication ;
- APIs Drupal à SQL custom ;
- Drush/DDEV à orchestrateur maison ;
- primitives système éprouvées à wrappers génériques ;
- un downtime PREPROD borné et acceptable à une transaction distribuée complexe lorsque le besoin métier ne justifie pas cette complexité ;
- un fail-closed simple à une récupération sophistiquée non nécessaire ;
- suppression de code non mergé à préservation par coût irrécupérable.

## Application aux flux Agency

### A. Code / configuration

Cible :

```text
Git / Composer
-> CI
-> exact SHA
-> PREPROD
-> validation
-> même SHA
-> PROD
-> updb / cim / Config Split
```

Ne pas créer une promotion de configuration PREPROD -> PROD si Git + Config API couvrent déjà le besoin.

### B. PROD -> PREPROD

Avant tout custom additionnel, réévaluer les primitives standards :

- dump/snapshot PROD read-only via Drush/MariaDB ;
- staging DB isolée ;
- Drush `sql:sanitize` comme base, étendue uniquement pour les règles Agency ;
- assertions Agency pour Webform, sessions, queues, logs, credentials et side effects ;
- backup/restauration PREPROD via primitives DB standards ;
- maintenance PREPROD bornée si cela permet de supprimer une activation transactionnelle disproportionnée ;
- Stage File Proxy ou équivalent pour les fichiers publics avant de développer une synchronisation custom.

Les règles de confidentialité restent non négociables : raw PROD interdit sur GitHub-hosted/artifacts/logs, PREPROD ne doit pas pointer sur des données non sanitisées, secrets/settings restent environment-owned.

### C. Promotion éditoriale

Commencer par les primitives déjà disponibles :

- route éditoriale #576 ;
- Drupal Entity API ;
- révisions ;
- Content Moderation lorsque utile ;
- JSON:API lorsque cela réduit réellement le custom ;
- Drupal AI / AI Automators ;
- évaluation de primitives structurées existantes avant toute abstraction maison pour Paragraphs/Canvas.

Le MVP doit rester :

```text
payload canonique + hash
-> PREPROD draft/preview
-> approbation humaine
-> fresh PROD revision/conflict check
-> backup
-> apply ciblé par Entity API
```

Pas de framework générique tant qu'un besoin réel ne l'exige pas.

### D. Development data / DDEV

Commencer par les providers DDEV et les primitives existantes :

```text
seed sanitizé
-> ddev pull agency
```

Pas de push. Utiliser `ddev snapshot` lorsque pertinent pour le rollback local. Le custom doit se limiter à la policy de sanitisation additionnelle, l'identité/hash du seed et la distribution sécurisée réellement nécessaires.

## Règle de revue

Toute PR introduisant une surface custom substantielle doit permettre au reviewer de retrouver l'`EXISTING_CAPABILITY_AUDIT` correspondant.

Une PR peut être refusée même si ses tests sont verts lorsque l'architecture duplique inutilement une capacité existante ou crée une complexité disproportionnée.

`CI GREEN != ARCHITECTURE APPROVED`.

## Documentation

La documentation opérationnelle décrit le système courant et les recettes utiles, pas la chronologie de construction.

Un opérateur compétent doit pouvoir comprendre une capacité sans reconstituer une chaîne d'issues historiques.

## Conséquences

### Positives

- moins de code custom à maintenir ;
- adoption plus rapide de primitives Drupal/DDEV éprouvées ;
- réduction des failure modes ;
- meilleure transférabilité à d'autres développeurs ;
- capacité à retirer du custom quand upstream couvre le besoin ;
- meilleure vitesse de livraison des fonctionnalités métier.

### Coûts acceptés

- audit initial obligatoire avant custom ;
- certaines solutions pourront accepter un compromis opérationnel simple, par exemple quelques minutes de maintenance PREPROD ;
- une implémentation déjà écrite peut être supprimée si une primitive existante la remplace proprement.

## Invariants

```text
USE EXISTING FIRST
!= baisse de sécurité

SIMPLER
!= moins de fail-closed

CONTRIB FIRST
!= ajouter une dépendance inutile

CUSTOM ALREADY WRITTEN
!= obligation de le conserver

CI GREEN
!= architecture approuvée
```

Cette ADR est autoritative tant qu'une ADR ultérieure ne la supersede pas explicitement.