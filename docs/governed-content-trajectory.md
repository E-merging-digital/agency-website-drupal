# Governed Content — trajectoire de convergence de Content Sync

Statut : **transition active — pilote #440 terminé**  
Date : **2026-08-16**  
Ticket d'origine : **#385**  
Mise à jour d'inventaire : **#445**  
Parent : **#60**

## 1. Décision

Le Content Sync Agency a correctement rempli son rôle de bootstrap et de
promotion déterministe du contenu initial. Il ne doit toutefois plus devenir la
source de vérité Git de tout le contenu éditorial courant.

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
-> pas d'écrasement par Git après libération contrôlée
```

La migration est progressive. #439 a introduit le lifecycle `released` et les
commandes de release/readmission. #440 a ensuite libéré un premier lot de trois
cas clients sans perte d'identité, de traduction, d'alias ou de persistance
éditoriale.

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

La documentation explique la trajectoire, les invariants et les étapes. Elle ne
doit jamais devenir une seconde allowlist copiée à la main.

## 3. Inventaire courant après #440

Inventaire recalculé depuis le catalogue versionné sur `main` après le merge de
#440 :

```text
CATALOG_VERSION=1
CATALOG_COUNT=33
BUNDLE_COUNTS={"ai_feature":10,"page":9,"service":14}
MISSING_REFERENCED_FILES=[]
UNREFERENCED_NODE_FILES=[]
DUPLICATE_IDS=[]
ALL_HAVE_FR_EN=true
LEGACY_UUID_COUNT=8
```

`LEGACY_UUID_COUNT` compte uniquement les entrées du catalogue portant encore
explicitement la clé top-level `legacy_uuid`. Il ne faut pas l'assimiler au
nombre total d'entrées du catalogue.

La policy runtime contient :

```text
3 IDs = GOVERNED
30 IDs = LEGACY_RELEASE_PENDING
```

Donc :

```text
33 entrées cataloguées
= 3 Governed Content
+ 30 contenus ordinaires encore en transition
```

### 3.1 Governed Content conservé sous Git

Les trois contenus actuellement admis durablement sont :

| Source ID | Bundle | Classification | Motif |
|---|---|---|---|
| `mentions-legales` | `page` | `GOVERNED` | contenu légal |
| `politique-confidentialite` | `page` | `GOVERNED` | politique de confidentialité |
| `politique-cookies` | `page` | `GOVERNED` | politique cookies |

Ces contenus restent canoniques dans le repository jusqu'à décision contraire
explicite.

### 3.2 Contenu ordinaire encore pending

La liste exacte des 30 contenus encore `LEGACY_RELEASE_PENDING` appartient à
`GovernedContentPolicy::LEGACY_RELEASE_PENDING_IDS` et ne doit pas être recopiée
ici.

Répartition actuelle, dérivée du catalogue :

```text
service: 14
ai_feature: 10
page ordinaire pending: 6
```

Les pages ordinaires à fort impact (`homepage`, `services`, `contact`) doivent
rester parmi les derniers lots et conserver des browser gates dédiés.

### 3.3 Lot pilote déjà RELEASED

#440 a libéré les trois vrais cas clients historiques :

```text
cas-client-refonte-drupal-institutionnelle
cas-client-migration-drupal-11
cas-client-integration-ia-editoriale
```

Leur bundle historique est `case_client`.

Ils sont désormais :

```text
RELEASED
absents du catalogue
absents de LEGACY_RELEASE_PENDING_IDS
editor-owned dans Drupal
```

Les identifiants suivants n'ont jamais constitué les IDs autoritatifs du
catalogue et ne doivent plus être utilisés comme inventaire :

```text
cas-clients/refonte-drupal-b2b
cas-clients/plateforme-contenus-api-first
cas-clients/industrie-site-haute-disponibilite
```

## 4. Pourquoi la migration ne doit pas être brutale

Le déploiement production appelle :

```text
emerging:content-sync:validate
emerging:content-sync --all --dry-run
emerging:content-sync --all
emerging:content-sync:report --severity=error
```

Le chemin normal n'active pas `--prune`. Le prune `unpublish` possède un
garde-fou production explicite.

Invariant fondamental :

```text
retirer un contenu de la gouvernance Git
!= supprimer ou dépublier son entité Drupal
```

Le lifecycle `released` implémenté dans #439 garantit précisément cette
séparation : un mapping released est ignoré par le prune et ne peut pas être
repris implicitement par un Content Sync normal.

## 5. Taxonomie de gouvernance cible

Un contenu n'entre dans le futur catalogue Governed Content que s'il appartient
explicitement à une classe approuvée.

### 5.1 Légal et réglementaire

Exemples : mentions légales, confidentialité, cookies et notices réglementaires
réellement requises.

C'est le seul groupe actuellement matérialisé dans le catalogue.

### 5.2 Prompts système approuvés

Un prompt système peut être gouverné seulement si :

- il est réellement un artefact métier versionnable ;
- sa revue humaine avant promotion est requise ;
- il ne contient aucun secret, token ou credential ;
- il possède une identité stable et une destination runtime définie ;
- le mécanisme de matérialisation est explicite.

Cette classe reste une capacité future, pas un prétexte pour créer de nouveaux
payloads.

### 5.3 Contrats, métadonnées contrôlées et ressources critiques

Éligibles uniquement lorsque l'artefact doit être identique entre environnements
et que l'historique Git/review apporte une garantie métier concrète.

Une ressource n'est pas « critique » simplement parce qu'elle est pratique à
versionner. Toute nouvelle admission exige un ticket et une justification.

## 6. États de cycle de vie

La trajectoire utilise quatre états :

```text
GOVERNED
LEGACY_RELEASE_PENDING
RELEASED
RETIRED
```

### `GOVERNED`

Le payload versionné est canonique. Drupal est une matérialisation runtime. Les
changements suivent PR -> revue -> promotion.

### `LEGACY_RELEASE_PENDING`

Contenu historiquement versionné par le bootstrap Content Sync mais destiné à
revenir au workflow éditorial Drupal.

### `RELEASED`

Le contenu n'est plus piloté par Git. L'entité Drupal existante, ses
traductions, aliases et révisions restent en place et deviennent la source
éditoriale runtime.

### `RETIRED`

Retrait réellement voulu d'un contenu gouverné. La suppression ou dépublication
reste une opération distincte, explicite et auditable.

## 7. Identité, UUID et mapping

L'identité métier existante reste la clé de convergence :

```text
source/business ID
-> mapping local d'environnement
-> target entity ID / UUID
```

Règles :

- le `source_id` reste stable tant que l'élément est gouverné ou en transition ;
- le mapping est propre à chaque environnement ;
- `target_id` et `target_uuid` identifient l'entité runtime locale ;
- `legacy_uuid` reste une graine/héritage de migration et ne doit pas être
  recyclé ;
- le passage `released` conserve l'identité de l'entité existante ;
- une release ne recrée jamais le node.

## 8. Révisions, traductions et aliases

Avant libération d'un ID ordinaire :

- vérifier les traductions FR/EN runtime ;
- vérifier les aliases FR/EN ;
- conserver le node existant et son UUID ;
- conserver une révision récupérable ;
- enregistrer l'état final du mapping et son checksum.

Après libération :

- les traductions deviennent éditables via Drupal ;
- les aliases ne sont plus réappliqués depuis le payload Git ;
- un déploiement Content Sync normal ne doit plus toucher le contenu ;
- un ancien commit Git ne doit pas reprendre automatiquement possession du node.

## 9. Promotion et revue

### 9.1 Governed Content

```text
changement payload
-> PR
-> validation schéma/politique
-> matérialisation staging/preprod
-> validation fonctionnelle + rendu si applicable
-> revue humaine
-> merge du même commit
-> promotion production
-> validation post-déploiement
```

### 9.2 Contenu ordinaire libéré

Le contenu suit le workflow Drupal normal : permissions, révisions, traduction
et validation humaine. Il n'est pas réexporté automatiquement vers
`content_sync/`.

## 10. Positionnement des primitives

### Content Sync custom actuel — `KEEP -> CONVERGE`

À conserver pendant la transition pour : parsing du catalogue, validation,
mapping, idempotence/checksums, traductions/aliases, matérialisation
déterministe, rapports et dry-run.

Le but est de réduire son domaine à ce qui mérite réellement la gouvernance Git,
pas de construire une nouvelle plateforme custom.

### Core Workspaces

Responsabilité différente : staging de contenu/révisions dans Drupal. Il ne
remplace pas à lui seul la promotion déterministe inter-environnements de
Governed Content.

### Default Content Deploy — `EVALUATE LATER`

À réévaluer seulement si une solution contrib remplace réellement une partie du
materializer/mapping custom avec des garanties au moins équivalentes.

## 11. Admission policy

Pendant la migration, la policy runtime contient deux ensembles explicites :

```text
GOVERNED_CONTENT_IDS = 3 IDs légaux
LEGACY_RELEASE_PENDING_IDS = 30 IDs grandfathered
```

La liste pending ne peut que diminuer.

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

## 12. Libération contrôlée

La primitive a été implémentée par #439. La procédure opérationnelle détaillée
est documentée dans `docs/governed-content-release.md`.

Séquence minimale :

1. sélectionner un ID encore `LEGACY_RELEASE_PENDING` ;
2. valider le catalogue et lancer le dry-run global ;
3. vérifier node, UUID, traductions, aliases et révision ;
4. lancer `emerging:content-sync:release <id> --dry-run` ;
5. lancer `emerging:content-sync:release <id> --apply` ;
6. vérifier le mapping `released` et l'entité inchangée ;
7. retirer l'ID/payload du catalogue et de la policy dans le même changement
   gouverné ;
8. déployer/rejouer Content Sync ;
9. prouver une modification éditoriale persistante ;
10. valider rendu, traductions, aliases et rollback.

Invariant :

```text
catalogue removal
MUST NOT happen
before released mapping semantics are applied and proven
```

## 13. Prune

Le prune reste une primitive de retrait volontaire, pas un outil de migration
vers l'édition Drupal.

- `active` absent du catalogue peut être candidat au prune selon la policy ;
- `released` absent du catalogue est ignoré ;
- `pruned` reste terminal pour l'action historique correspondante ;
- la réadmission d'un contenu released exige une décision explicite.

Le garde-fou production de `--prune=unpublish` reste obligatoire.

## 14. Rollback

### Governed Content

```text
commit/payload précédent approuvé
-> promotion
-> sync ciblé
-> vérification runtime
```

### Libération de mapping

Le rollback doit permettre de réintroduire explicitement l'ID/payload, exécuter
la readmission, préserver l'identité du node et vérifier traductions/aliases.

Aucun rollback ne doit écraser silencieusement des modifications éditoriales
réalisées après une libération déjà acceptée.

## 15. Données sensibles

Governed Content n'est pas un coffre-fort.

Interdit dans les payloads : secrets provider, tokens, mots de passe, clés
privées, credentials, variables sensibles ou dumps arbitraires contenant des
secrets.

## 16. Backlog de migration

### GC-1 — mapping `released` et commandes de transition — **TERMINÉ (#439)**

La release/readmission explicite, le fail-closed et l'exclusion du prune sont
implémentés et testés.

### GC-2 — pilote de libération — **TERMINÉ (#440)**

Les trois cas clients réels ont été libérés, vérifiés sur l'environnement de
preuve, validés au navigateur et leur rollback a été prouvé avant merge.

### GC-3 — libération par lots — **PROCHAINE ÉTAPE (#441)**

Ordre recommandé :

```text
ai_feature
-> services détaillés par lots bornés
-> pages/services ordinaires à impact moyen
-> homepage / services / contact en dernier
```

Chaque lot doit réduire `LEGACY_RELEASE_PENDING_IDS`. Aucun lot ne peut ajouter
un nouvel ID grandfathered.

### GC-4 — convergence nominale finale — **#442**

Lorsque le catalogue ne contient plus que le Governed Content :

- converger la terminologie et la façade ;
- conserver si nécessaire un alias de compatibilité temporaire ;
- simplifier l'admission policy ;
- réévaluer le volume de code custom restant.

## 17. Preuves et historique

La preuve d'inventaire #385 correspond à l'état **avant** les corrections
matérialisées par #439/#440. Elle reste historique et ne doit pas être utilisée
comme inventaire courant.

La preuve autoritative actuelle est le couple :

```text
catalog.yml
GovernedContentPolicy.php
```

Le merge #440 a également ajouté une barrière projet qui vérifie :

```text
GOVERNED count = 3
LEGACY_RELEASE_PENDING count = 30
catalog count = 33
3 vrais case_client pilotes absents du catalogue
```

## 18. Conclusion

La cible n'est ni « tout Git » ni « tout Drupal ».

```text
Git
-> petit ensemble explicitement gouverné
-> review/promotion déterministe

Drupal
-> contenu éditorial ordinaire
-> permissions/révisions/traductions/workflow humain
```

La trajectoire réelle est désormais :

```text
bootstrap historique : 36 entrées
après pilote #440 : 33 entrées au catalogue
                    3 GOV + 30 pending
cible :             noyau Governed Content justifié
```

#439 a fourni la primitive de release sûre, #440 a prouvé le premier lot réel,
et #441 poursuit désormais la diminution progressive des 30 contenus ordinaires
restants.