# Governed Content — trajectoire de convergence de Content Sync

Statut : **architecture de transition approuvable, runtime inchangé**  
Date : **2026-08-16**  
Ticket : **#385**  
Parent : **#60**

## 1. Décision

Le Content Sync Agency a correctement rempli son rôle de bootstrap et de promotion déterministe du contenu initial. Il ne doit toutefois plus devenir la source de vérité Git de tout le contenu éditorial courant.

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

La migration est progressive. **Aucune des 33 entrées ordinaires actuelles n'est retirée du catalogue dans #385.** Le déploiement production continue donc à exécuter le Content Sync actuel tant que le mécanisme de libération des mappings n'est pas implémenté et prouvé.

## 2. Pourquoi la migration ne doit pas être brutale

Le déploiement production appelle actuellement :

```text
emerging:content-sync:validate
emerging:content-sync --all --dry-run
emerging:content-sync --all
emerging:content-sync:report --severity=error
```

Le chemin normal n'active pas `--prune`. Le prune `unpublish` possède par ailleurs un garde-fou production explicite.

C'est une bonne base de sécurité, mais elle ne suffit pas pour retirer immédiatement des éléments ordinaires du catalogue : la table de mapping continue à mémoriser les contenus historiquement gérés et le prune considère aujourd'hui comme candidats les mappings absents du catalogue tant qu'ils ne sont pas déjà `pruned`.

Le besoin de transition est donc :

```text
retirer un contenu de la gouvernance Git
!= supprimer ou dépublier son entité Drupal
```

Avant toute réduction du catalogue, le runtime doit savoir représenter explicitement un mapping **released** que le prune ignore.

## 3. Inventaire autoritatif au 16 août 2026

Une preuve machine a inventorié le catalogue exact :

```text
CATALOG_VERSION=1
CATALOG_COUNT=36
BUNDLE_COUNTS={"ai_feature":10,"case_study":3,"page":9,"service":14}
MISSING_REFERENCED_FILES=[]
UNREFERENCED_NODE_FILES=[]
DUPLICATE_IDS=[]
ALL_HAVE_FR_EN=true
LEGACY_UUID_COUNT=36
```

Le catalogue actuel est donc techniquement cohérent : tous les fichiers sont référencés, tous les contenus possèdent FR/EN, aucun ID source n'est dupliqué et les 36 entrées portent encore un `legacy_uuid` de migration.

### 3.1 Contenu conservé sous gouvernance Git

Trois entrées correspondent directement au besoin de Governed Content actuel :

| Source ID | Bundle | Classification | Motif |
|---|---|---|---|
| `mentions-legales` | `page` | `GOVERNED` | contenu légal |
| `politique-confidentialite` | `page` | `GOVERNED` | politique de confidentialité |
| `politique-cookies` | `page` | `GOVERNED` | politique cookies |

Ces contenus restent canoniques dans le repository jusqu'à décision contraire explicite.

### 3.2 Contenu ordinaire grandfathered pendant la transition

Les 33 entrées suivantes ne sont **pas** des candidats naturels au Governed Content cible. Elles restent néanmoins temporairement dans le catalogue afin d'éviter toute perte, dépublication ou reprise de contrôle involontaire pendant la migration.

#### Services — 14

```text
services/drupal
services/web
services/architecture
services/securite
services/communication
services/hebergement
services/sauvegardes
services/migration
services/formation
services/infogerance
services/support
services/seo
services/contenus
services/ia-drupal
```

#### AI features — 10

```text
ai-features/brief-wizard
ai-features/rewrite-blocks
ai-features/seo-assistant
ai-features/content-assistant
ai-features/semantic-search
ai-features/document-search
ai-features/chatbot
ai-features/agent-workflows
ai-features/privacy-guardrails
ai-features/observability
```

#### Cas clients — 3

```text
cas-clients/refonte-drupal-b2b
cas-clients/plateforme-contenus-api-first
cas-clients/industrie-site-haute-disponibilite
```

#### Pages ordinaires — 6

```text
services
ia-drupal
cas-clients
equipe
contact
homepage
```

État de transition :

```text
33 IDs = LEGACY_RELEASE_PENDING
```

Ils ne doivent plus servir de précédent pour admettre de nouveaux contenus marketing dans Content Sync.

## 4. Taxonomie de gouvernance cible

Un contenu n'entre dans le futur catalogue Governed Content que s'il appartient explicitement à une classe approuvée.

### 4.1 Légal et réglementaire

Exemples :

- mentions légales ;
- confidentialité ;
- cookies ;
- notices réglementaires réellement requises.

C'est le seul groupe actuellement matérialisé dans le catalogue.

### 4.2 Prompts système approuvés

Un prompt système peut être gouverné seulement si :

- il est réellement un artefact métier versionnable ;
- sa revue humaine avant promotion est requise ;
- il ne contient aucun secret, token ou credential ;
- il possède une identité stable et une destination runtime définie ;
- le mécanisme de matérialisation est explicite.

Les prompts de configuration existants ne doivent pas être déplacés artificiellement dans Content Sync uniquement pour remplir cette catégorie. Cette classe reste une **capacité future**, pas un prétexte pour créer de nouveaux payloads.

### 4.3 Contrats et métadonnées contrôlées

Éligibles uniquement lorsque l'artefact doit être identique entre environnements et que l'historique Git/review apporte une garantie métier concrète.

Le contenu business configurable ne doit jamais être placé dans `config/sync` pour cette raison : configuration Drupal et Governed Content restent deux responsabilités distinctes.

### 4.4 Ressources critiques

Exemples possibles : ressources de référence, manifests ou textes approuvés dont l'intégrité inter-environnements est une exigence produit.

Une ressource n'est pas « critique » parce qu'elle est pratique à versionner. L'admission doit être justifiée dans le ticket qui l'introduit.

## 5. États de cycle de vie

La trajectoire utilise quatre états conceptuels :

```text
GOVERNED
LEGACY_RELEASE_PENDING
RELEASED
RETIRED
```

### `GOVERNED`

Le payload versionné est canonique. Drupal est une matérialisation runtime. Les changements suivent PR -> revue -> promotion.

### `LEGACY_RELEASE_PENDING`

Contenu historiquement versionné par le bootstrap Content Sync, mais destiné à revenir au workflow éditorial Drupal. Tant que la libération n'est pas prouvée, le comportement historique reste intact.

### `RELEASED`

Le contenu n'est plus piloté par Git. L'entité Drupal existante, ses traductions, aliases et révisions restent en place et deviennent la source éditoriale runtime. Le déploiement ne doit plus l'écraser ni le considérer comme un orphan à pruner.

### `RETIRED`

Utilisé uniquement lorsqu'un contenu gouverné doit réellement être retiré. La suppression ou dépublication reste une opération distincte, explicite et auditable ; elle ne découle jamais implicitement d'un simple changement de classification.

## 6. Source de vérité par classe

| Classe | Source canonique | Runtime | Écriture normale |
|---|---|---|---|
| `GOVERNED` | payload versionné Git | entité/artefact Drupal matérialisé | PR + promotion |
| `LEGACY_RELEASE_PENDING` | Git temporairement | entité Drupal | pas de nouveaux edits durables avant libération |
| `RELEASED` | entité Drupal de l'environnement éditorial | entité Drupal | UI/workflow éditeur Drupal |
| `RETIRED` | décision/revision Git + audit | absent/non publié selon décision | opération dédiée |

Le statut `RELEASED` ne signifie pas que chaque environnement Drupal devient une source indépendante sans processus. La production reste alimentée par le processus éditorial/déploiement retenu pour le site ; ce que l'on retire est uniquement **l'écrasement automatique par le catalogue Git de bootstrap**.

## 7. Identité, UUID et mapping inter-environnements

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
- `legacy_uuid` reste une graine/héritage de migration et ne doit pas être recyclé ;
- la destination n'est jamais directement couplée à un UUID provenant d'un autre environnement comme seule identité métier ;
- le passage `released` conserve l'identité de l'entité existante ; il ne recrée pas le node.

## 8. Révisions, traductions et aliases

Les pages sont revisionables (`new_revision: true` pour le type `page`). La transition doit préserver cette propriété.

Avant libération d'un ID ordinaire :

- vérifier que les traductions FR/EN runtime correspondent au contenu attendu ;
- vérifier les aliases FR/EN ;
- conserver le node existant et son UUID ;
- créer ou conserver une révision récupérable avant la bascule ;
- enregistrer le checksum/mapping final de la dernière version gouvernée.

Après libération :

- les traductions deviennent éditables via Drupal ;
- les aliases ne sont plus réappliqués depuis le payload Git ;
- un déploiement Content Sync normal ne doit plus toucher le contenu ;
- un ancien commit Git ne doit pas reprendre automatiquement possession du node.

## 9. Promotion et revue

### 9.1 Governed Content

Le chemin cible est :

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

Le même contenu versionné et le même commit doivent être promus ; la production ne doit pas recevoir une copie manuelle réécrite après validation.

### 9.2 Contenu ordinaire libéré

Le contenu éditorial ordinaire suit le workflow Drupal normal : permissions, révisions, traduction et validation humaine. Il n'est pas réexporté automatiquement vers `content_sync/`.

Si un futur besoin exige une promotion éditoriale structurée de staging vers production, il devra être traité par un mécanisme dédié et évalué séparément ; il ne justifie pas de conserver tout le marketing sous Git par défaut.

## 10. Positionnement des primitives Drupal/contrib

### Content Sync custom actuel — `KEEP -> CONVERGE`

À conserver pendant la transition pour :

- parsing du catalogue ;
- validation ;
- mapping ;
- idempotence/checksums ;
- traductions/aliases ;
- matérialisation déterministe ;
- rapports et dry-run.

Il ne faut pas le réécrire en une nouvelle « plateforme de gouvernance » custom. Le but est de **réduire son domaine** à ce qui mérite réellement la gouvernance Git.

### Core Workspaces — responsabilité différente

Workspaces est pertinent pour des scénarios de staging de contenu dans un même site Drupal. Il ne remplace pas, à lui seul, le besoin Agency de promotion déterministe d'un artefact gouverné entre environnements.

Il pourra être réévalué si le workflow éditorial du site adopte un besoin de staging de révisions, mais ne doit pas être détourné en mécanisme cross-environment ad hoc dans #385.

### Default Content Deploy — `EVALUATE LATER`

Une solution contrib d'export/import peut être réévaluée plus tard si elle remplace réellement une partie du materializer/mapping custom avec :

- identité stable ;
- traductions ;
- aliases ;
- dry-run/review ;
- promotion reproductible ;
- sécurité et rollback au moins équivalents.

Aucune nouvelle dépendance n'est introduite dans #385.

### `default_content` historique — migration uniquement

Le legacy/default content reste une source de migration historique, pas un second runtime de gouvernance en parallèle.

## 11. Admission policy — empêcher le retour du bootstrap massif

Pendant la migration, un test CI contient deux allowlists explicites :

```text
GOVERNED_CONTENT_IDS = 3 IDs légaux
LEGACY_ORDINARY_CONTENT_IDS = 33 IDs grandfathered
```

Le test échoue si :

- une entrée inconnue est ajoutée ;
- une entrée est retirée sans mettre à jour la politique ;
- un Article éditorial est introduit dans le catalogue ;
- un des trois contenus légaux perd sa classification structurelle attendue.

Cette duplication est **volontaire et temporaire** : elle transforme l'inventaire actuel en contrat de migration auditable.

Règle d'admission :

```text
nouvel ID ordinaire marketing/editorial
-> REFUSE dans Content Sync

nouvel ID Governed Content
-> ticket dédié
-> justification de classe gouvernée
-> identité + destination + review + rollback documentés
-> mise à jour explicite du test/policy
```

Aucun nouvel ID ne doit être ajouté à `LEGACY_ORDINARY_CONTENT_IDS`. Cette liste ne peut que diminuer au fil des libérations prouvées.

## 12. Libération contrôlée d'un contenu ordinaire

Le mécanisme de libération n'est **pas** implémenté dans #385. Sa spécification minimale est :

1. sélectionner l'ID `LEGACY_RELEASE_PENDING` ;
2. valider le catalogue et exécuter un dry-run actuel ;
3. vérifier node, UUID, traductions, aliases et révision de secours ;
4. sauvegarder/rapporter l'état final et le mapping ;
5. passer explicitement le mapping au statut `released` ;
6. prouver que le prune ignore `released` ;
7. retirer l'ID du catalogue et son payload dans le même changement gouverné ;
8. déployer en staging/preprod ;
9. modifier le contenu via Drupal pour produire une preuve éditoriale ;
10. relancer le déploiement/Content Sync et prouver que cette modification n'est pas écrasée ;
11. valider le rendu/traductions/aliases ;
12. promouvoir le même changement en production.

Invariant :

```text
catalogue removal
MUST NOT happen
before released mapping semantics are implemented and proven
```

## 13. Prune

Le prune reste une primitive de retrait volontaire, pas un outil de migration vers l'édition Drupal.

Règles futures :

- `managed` absent du catalogue peut être candidat au prune selon la politique existante ;
- `released` absent du catalogue doit être **ignoré** par le prune ;
- `pruned` reste terminal pour l'action historique correspondante ;
- aucun `released` ne doit être repassé en `managed` implicitement par `--all` ;
- la réadmission d'un contenu released exige une décision explicite.

Le garde-fou production de `--prune=unpublish` reste obligatoire.

## 14. Rollback

### Governed Content

Rollback normal :

```text
commit/payload précédent approuvé
-> promotion
-> sync ciblé
-> vérification runtime
```

Les révisions Drupal et backups restent une protection supplémentaire, pas la source de vérité principale.

### Libération de mapping

Avant promotion production, le rollback de transition doit permettre :

- réadmettre explicitement l'ID ;
- restaurer son état de mapping `managed` ;
- rematérialiser le payload connu ;
- conserver l'UUID du node lorsque possible ;
- vérifier traductions et aliases.

Aucun rollback ne doit supprimer silencieusement des modifications éditoriales réalisées après une libération déjà acceptée en production. Si ce cas survient, une décision humaine sur la version de contenu à conserver est requise.

### Contenu ordinaire après libération

Rollback par :

- révision Drupal ;
- backup DB ;
- mécanisme éditorial/déploiement du contenu ordinaire retenu.

Ne jamais restaurer automatiquement l'ancien YAML Content Sync comme raccourci.

## 15. Données sensibles

Governed Content n'est pas un coffre-fort.

Interdit dans les payloads :

- secrets provider ;
- tokens ;
- mots de passe ;
- clés privées ;
- credentials ;
- variables d'environnement sensibles ;
- dumps arbitraires de configuration contenant des secrets.

Pour les prompts ou métadonnées contrôlées, les références à des secrets restent des identifiants de Key/configuration locale, jamais les valeurs elles-mêmes.

## 16. Backlog de migration borné

### GC-1 — mapping `released` et commande de libération

Ajouter une primitive de mapping explicite :

```text
emerging:content-sync:release <content-id> --dry-run
```

ou équivalent cohérent avec les patterns existants.

DoD minimal :

- uniquement ID grandfathered ;
- dry-run lisible ;
- apply explicite ;
- mapping devient `released` ;
- prune ignore `released` ;
- `--all` ne reprend pas possession après retrait du catalogue ;
- tests unit/kernel selon la couche concernée ;
- audit/log clair ;
- aucun delete/unpublish implicite.

### GC-2 — pilote de libération staging/preprod

Choisir un petit groupe à faible risque, de préférence les **3 cas clients**, puis prouver :

- mapping released ;
- retrait catalogue sans retrait Drupal ;
- FR/EN + aliases + UUID conservés ;
- modification éditoriale Drupal persistante ;
- déploiement suivant sans overwrite ;
- browser validation ;
- rollback documenté et testé avant production.

### GC-3 — libération par lots des contenus ordinaires

Après le pilote :

```text
case_study
-> ai_feature
-> services/pages ordinaires par lots maîtrisés
```

Chaque lot doit réduire `LEGACY_ORDINARY_CONTENT_IDS`. Aucun lot ne doit ajouter de nouvel ID grandfathered.

Les pages à fort impact (`homepage`, `services`, `contact`) passent en dernier avec browser validation dédiée.

### GC-4 — convergence nominale finale

Lorsque le catalogue ne contient plus que le Governed Content :

- renommer/documenter la surface comme Governed Content ;
- garder éventuellement `emerging:content-sync` comme alias déprécié temporaire ;
- simplifier le test d'admission ;
- mettre à jour les scripts de déploiement/documentation ;
- supprimer la terminologie bootstrap ambiguë ;
- réévaluer le volume de code custom restant face aux primitives contrib stables du moment.

### GC-5 — export éditorial vers Git, seulement si besoin réel

Si un jour les contenus gouvernés doivent être édités depuis Drupal staging :

- export allowlisté de champs ;
- production d'un diff/PR ;
- aucune écriture directe Git depuis production ;
- aucune exportation de secret ;
- identité et schéma versionnés ;
- revue humaine avant promotion.

Ce besoin n'est pas requis pour la trajectoire actuelle.

## 17. Ce que #385 ne change pas

Dans cette tâche :

- aucun payload Content Sync n'est supprimé ;
- aucun mapping runtime n'est modifié ;
- aucun node n'est recréé, dépublié ou supprimé ;
- `scripts/deploy-production.sh` reste inchangé ;
- les commandes Drush restent inchangées ;
- aucune dépendance Drupal/contrib n'est ajoutée ;
- aucune configuration Drupal n'est modifiée ;
- aucune donnée de production n'est requise.

Le seul changement exécutable est un **test de politique CI** destiné à empêcher une dérive du catalogue pendant la transition.

## 18. Preuve d'inventaire #385

Workflow d'inspection jetable :

```text
run 31937823780
```

Artefact :

```text
issue-385-content-sync-inventory-31937823780
sha256:5bd01c33df172d5037fbe331ea1b068d830d61c233035479282d735da08d9c56
```

Le workflow temporaire n'appartient pas au candidat final ; il sert uniquement de preuve d'inventaire reproductible.

## 19. Conclusion

La cible n'est ni « tout Git » ni « tout Drupal ».

La séparation voulue est :

```text
Git
-> petit ensemble explicitement gouverné
-> review/promotion déterministe

Drupal
-> contenu éditorial ordinaire
-> permissions/révisions/traductions/workflow humain
```

Le Content Sync existant reste utile comme materializer fiable pendant la transition, mais son domaine doit progressivement se réduire de **36 entrées** à un noyau Governed Content justifié.

#385 crée la barrière qui manquait : un inventaire explicite, une admission policy, des règles d'identité/promotion/rollback et un chemin de libération qui évite toute perte ou overwrite avant de toucher au catalogue runtime.
